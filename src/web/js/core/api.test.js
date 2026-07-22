import { describe, it, expect, vi } from 'vitest';
import { BookedApi, ApiError, AbortedError } from './api.js';

/** Build a fake fetch that records calls and returns a JSON body. */
function fakeFetch({ status = 200, json = {}, text } = {}) {
  const calls = [];
  const impl = vi.fn(async (url, init) => {
    calls.push({ url, init });
    return {
      ok: status >= 200 && status < 300,
      status,
      text: async () => (text !== undefined ? text : JSON.stringify(json)),
    };
  });
  impl.calls = calls;
  return impl;
}

const csrf = { name: 'CRAFT_CSRF_TOKEN', value: 'abc123' };

describe('BookedApi — request shaping', () => {
  it('builds versioned GET URLs with the site handle', async () => {
    const f = fakeFetch({ json: { ok: true } });
    const api = new BookedApi({ csrf, site: 'de', fetch: f });
    await api.get('services');
    const { url, init } = f.calls[0];
    expect(url).toBe('/booked/api/v1/services?site=de');
    expect(init.method).toBe('GET');
  });

  it('merges query params alongside the site handle', async () => {
    const f = fakeFetch();
    const api = new BookedApi({ site: 'en', fetch: f });
    await api.get('availability/dates', { query: { serviceId: 12, month: '2026-08' } });
    expect(f.calls[0].url).toBe('/booked/api/v1/availability/dates?site=en&serviceId=12&month=2026-08');
  });

  it('omits the site param when none is configured', async () => {
    const f = fakeFetch();
    const api = new BookedApi({ fetch: f });
    await api.get('me');
    expect(f.calls[0].url).toBe('/booked/api/v1/me');
  });

  it('form-encodes POST bodies and injects the CSRF token', async () => {
    const f = fakeFetch();
    const api = new BookedApi({ csrf, fetch: f });
    await api.post('bookings', { body: { serviceId: 3, addToCart: true, notes: null } });
    const body = f.calls[0].init.body;
    expect(body).toBeInstanceOf(URLSearchParams);
    expect(body.get('CRAFT_CSRF_TOKEN')).toBe('abc123');
    expect(body.get('serviceId')).toBe('3');
    expect(body.get('addToCart')).toBe('1'); // boolean → '1'/'0'
    expect(body.has('notes')).toBe(false); // null dropped
  });

  it('encodes a nested object as key[subKey]=value for PHP array parsing', async () => {
    const f = fakeFetch();
    const api = new BookedApi({ fetch: f });
    await api.createBooking({ serviceId: 1, extras: { 5: 2, 7: 1 } });
    const body = f.calls[0].init.body;
    expect(body.get('extras[5]')).toBe('2');
    expect(body.get('extras[7]')).toBe('1');
    expect(body.get('serviceId')).toBe('1');
  });

  it('parses a JSON response body', async () => {
    const f = fakeFetch({ json: { services: [{ id: 1 }] } });
    const api = new BookedApi({ fetch: f });
    const data = await api.services();
    expect(data).toEqual({ services: [{ id: 1 }] });
  });

  it('respects a custom baseUrl', async () => {
    const f = fakeFetch();
    const api = new BookedApi({ baseUrl: '/actions/booked/api/v2/', fetch: f });
    await api.get('services');
    expect(f.calls[0].url).toBe('/actions/booked/api/v2/services');
  });
});

describe('BookedApi — errors', () => {
  it('throws ApiError with status and code on a non-2xx response', async () => {
    const f = fakeFetch({ status: 422, json: { error: 'bad input' } });
    const api = new BookedApi({ fetch: f });
    await expect(api.createBooking({})).rejects.toMatchObject({
      name: 'ApiError',
      status: 422,
      message: 'bad input',
      code: 'http_error',
    });
  });

  it('maps 410 to the expired code (lock gone)', async () => {
    const f = fakeFetch({ status: 410, json: { error: 'lock expired' } });
    const api = new BookedApi({ fetch: f });
    await expect(api.createBooking({})).rejects.toMatchObject({ code: 'expired', status: 410 });
  });

  it('maps 429 to rate_limited', async () => {
    const f = fakeFetch({ status: 429, json: {} });
    const api = new BookedApi({ fetch: f });
    await expect(api.slots({})).rejects.toMatchObject({ code: 'rate_limited' });
  });

  it('wraps a network failure as ApiError code=network', async () => {
    const api = new BookedApi({
      fetch: async () => {
        throw new Error('offline');
      },
    });
    await expect(api.services()).rejects.toMatchObject({ name: 'ApiError', code: 'network' });
  });

  it('throws if no fetch is available', () => {
    const original = globalThis.fetch;
    // eslint-disable-next-line no-global-assign
    globalThis.fetch = undefined;
    try {
      expect(() => new BookedApi({})).toThrow(/no fetch/);
    } finally {
      globalThis.fetch = original;
    }
  });
});

describe('BookedApi — stale-response guard (channels)', () => {
  it('aborts the in-flight request when a newer one fires on the same channel', async () => {
    const aborted = [];
    // A fetch that resolves only when its signal aborts (for the first call),
    // and immediately for the second.
    let call = 0;
    const api = new BookedApi({
      fetch: (url, init) => {
        call++;
        const n = call;
        return new Promise((resolve, reject) => {
          if (n === 1) {
            init.signal.addEventListener('abort', () => {
              aborted.push(n);
              const e = new Error('aborted');
              e.name = 'AbortError';
              reject(e);
            });
          } else {
            resolve({ ok: true, status: 200, text: async () => JSON.stringify({ n }) });
          }
        });
      },
    });

    const first = api.slots({ date: '2026-08-01' }); // channel 'slots'
    const second = api.slots({ date: '2026-08-02' }); // supersedes

    await expect(first).rejects.toBeInstanceOf(AbortedError);
    await expect(second).resolves.toEqual({ n: 2 });
    expect(aborted).toEqual([1]);
  });

  it('requests on different channels do not abort each other', async () => {
    const api = new BookedApi({
      fetch: async (url) => ({ ok: true, status: 200, text: async () => JSON.stringify({ url }) }),
    });
    const a = await api.slots({});
    const b = await api.dates({});
    expect(a).toBeTruthy();
    expect(b).toBeTruthy();
  });

  it('abortAll() clears in-flight channels', async () => {
    let sawAbort = false;
    const api = new BookedApi({
      fetch: (url, init) =>
        new Promise((_resolve, reject) => {
          init.signal.addEventListener('abort', () => {
            sawAbort = true;
            const e = new Error('aborted');
            e.name = 'AbortError';
            reject(e);
          });
        }),
    });
    const p = api.slots({});
    api.abortAll();
    await expect(p).rejects.toBeInstanceOf(AbortedError);
    expect(sawAbort).toBe(true);
  });
});

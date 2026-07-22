/**
 * Renderer shell — the vanilla UI bound to a headless core Wizard.
 *
 * The shell owns the cross-step concerns: which step region is visible, focus
 * movement between steps, the progress indicator, the aria-live announcer, and
 * the error region. It listens to the core's events (never reaches into core
 * internals) and drives navigation by delegating DOM events back to core
 * methods — proving the headless contract from the consumer side.
 *
 * Step *content* (service cards, the calendar, slot list) is populated by
 * per-step renderers registered via `registerStep`; the shell just shows/hides
 * their regions. All markup comes from Twig-rendered `<template>`/step regions
 * carrying `data-booked-*` hooks — the stable styling and behavior contract.
 */
import { qs, qsa, setHidden, setText, delegate, focusElement, LiveRegion } from './dom.js';

const SEL = {
  step: '[data-booked-step]',
  stepHeading: '[data-booked-step-heading]',
  progress: '[data-booked-progress]',
  progressCurrent: '[data-booked-progress-current]',
  progressTotal: '[data-booked-progress-total]',
  live: '[data-booked-live]',
  error: '[data-booked-error]',
  loading: '[data-booked-loading]',
};

export class Renderer {
  /**
   * @param {import('../core/wizard.js').Wizard} wizard
   * @param {Element} root  the mounted wizard container
   */
  constructor(wizard, root) {
    if (!root) throw new Error('Renderer: a root element is required');
    this._wizard = wizard;
    this._root = root;
    this._steps = new Map(); // stepId → per-step renderer (optional)
    this._mounted = new Set(); // stepIds whose one-time mount() has run
    this._mountedRegions = new Map(); // stepId → region, for unmount() on destroy
    this._unbinders = [];

    const liveEl = qs(SEL.live, root);
    this._live = liveEl ? new LiveRegion(liveEl) : null;

    this._bindCoreEvents();
    this._bindDomActions();
  }

  /** Register a per-step content renderer: { render(regionEl, wizard), update? }. */
  registerStep(stepId, stepRenderer) {
    this._steps.set(stepId, stepRenderer);
    return this;
  }

  /** Attach a captcha controller whose token refreshes before each submit. */
  setCaptcha(captcha) {
    this._captcha = captcha;
    return this;
  }

  // ---- Core → DOM ======================================================

  _bindCoreEvents() {
    const on = (event, handler) => this._unbinders.push(this._wizard.on(event, handler));

    on('step:change', ({ to }) => this._showStep(to));
    on('state:change', ({ to }) => this._onState(to));
    on('announce', ({ message, politeness }) => this._live?.announce(message, politeness));
    on('error', (payload) => this._showError(payload));
    on('data:loaded', () => this._updateActiveStep());
  }

  _onState(lifecycle) {
    if (lifecycle === 'loading') this._setLoading(true);
    else this._setLoading(false);
    if (lifecycle === 'confirmed') this._showStep('success');
    if (lifecycle === 'browsing') this._clearError();
  }

  /**
   * Show one step region, hide the rest, update progress. Moves focus to the
   * step heading only on user-driven navigation (`focus: true`); the initial
   * render passes `focus: false` so the wizard never steals focus on page load.
   */
  _showStep(stepId, { focus = true } = {}) {
    for (const region of qsa(SEL.step, this._root)) {
      setHidden(region, region.getAttribute('data-booked-step') !== stepId);
    }
    const active = this._regionFor(stepId);
    if (active) {
      this._renderStep(stepId, active);
      if (focus) {
        const heading = qs(SEL.stepHeading, active) || active;
        focusElement(heading);
      }
    }
    this._updateProgress();
    this._clearError();
  }

  /** Run a step renderer's one-time mount (first show) then its per-show render. */
  _renderStep(stepId, active) {
    const step = this._steps.get(stepId);
    if (!step) return;
    if (!this._mounted.has(stepId) && typeof step.mount === 'function') {
      step.mount(active, this._wizard);
      this._mounted.add(stepId);
      this._mountedRegions.set(stepId, active);
    }
    if (typeof step.render === 'function') step.render(active, this._wizard);
  }

  _updateActiveStep() {
    const stepId = this._wizard.stepId;
    const active = this._regionFor(stepId);
    if (active) this._renderStep(stepId, active);
  }

  _regionFor(stepId) {
    return qs(`[data-booked-step="${stepId}"]`, this._root);
  }

  _updateProgress() {
    const state = this._wizard.getState();
    const progress = qs(SEL.progress, this._root);
    if (!progress) return;
    setText(qs(SEL.progressCurrent, progress), state.position);
    setText(qs(SEL.progressTotal, progress), state.total);
    progress.setAttribute('aria-valuenow', String(state.position));
    progress.setAttribute('aria-valuemax', String(state.total));
  }

  _setLoading(isLoading) {
    const el = qs(SEL.loading, this._root);
    if (el) setHidden(el, !isLoading);
    this._root.setAttribute('data-booked-loading-state', isLoading ? 'loading' : 'idle');
  }

  _showError(payload) {
    const el = qs(SEL.error, this._root);
    if (!el) return;
    if (payload && payload.message) {
      setText(el, payload.message);
      setHidden(el, false);
    }
  }

  _clearError() {
    const el = qs(SEL.error, this._root);
    if (el) {
      setText(el, '');
      setHidden(el, true);
    }
  }

  // ---- DOM → Core ======================================================

  _bindDomActions() {
    const bind = (type, selector, fn) => this._unbinders.push(delegate(this._root, type, selector, fn));

    bind('click', '[data-booked-action="next"]', (e) => {
      e.preventDefault();
      this._wizard.goNext();
    });
    bind('click', '[data-booked-action="back"]', (e) => {
      e.preventDefault();
      this._wizard.goBack();
    });
    bind('click', '[data-booked-action="submit"]', async (e) => {
      e.preventDefault();
      const addToCart = e.target.closest('[data-booked-add-to-cart]') !== null;
      // Refresh the captcha token (reCAPTCHA v3 mints one per submit) before sending.
      if (this._captcha) {
        try {
          await this._captcha.ensureToken();
        } catch {
          /* fall through; backend rejects a missing/expired token */
        }
      }
      this._wizard.submit({ addToCart, fields: this._collectAntiSpamFields() });
    });
    bind('click', '[data-booked-action="select-service"]', (e, el) => {
      e.preventDefault();
      const id = Number(el.getAttribute('data-booked-id'));
      if (Number.isInteger(id)) this._wizard.selectService(id);
    });
    bind('click', '[data-booked-action="select-location"]', (e, el) => {
      e.preventDefault();
      const id = Number(el.getAttribute('data-booked-id'));
      if (Number.isInteger(id)) {
        this._wizard.selectLocation(id);
        this._updateActiveStep();
      }
    });
    bind('click', '[data-booked-action="select-employee"]', (e, el) => {
      e.preventDefault();
      const id = Number(el.getAttribute('data-booked-id'));
      if (Number.isInteger(id)) {
        this._wizard.selectEmployee(id);
        this._updateActiveStep();
      }
    });
    bind('click', '[data-booked-action="select-event"]', (e, el) => {
      e.preventDefault();
      if (el.getAttribute('aria-disabled') === 'true') return;
      const id = Number(el.getAttribute('data-booked-id'));
      if (Number.isInteger(id)) {
        this._wizard.selectEventDate(id).then(() => this._updateActiveStep());
      }
    });
    bind('click', '[data-booked-action="extra-increment"]', (e, el) => {
      e.preventDefault();
      this._adjustExtra(el, 1);
    });
    bind('click', '[data-booked-action="extra-decrement"]', (e, el) => {
      e.preventDefault();
      this._adjustExtra(el, -1);
    });
  }

  /**
   * Collect anti-spam fields to send with the booking. The honeypot is a
   * visually-hidden input a human never fills but a bot does; its `name`/value
   * pass straight through to the backend's spam check. A captcha token, if a
   * widget populated `[data-booked-captcha-token]`, rides along too.
   */
  _collectAntiSpamFields() {
    const fields = {};
    const honeypot = qs('[data-booked-honeypot]', this._root);
    if (honeypot && honeypot.name) fields[honeypot.name] = honeypot.value || '';
    const captcha = qs('[data-booked-captcha-token]', this._root);
    if (captcha && captcha.value) fields.captchaToken = captcha.value;
    return fields;
  }

  /** Nudge an add-on's quantity within its [min,max] and repaint the active step. */
  _adjustExtra(el, delta) {
    const id = Number(el.getAttribute('data-booked-extra-id'));
    if (!Number.isInteger(id)) return;
    const ctx = this._wizard.getState().context;
    const extra = (ctx.extras || []).find((e) => e.id === id);
    const min = extra && extra.isRequired ? 1 : 0; // required add-ons can't go to 0
    const max = extra && extra.maxQuantity ? extra.maxQuantity : Infinity;
    const current = ctx.selectedExtras[id] || 0;
    const next = Math.min(max, Math.max(min, current + delta));
    if (next !== current) {
      this._wizard.selectExtra(id, next);
      this._updateActiveStep();
    }
  }

  /** Render the initial step after the wizard has started (without stealing focus). */
  syncInitial() {
    this._showStep(this._wizard.stepId, { focus: false });
  }

  destroy() {
    // Let mounted step renderers release any core subscriptions they made.
    for (const [stepId, region] of this._mountedRegions) {
      const step = this._steps.get(stepId);
      if (step && typeof step.unmount === 'function') step.unmount(region);
    }
    this._mountedRegions.clear();
    this._mounted.clear();
    for (const off of this._unbinders) off();
    this._unbinders = [];
    this._steps.clear();
  }
}

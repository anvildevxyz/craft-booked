/**
 * Questions step renderer — a service's custom booking questions (#146).
 *
 * Clones a `question-field` template per question, builds the right input
 * for its `type` (text/textarea/dropdown/radio/checkboxes/lightswitch), and
 * wires a delegated `change`/`input` listener that feeds `wizard.setAnswer()`.
 * `assets` questions are rendered as a file input; the file is uploaded via
 * a separate pre-upload endpoint and the returned asset id is what's stored
 * as the answer, so the main booking submission stays form-encoded.
 */
import { qs, qsa, cloneTemplate, setText, setHidden, delegate } from '../dom.js';

const cleanups = new WeakMap();

/** Build the input element(s) for one question's `type` inside `fieldEl`. */
function buildInput(fieldEl, question) {
  const mount = qs('[data-booked-question-input]', fieldEl);
  if (!mount) return;
  const name = `question-${question.handle}`;

  switch (question.type) {
    case 'textarea': {
      const el = document.createElement('textarea');
      el.setAttribute('data-booked-question', question.handle);
      el.name = name;
      mount.appendChild(el);
      break;
    }
    case 'dropdown': {
      const el = document.createElement('select');
      el.setAttribute('data-booked-question', question.handle);
      el.name = name;
      // A native <select> auto-selects its first option, which would submit
      // an answer the customer never chose. A blank, disabled placeholder
      // forces an explicit choice before the value can be anything real.
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.disabled = true;
      placeholder.selected = true;
      placeholder.textContent = fieldEl.querySelector('[data-booked-select-placeholder]')?.dataset.bookedSelectPlaceholder ?? '';
      el.appendChild(placeholder);
      for (const opt of question.options ?? []) {
        const optionEl = document.createElement('option');
        optionEl.value = opt.value;
        optionEl.textContent = opt.label;
        el.appendChild(optionEl);
      }
      mount.appendChild(el);
      break;
    }
    case 'radio':
    case 'checkboxes': {
      const inputType = question.type === 'radio' ? 'radio' : 'checkbox';
      for (const opt of question.options ?? []) {
        const label = document.createElement('label');
        const el = document.createElement('input');
        el.type = inputType;
        el.name = name;
        el.value = opt.value;
        el.setAttribute('data-booked-question', question.handle);
        label.appendChild(el);
        label.appendChild(document.createTextNode(opt.label));
        mount.appendChild(label);
      }
      break;
    }
    case 'lightswitch': {
      const el = document.createElement('input');
      el.type = 'checkbox';
      el.setAttribute('data-booked-question', question.handle);
      el.name = name;
      mount.appendChild(el);
      break;
    }
    case 'assets': {
      const el = document.createElement('input');
      el.type = 'file';
      el.setAttribute('data-booked-question', question.handle);
      el.setAttribute('data-booked-question-upload', 'true');
      el.name = name;
      mount.appendChild(el);
      break;
    }
    default: {
      // text
      const el = document.createElement('input');
      el.type = 'text';
      el.setAttribute('data-booked-question', question.handle);
      el.name = name;
      mount.appendChild(el);
      break;
    }
  }
}

/** Read the current answer value for one question's input group. */
function readValue(region, question) {
  if (question.type === 'checkboxes') {
    return qsa(`[data-booked-question="${question.handle}"]`, region)
      .filter((el) => el.checked)
      .map((el) => el.value);
  }
  if (question.type === 'radio') {
    return qsa(`[data-booked-question="${question.handle}"]`, region).find((el) => el.checked)?.value ?? '';
  }
  if (question.type === 'lightswitch') {
    return qs(`[data-booked-question="${question.handle}"]`, region)?.checked ?? false;
  }
  return qs(`[data-booked-question="${question.handle}"]`, region)?.value ?? '';
}

export const questionsStep = {
  mount(region, wizard) {
    const offChange = delegate(region, 'change', '[data-booked-question]', (event, el) => {
      const handle = el.getAttribute('data-booked-question');
      const question = (wizard.getState().context.questions || []).find((q) => q.handle === handle);
      if (!question) return;
      if (el.hasAttribute('data-booked-question-upload')) {
        this._handleUpload(region, wizard, el, question);
        return;
      }
      wizard.setAnswer(handle, readValue(region, question));
    });
    // Text/textarea answers should also update as the customer types, not
    // only on blur (`change`), matching customer-info's `input` binding.
    const offInput = delegate(region, 'input', 'input[type="text"][data-booked-question], textarea[data-booked-question]', (event, el) => {
      wizard.setAnswer(el.getAttribute('data-booked-question'), el.value);
    });

    const offError = wizard.on('error', (payload) => {
      if (!payload || payload.code !== 'validation' || !payload.messages) return;
      this._applyErrors(region, payload.messages);
    });

    cleanups.set(region, () => {
      offChange();
      offInput();
      offError();
    });
  },

  unmount(region) {
    const off = cleanups.get(region);
    if (off) {
      off();
      cleanups.delete(region);
    }
  },

  render(region, wizard) {
    const container = qs('[data-booked-list="questions"]', region);
    if (!container) return;
    const { context } = wizard.getState();
    const questions = context.questions || [];
    const answers = context.answers || {};

    container.replaceChildren();
    for (const question of questions) {
      const frag = cloneTemplate(region, 'question-field');
      if (!frag) break;

      setText(frag.querySelector('[data-booked-field="label"]'), question.label);
      const instructionsEl = frag.querySelector('[data-booked-field="instructions"]');
      setText(instructionsEl, question.instructions);
      setHidden(instructionsEl, !question.instructions);

      const fieldEl = frag.firstElementChild;
      if (fieldEl && question.required) fieldEl.setAttribute('data-booked-required', 'true');

      // Stamp this question's handle onto its status/error slots — the
      // template is one generic shell cloned per question, so these only
      // become handle-specific here (mirrors extras.js stamping extra ids).
      const statusEl = frag.querySelector('[data-booked-field-status]');
      if (statusEl) statusEl.setAttribute('data-booked-field-status', question.handle);
      const errorEl = frag.querySelector('[data-booked-field-error]');
      if (errorEl) errorEl.setAttribute('data-booked-field-error', question.handle);

      buildInput(frag, question);

      // Reflect the current answer (e.g. after navigating back from review).
      const answer = answers[question.handle];
      if (answer !== undefined) {
        if (question.type === 'checkboxes' && Array.isArray(answer)) {
          for (const el of frag.querySelectorAll(`[data-booked-question="${question.handle}"]`)) {
            el.checked = answer.includes(el.value);
          }
        } else if (question.type === 'radio') {
          for (const el of frag.querySelectorAll(`[data-booked-question="${question.handle}"]`)) {
            el.checked = el.value === answer;
          }
        } else if (question.type === 'lightswitch') {
          const el = frag.querySelector(`[data-booked-question="${question.handle}"]`);
          if (el) el.checked = !!answer;
        } else if (question.type === 'assets') {
          // The file input itself can't be set programmatically; a generic
          // "already uploaded" note is all that's shown on returning to this
          // step — the filename from the original upload isn't kept in context.
          const text = fieldEl?.dataset.bookedUploadedText || 'A file was uploaded.';
          setText(frag.querySelector(`[data-booked-field-status="${question.handle}"]`), text);
          setHidden(frag.querySelector(`[data-booked-field-status="${question.handle}"]`), false);
        } else {
          const el = frag.querySelector(`[data-booked-question="${question.handle}"]`);
          if (el) el.value = answer;
        }
      }

      container.appendChild(frag);
    }

    this._clearErrors(region);
  },

  /**
   * Upload the selected file for an `assets` question and record the
   * resulting asset id as its answer. A failed upload surfaces into that
   * question's own error slot, local to this one input — the customer can
   * retry without losing anything else they've filled in on the step.
   */
  async _handleUpload(region, wizard, el, question) {
    const file = el.files?.[0];
    const statusEl = qs(`[data-booked-field-status="${question.handle}"]`, region);
    const errorEl = qs(`[data-booked-field-error="${question.handle}"]`, region);
    if (!file) return;

    const fieldWrap = el.closest('[data-booked-uploading-text], [data-booked-uploaded-text]');

    setText(errorEl, '');
    setHidden(errorEl, true);
    el.disabled = true;
    setText(statusEl, fieldWrap?.dataset.bookedUploadingText || 'Uploading…');
    setHidden(statusEl, false);

    try {
      await wizard.uploadQuestionFile(question.handle, file);
      setText(statusEl, file.name);
    } catch (err) {
      el.value = '';
      setText(statusEl, '');
      setHidden(statusEl, true);
      if (errorEl) {
        setText(errorEl, err?.message || 'Upload failed.');
        setHidden(errorEl, false);
      }
      el.setAttribute('aria-invalid', 'true');
    } finally {
      el.disabled = false;
    }
  },

  _applyErrors(region, messages) {
    this._clearErrors(region);
    for (const [handle, message] of Object.entries(messages)) {
      const errorEl = qs(`[data-booked-field-error="${handle}"]`, region);
      const inputs = qsa(`[data-booked-question="${handle}"]`, region);
      for (const input of inputs) input.setAttribute('aria-invalid', 'true');
      if (errorEl) {
        setText(errorEl, message);
        setHidden(errorEl, false);
      }
    }
  },

  _clearErrors(region) {
    for (const input of qsa('[data-booked-question]', region)) {
      input.removeAttribute('aria-invalid');
    }
    for (const errorEl of qsa('[data-booked-field-error]', region)) {
      setText(errorEl, '');
      setHidden(errorEl, true);
    }
  },
};

/**
 * Booked headless wizard core — public entry point.
 *
 * The full `BookedWizard.create()` facade (context, api, lock, i18n wired
 * together) lands as M1 progresses. This entry currently exports the building
 * blocks that are implemented and tested, plus the contract version.
 *
 * The core contract version is semver'd independently of the plugin version:
 * additions bump minor, removals/renames bump major (2.0).
 */
export const version = '1.0.0-dev';

export { Wizard, create } from './wizard.js';
export { Emitter } from './emitter.js';
export { Machine, STATES } from './machine.js';
export { Flow } from './flow.js';
export { bookingFlow } from './flows/booking.js';
export { eventFlow } from './flows/event.js';
export { BookedApi, ApiError, AbortedError } from './api.js';
export { Context } from './context.js';
export { LockController } from './lock.js';
export { I18n, DEFAULTS as I18N_DEFAULTS } from './i18n.js';
export {
  isValidEmail,
  isPresent,
  validateCustomer,
  validateQuantity,
  canLeaveStep,
} from './validation.js';

import { create } from './wizard.js';
export default { version, create };

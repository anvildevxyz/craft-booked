/**
 * Booking flow definition — the service/appointment wizard.
 *
 * Steps are data: each skip rule is a `visible(ctx)` predicate the flow engine
 * (flow.js) walks in both directions. `success` is not a step — completion is
 * the `confirmed` lifecycle state.
 *
 * Predicate rationale — a step is shown only when it offers a real choice, so a
 * one-of-everything setup lands straight on the calendar:
 *   service   — shown unless exactly one exists (1 auto-selects in start())
 *   extras    — shown only when the selected service has add-ons
 *   location  — shown only when more than one location exists (1 auto-selects)
 *   employee  — shown only when more than one exists (1 auto-selects)
 *
 * Auto-selected service, location and employee are still shown on the review
 * step, so the customer confirms them before submitting.
 */
export const bookingFlow = {
  id: 'booking',
  steps: [
    { id: 'service', visible: (ctx) => !(Array.isArray(ctx.services) && ctx.services.length === 1) },
    { id: 'extras', visible: (ctx) => Array.isArray(ctx.extras) && ctx.extras.length > 0 },
    { id: 'location', visible: (ctx) => Array.isArray(ctx.locations) && ctx.locations.length > 1 },
    { id: 'employee', visible: (ctx) => Array.isArray(ctx.employees) && ctx.employees.length > 1 },
    { id: 'datetime', visible: () => true },
    { id: 'info', visible: () => true },
    { id: 'review', visible: () => true },
  ],
};

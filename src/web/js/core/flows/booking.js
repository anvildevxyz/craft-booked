/**
 * Booking flow definition — the service/appointment wizard.
 *
 * Steps are data: each skip rule is a `visible(ctx)` predicate the flow engine
 * (flow.js) walks in both directions. `success` is not a step — completion is
 * the `confirmed` lifecycle state.
 *
 * Predicate rationale:
 *   extras    — shown only when the selected service has add-ons
 *   location  — shown only when more than one location exists (1 auto-selects)
 *   employee  — shown whenever employees exist (so the customer confirms who
 *               they're booking with)
 */
export const bookingFlow = {
  id: 'booking',
  steps: [
    { id: 'service', visible: () => true },
    { id: 'extras', visible: (ctx) => Array.isArray(ctx.extras) && ctx.extras.length > 0 },
    { id: 'location', visible: (ctx) => Array.isArray(ctx.locations) && ctx.locations.length > 1 },
    {
      // Shown whenever employees exist; skipped only when there are none.
      id: 'employee',
      visible: (ctx) => Array.isArray(ctx.employees) && ctx.employees.length > 0,
    },
    { id: 'datetime', visible: () => true },
    { id: 'info', visible: () => true },
    { id: 'review', visible: () => true },
  ],
};

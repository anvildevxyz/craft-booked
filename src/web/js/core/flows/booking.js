/**
 * Booking flow definition — the service/appointment wizard.
 *
 * Steps are data; the skip rules that used to live in `nextStep`,
 * `getPreviousStep` and `shouldSkipEmployeeStep` are expressed once here as
 * `visible(ctx)` predicates. The step engine (flow.js) walks these in both
 * directions, so forward and back can never disagree.
 *
 * `success` is not a step — completion is the `confirmed` lifecycle state.
 *
 * Predicate rationale (from the current wizard's behavior):
 *   extras    — shown only when the selected service has add-ons
 *   location  — shown only when more than one location exists (1 auto-selects)
 *   employee  — shown when employees exist AND the service has no own schedule
 *               (a schedule-carrying service needs no employee choice)
 */
export const bookingFlow = {
  id: 'booking',
  steps: [
    { id: 'service', visible: () => true },
    { id: 'extras', visible: (ctx) => Array.isArray(ctx.extras) && ctx.extras.length > 0 },
    { id: 'location', visible: (ctx) => Array.isArray(ctx.locations) && ctx.locations.length > 1 },
    {
      // Parity with the legacy shouldSkipEmployeeStep: show whenever employees
      // exist (so the customer sees/confirms who they're booking with), even for
      // a schedule-carrying service. Skipped only when there are no employees.
      id: 'employee',
      visible: (ctx) => Array.isArray(ctx.employees) && ctx.employees.length > 0,
    },
    { id: 'datetime', visible: () => true },
    { id: 'info', visible: () => true },
    { id: 'review', visible: () => true },
  ],
};

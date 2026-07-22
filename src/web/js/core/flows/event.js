/**
 * Event flow definition — the event-date booking wizard.
 *
 * Simpler than the booking flow: pick an event date, give customer info,
 * review. No location/employee/extras branching. Event-management mode
 * (?manage=<token>) is a separate concern handled outside the step cursor.
 */
export const eventFlow = {
  id: 'event',
  steps: [
    { id: 'event', visible: () => true },
    { id: 'info', visible: () => true },
    { id: 'review', visible: () => true },
  ],
};

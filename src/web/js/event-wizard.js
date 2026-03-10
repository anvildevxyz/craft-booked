/**
 * Booked Event Wizard Component (Alpine.js)
 *
 * Lightweight wizard for event-based bookings (EventDates).
 * Events are independent entities with their own date, time, capacity, and optional location.
 *
 * Flow:
 * Step 1: Select Event
 * Step 2: Enter Customer Info
 * Step 3: Review & Confirm
 * Step 4: Success
 *
 * Configuration options (passed via config parameter):
 * - requirePhone: boolean - Whether phone field is required (default: false)
 * - showNotes: boolean - Whether to show notes field (default: true)
 * - defaultQuantity: number - Default booking quantity (default: 1)
 */
(function() {
    let wizardRegistered = false;
    const initEventWizard = () => {
        if (!window.Alpine || wizardRegistered) return;
        wizardRegistered = true;

        Alpine.data('eventWizard', (config = {}) => ({
            step: 1,
            totalSteps: 3,
            loading: false,

            // Configuration (with defaults)
            config: {
                requirePhone: false,
                showNotes: true,
                defaultQuantity: 1,
                captchaEnabled: false,
                captchaProvider: null,
                captchaSiteKey: null,
                ...config,
            },

            // Shared state (customer info, commerce, CAPTCHA, accessibility)
            ...BookedWizardCommon.sharedState(config),
            // Shared methods (checkLoggedInUser, fetchCommerceSettings, validation, CAPTCHA, etc.)
            ...BookedWizardCommon.sharedMethods({ captchaContainerPrefix: 'booked-event' }),

            // Soft lock
            softLockToken: null,

            // Form Data
            eventDateId: null,
            quantity: config.defaultQuantity ?? 1,

            // Data
            eventDates: [],

            // Selected event object
            selectedEvent: null,

            // Waitlist
            waitlistEventId: null,
            waitlistLoading: false,
            waitlistSuccess: false,
            waitlistError: null,

            // Management mode
            managementMode: false,
            managementData: null,
            managementLoading: false,
            quantityChangeAmount: 1,
            managementError: null,
            managementSuccess: null,

            init() {
                BookedWizardCommon.initLoadingWatcher(this);
                BookedWizardCommon.initStepAnnouncer(this, 3);

                // Check URL for management token
                const urlParams = new URLSearchParams(window.location.search);
                const manageToken = urlParams.get('manage');
                if (manageToken) {
                    this.initManagement(manageToken);
                    return; // Skip normal wizard init
                }

                // Check URL for waitlist conversion token
                const waitlistToken = urlParams.get('waitlist');
                if (waitlistToken) {
                    this.handleWaitlistConversion(waitlistToken);
                    return; // Conversion handler takes over
                }

                this.fetchEventDates();
                this.fetchCommerceSettings();
                this.checkLoggedInUser();

                this._beforeUnloadHandler = () => {
                    if (this.softLockToken) {
                        const data = new URLSearchParams({
                            token: this.softLockToken,
                            [window.csrfTokenName]: window.csrfTokenValue,
                        });
                        navigator.sendBeacon('/actions/booked/slot/release-lock', data);
                    }
                };
                window.addEventListener('beforeunload', this._beforeUnloadHandler);
            },

            destroy() {
                if (this.softLockToken) {
                    this.releaseEventSoftLock(this.softLockToken);
                    this.softLockToken = null;
                }
                window.removeEventListener('beforeunload', this._beforeUnloadHandler);
            },

            async handleWaitlistConversion(token) {
                this.loading = true;

                // Always initialize the wizard normally first
                this.fetchCommerceSettings();
                this.checkLoggedInUser();

                let result = null;
                try {
                    const response = await fetch('/actions/booked/waitlist-conversion/convert?conversionToken=' + encodeURIComponent(token), {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (response.ok) {
                        result = await response.json();
                    }
                } catch (error) {
                    console.warn('Waitlist conversion lookup failed:', error);
                }

                // Pre-fill customer info if conversion succeeded
                if (result?.success) {
                    if (result.userName) this.customerName = result.userName;
                    if (result.userEmail) this.customerEmail = result.userEmail;
                    if (result.userPhone) this.customerPhone = result.userPhone;
                } else if (result && !result.success) {
                    this.bookingError = result.error || 'This waitlist link has expired. You can still book normally below.';
                }

                // Always fetch event dates so the wizard is usable
                await this.fetchEventDates();

                // Auto-select the waitlisted event if we have one
                if (result?.success && result.eventDateId) {
                    const event = this.eventDates.find(e => e.id === result.eventDateId);
                    if (event) {
                        await this.selectEvent(event);
                    }
                }

                this.loading = false;
            },

            async fetchEventDates() {
                this.loading = true;
                try {
                    const params = new URLSearchParams();
                    const siteHandle = this.config.siteHandle || '';
                    if (siteHandle) params.append('site', siteHandle);
                    const url = '/actions/booked/slot/get-event-dates' + (params.toString() ? '?' + params.toString() : '');
                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = await response.json();
                    if (data.success) {
                        this.eventDates = data.eventDates || [];
                    }
                } catch (error) {
                    console.warn('Could not fetch event dates:', error);
                } finally {
                    this.loading = false;
                }
            },

            async selectEvent(event) {
                if (this.loading) return;
                this.loading = true;
                try {
                    this.eventDateId = event.id;
                    this.selectedEvent = event;
                    // Reset quantity
                    this.quantity = this.config.defaultQuantity ?? 1;
                    await this.createEventSoftLock();
                    this.step = 2;
                } finally {
                    this.loading = false;
                }
            },

            nextStep() {
                if (this.step < this.totalSteps) {
                    this.step++;
                }
            },

            prevStep() {
                if (this.step > 1) {
                    this.bookingError = null;
                    // Release soft lock when going back to event selection
                    if (this.step === 2 && this.softLockToken) {
                        this.releaseEventSoftLock(this.softLockToken);
                        this.softLockToken = null;
                    }
                    this.step--;
                }
            },

            async createEventSoftLock() {
                if (!this.selectedEvent) return;

                try {
                    const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                    const csrfTokenValue = window.csrfTokenValue || '';
                    const formData = new URLSearchParams();
                    formData.append(csrfTokenName, csrfTokenValue);
                    formData.append('eventDateId', this.eventDateId);
                    if (this.quantity > 1) {
                        formData.append('quantity', this.quantity);
                    }

                    const response = await fetch('/actions/booked/slot/create-event-lock', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const result = await response.json();
                    this.softLockToken = result.success ? result.token : null;
                } catch (error) {
                    console.warn('Could not create event soft lock:', error);
                    this.softLockToken = null;
                }
            },

            async releaseEventSoftLock(token) {
                if (!token) return;
                try {
                    const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                    const csrfTokenValue = window.csrfTokenValue || '';
                    const formData = new URLSearchParams();
                    formData.append(csrfTokenName, csrfTokenValue);
                    formData.append('token', token);

                    await fetch('/actions/booked/slot/release-lock', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                        body: formData.toString()
                    });
                } catch (error) {
                    // Best effort - lock will expire naturally
                }
            },

            async submitBooking() {
                if (this.loading) return;

                if (!this.isCustomerInfoValid()) {
                    this.bookingError = this.config.messages?.customerInfoRequired || 'Please complete all required fields.';
                    return;
                }

                this.loading = true;

                const captchaToken = await this.getCaptchaToken();
                if (this.config.captchaEnabled && captchaToken === null) {
                    this.loading = false;
                    return;
                }

                const data = {
                    eventDateId: this.eventDateId,
                    quantity: this.quantity,
                    customerName: this.customerName,
                    customerEmail: this.customerEmail,
                    customerPhone: this.customerPhone,
                    notes: this.notes,
                    softLockToken: this.softLockToken,
                    captchaToken,
                    addToCart: this.addToCartOnly ? '1' : '0',
                    siteHandle: this.config.siteHandle || ''
                };
                this.appendHoneypotData(data);

                try {
                    this.bookingError = null;
                    const result = await window.BookedAvailability.createBooking(data);
                    if (this.handleBookingResult(result)) return;
                } catch (error) {
                    this.handleBookingError(error);
                } finally {
                    this.loading = false;
                }
            },

            requiresPayment() {
                return this.commerceEnabled && this.selectedEvent?.price > 0;
            },

            getEventTotalPrice() {
                if (!this.selectedEvent?.price) return 0;
                return parseFloat(this.selectedEvent.price) * this.quantity;
            },

            initManagement(token) {
                this.managementMode = true;
                this.managementLoading = true;
                this.managementError = null;

                const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                const csrfTokenValue = window.csrfTokenValue || '';

                fetch('/actions/booked/booking-management/manage-booking', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams({ [csrfTokenName]: csrfTokenValue, token }),
                })
                    .then(r => {
                        if (!r.ok) throw new Error(`Server error: ${r.status}`);
                        return r.json();
                    })
                    .then(data => {
                        if (data.success === false) {
                            this.managementError = data.message || data.error || 'Invalid or expired token';
                        } else {
                            this.managementData = data;
                        }
                        this.managementLoading = false;
                    })
                    .catch(() => {
                        this.managementError = 'Failed to load booking details';
                        this.managementLoading = false;
                    });
            },

            cancelBooking() {
                if (!confirm(this.config.translations?.confirmCancel || 'Are you sure you want to cancel this booking?')) return;
                this.managementLoading = true;
                this.managementError = null;

                const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                const csrfTokenValue = window.csrfTokenValue || '';
                const params = new URLSearchParams();
                params.append(csrfTokenName, csrfTokenValue);
                params.append('id', this.managementData.id);
                params.append('token', this.managementData.token);

                fetch('/actions/booked/booking-management/cancel-booking', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: params,
                })
                .then(r => {
                    if (!r.ok) throw new Error(`Server error: ${r.status}`);
                    return r.json();
                })
                .then(data => {
                    this.managementLoading = false;
                    if (data.success) {
                        this.managementData.status = 'cancelled';
                        this.managementSuccess = this.config.translations?.cancelSuccess || 'Booking cancelled successfully';
                    } else {
                        this.managementError = data.message || data.error || 'Failed to cancel booking';
                    }
                })
                .catch(() => {
                    this.managementLoading = false;
                    this.managementError = 'Failed to cancel booking';
                });
            },

            reduceBookingQuantity() {
                this.managementLoading = true;
                this.managementError = null;
                this.managementSuccess = null;

                const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                const csrfTokenValue = window.csrfTokenValue || '';
                const params = new URLSearchParams();
                params.append(csrfTokenName, csrfTokenValue);
                params.append('id', this.managementData.id);
                params.append('token', this.managementData.token);
                params.append('reduceBy', this.quantityChangeAmount);

                fetch('/actions/booked/booking-management/reduce-quantity', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: params,
                })
                .then(r => {
                    if (!r.ok) throw new Error(`Server error: ${r.status}`);
                    return r.json();
                })
                .then(data => {
                    this.managementLoading = false;
                    if (data.success) {
                        this.managementData.quantity -= this.quantityChangeAmount;
                        this.quantityChangeAmount = 1;
                        this.managementSuccess = data.message || 'Quantity reduced';
                    } else {
                        this.managementError = data.message || data.error || 'Failed to reduce quantity';
                    }
                })
                .catch(() => {
                    this.managementLoading = false;
                    this.managementError = 'Failed to reduce quantity';
                });
            },

            increaseBookingQuantity() {
                this.managementLoading = true;
                this.managementError = null;
                this.managementSuccess = null;

                const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                const csrfTokenValue = window.csrfTokenValue || '';
                const params = new URLSearchParams();
                params.append(csrfTokenName, csrfTokenValue);
                params.append('id', this.managementData.id);
                params.append('token', this.managementData.token);
                params.append('increaseBy', this.quantityChangeAmount);

                fetch('/actions/booked/booking-management/increase-quantity', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: params,
                })
                .then(r => {
                    if (!r.ok) throw new Error(`Server error: ${r.status}`);
                    return r.json();
                })
                .then(data => {
                    this.managementLoading = false;
                    if (data.success) {
                        this.managementData.quantity += this.quantityChangeAmount;
                        this.quantityChangeAmount = 1;
                        this.managementSuccess = data.message || 'Quantity increased';
                    } else {
                        this.managementError = data.message || data.error || 'Failed to increase quantity';
                    }
                })
                .catch(() => {
                    this.managementLoading = false;
                    this.managementError = 'Failed to increase quantity';
                });
            },

            showWaitlistForm(event) {
                this.waitlistEventId = event.id;
                this.selectedEvent = event;
                this.waitlistSuccess = false;
                this.waitlistError = null;
            },

            hideWaitlistForm() {
                this.waitlistEventId = null;
                this.waitlistSuccess = false;
                this.waitlistError = null;
            },

            async joinEventWaitlist() {
                if (!this.customerName || !this.customerEmail) {
                    this.waitlistError = this.config.messages?.waitlistNameEmailRequired || 'Please enter your name and email.';
                    return;
                }

                this.waitlistLoading = true;
                this.waitlistError = null;

                // For interactive CAPTCHAs (hCaptcha/Turnstile), the widget may not be
                // rendered on the waitlist step. Use token if available (reCAPTCHA v3).
                let captchaToken = null;
                if (this.config.captchaEnabled && this.config.captchaProvider === 'recaptcha') {
                    captchaToken = await this.getCaptchaToken();
                    if (captchaToken === null) {
                        this.waitlistError = this.bookingError || this.config.messages?.captchaFailed || 'CAPTCHA verification failed. Please refresh the page and try again.';
                        this.bookingError = null;
                        this.waitlistLoading = false;
                        return;
                    }
                }

                const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                const csrfTokenValue = window.csrfTokenValue || '';

                const data = {
                    [csrfTokenName]: csrfTokenValue,
                    eventDateId: this.waitlistEventId,
                    userName: this.customerName,
                    userEmail: this.customerEmail,
                    userPhone: this.customerPhone || '',
                    notes: this.notes || '',
                    siteHandle: this.config.siteHandle || '',
                };
                if (captchaToken) {
                    data.captchaToken = captchaToken;
                }
                this.appendHoneypotData(data);

                try {
                    const params = new URLSearchParams(data);
                    const response = await fetch('/actions/booked/waitlist/join-event-waitlist', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: params,
                    });

                    const result = await response.json();
                    if (result.success) {
                        this.waitlistSuccess = true;
                        this.waitlistError = null;
                    } else {
                        this.waitlistError = result.message || result.error || 'Failed to join waitlist.';
                    }
                } catch (error) {
                    console.error('Event waitlist join error:', error);
                    this.waitlistError = 'An error occurred. Please try again.';
                } finally {
                    this.waitlistLoading = false;
                }
            },

            resetWizard() {
                if (this.softLockToken) {
                    this.releaseEventSoftLock(this.softLockToken);
                    this.softLockToken = null;
                }
                this.step = 1;
                this.eventDateId = null;
                this.selectedEvent = null;
                this.quantity = this.config.defaultQuantity ?? 1;
                this.notes = '';
                this.reservationDetails = null;
                this.bookingError = null;
                this._captchaWidgetId = null;
                this.waitlistEventId = null;
                this.waitlistSuccess = false;
                this.waitlistError = null;

                if (this.isLoggedIn) {
                    this.checkLoggedInUser();
                } else {
                    this.customerName = '';
                    this.customerEmail = '';
                    this.customerPhone = '';
                }

                // Refresh event dates
                this.fetchEventDates();
            }
        }));
    };

    if (window.Alpine) {
        initEventWizard();
    } else {
        document.addEventListener('alpine:init', initEventWizard);
    }
})();

/**
 * Booked Wizard Component (Alpine.js)
 *
 * Simplified model where working hours are stored directly on employees.
 *
 * Flow (totalSteps: 7 covers interactive steps 1-7; step 8 = success, shown when step > totalSteps):
 * Step 1: Select Service
 * Step 2: Select Extras (skipped if service has no extras)
 * Step 3: Select Location (skipped if 0 or 1 locations)
 * Step 4: Select Employee (always shown if employees exist, auto-selected if only 1)
 * Step 5: Select Date & Time
 * Step 6: Enter Customer Info
 * Step 7: Review & Confirm
 * Step 8: Success (displayed via step > totalSteps)
 *
 * Configuration options (passed via config parameter):
 * - requirePhone: boolean - Whether phone field is required (default: false)
 * - showNotes: boolean - Whether to show notes field (default: true)
 * - defaultQuantity: number - Default booking quantity (default: 1)
 */
(function() {
    let wizardRegistered = false;
    const initWizard = () => {
        if (!window.Alpine || wizardRegistered) return;
        wizardRegistered = true;

        Alpine.data('bookingWizard', (config = {}) => ({
            step: 1,
            totalSteps: 7,
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
            ...BookedWizardCommon.sharedMethods({ captchaContainerPrefix: 'booked' }),

            // Form Data
            serviceId: null,
            employeeId: null,
            locationId: null,
            date: null,
            time: null,
            quantity: config.defaultQuantity ?? 1,
            selectedSlot: null, // Track selected slot for quantity selection
            slotQuantity: 1, // Quantity for the selected slot
            softLockToken: null,
            _focusTrapHandler: null,

            // Waitlist state
            showWaitlistForm: false,
            joiningWaitlist: false,
            waitlistSuccess: false,
            waitlistError: null,
            waitlistAvailable: false, // Dynamic flag from API - true if waitlist can be shown for this service/date
            // Data lists
            services: [],
            employees: [],
            locations: [],
            extras: [],
            availableSlots: [],
            availabilityCalendar: {}, // Date availability map for Flatpickr
            flatpickrInstance: null, // Flatpickr datepicker instance
            calendarFetching: new Set(), // Tracks in-flight calendar fetches by month key
            prefetchedMonths: {}, // Tracks which year-month combos have been fetched
            slotCache: {}, // Caches slot data by date string (entries expire after SLOT_CACHE_TTL_MS)
            SLOT_CACHE_TTL_MS: 2 * 60 * 1000, // 2 minutes
            showAvailabilityIndicators: true, // Show colored dates from the very first render

            // Selected objects
            selectedService: null,
            selectedEmployee: null,
            selectedLocation: null,
            selectedExtras: {}, // { extraId: quantity }

            // Day-service state
            isDayService: false,
            isFlexibleDayService: false,
            serviceDuration: 0,
            endDate: null,
            availableStartDates: [],
            calendarMonth: null,
            // Flexible day-service state
            selectingEndDate: false,
            validEndDates: [],
            hoveredDate: null,
            flexMinDays: 1,
            flexMaxDays: 7,
            dayRangeCapacity: null, // null = not fetched or unconstrained
            dayRangeCapacityLoading: false,
            dayDatesFetching: new Set(),
            dayDatesCache: {},

            // Flow control
            employeeRequired: false,
            hasSchedules: false,
            hasExtras: false,
            skipEmployeeStep: false,
            skipLocationStep: false,
            skipExtrasStep: false,
            serviceHasSchedule: false, // True if service has its own availability (employee-less)

            init() {
                BookedWizardCommon.initLoadingWatcher(this);
                BookedWizardCommon.initStepAnnouncer(this, 7);

                // Watch for step changes to reinitialize datepicker
                this.$watch('step', (newStep, oldStep) => {
                    if (newStep === 5 && oldStep !== 5) {
                        this.$nextTick(() => { this.initDatePicker(); });
                    }
                });

                // Watch for waitlist form open/close to manage focus trap
                this.$watch('showWaitlistForm', (open) => {
                    if (open) {
                        this.$nextTick(() => {
                            this.trapFocus('[role="dialog"]');
                            const firstInput = this.$el.querySelector('[role="dialog"] input');
                            if (firstInput) firstInput.focus();
                        });
                    } else {
                        this.releaseFocusTrap();
                        // Return focus to "Join Waitlist" button
                        this.$nextTick(() => {
                            const joinBtn = this.$el.querySelector('.booked-waitlist-btn');
                            if (joinBtn) joinBtn.focus();
                        });
                    }
                });

                // Initialize with URL parameters if present
                const urlParams = new URLSearchParams(window.location.search);
                let deepLinkServiceId = null;
                let deepLinkLocationId = null;
                let deepLinkEmployeeId = undefined; // undefined = not specified, null = "any available"
                let deepLinkDate = null;
                let deepLinkTime = null;

                if (urlParams.has('serviceId')) {
                    const serviceId = parseInt(urlParams.get('serviceId'));
                    if (!isNaN(serviceId) && serviceId > 0) {
                        deepLinkServiceId = serviceId;
                    }
                }

                if (urlParams.has('locationId')) {
                    const locationId = parseInt(urlParams.get('locationId'));
                    if (!isNaN(locationId) && locationId > 0) {
                        deepLinkLocationId = locationId;
                    }
                }

                if (urlParams.has('employeeId')) {
                    const empId = urlParams.get('employeeId');
                    deepLinkEmployeeId = empId === 'null' ? null : (parseInt(empId) > 0 ? parseInt(empId) : null);
                }

                if (urlParams.has('date')) {
                    const rawDate = urlParams.get('date');
                    if (/^\d{4}-\d{2}-\d{2}$/.test(rawDate)) {
                        deepLinkDate = rawDate;
                    }
                }

                if (urlParams.has('time')) {
                    const rawTime = urlParams.get('time');
                    if (/^\d{2}:\d{2}$/.test(rawTime)) {
                        deepLinkTime = rawTime;
                    }
                }

                // Handle waitlist conversion token
                if (urlParams.has('waitlist')) {
                    this.handleWaitlistConversion(urlParams.get('waitlist'));
                    return; // Skip normal deep-link init — conversion handler takes over
                }

                // Fetch services first, then apply deep link params after data is loaded
                this.fetchServices().then(() => {
                    if (deepLinkServiceId) {
                        const service = this.services.find(s => s.id === deepLinkServiceId);
                        if (service) {
                            // selectService loads extras, employees, locations
                            this.selectService(service).then(async () => {
                                if (deepLinkLocationId) {
                                    this.locationId = deepLinkLocationId;
                                    const loc = this.locations.find(l => l.id === deepLinkLocationId);
                                    if (loc) this.selectedLocation = loc;
                                }

                                if (deepLinkEmployeeId !== undefined) {
                                    this.employeeId = deepLinkEmployeeId;
                                }

                                if (deepLinkDate) {
                                    this.date = deepLinkDate;
                                    this.fetchSlots();
                                    this.step = 5;
                                } else if (deepLinkLocationId || deepLinkEmployeeId !== undefined) {
                                    this.step = deepLinkEmployeeId !== undefined ? 5 : 4;
                                    this.initDateSelection();
                                } else {
                                    // Service selected, advance past service step
                                    this.step = this.skipExtrasStep ? 3 : 2;
                                }

                                if (deepLinkTime) {
                                    this.time = deepLinkTime;
                                    await this.createSoftLock();
                                    this.step = 6;
                                }
                            });
                        }
                    }
                });
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
                this.flatpickrInstance?.destroy();
                this.flatpickrInstance = null;
                this.releaseFocusTrap();
                if (this.softLockToken) {
                    this.releaseSoftLock(this.softLockToken);
                    this.softLockToken = null;
                }
                (this._prefetchTimers || []).forEach(clearTimeout);
                this._prefetchTimers = [];
                window.removeEventListener('beforeunload', this._beforeUnloadHandler);
            },

            requiresPayment() {
                return this.commerceEnabled && this.getTotalPrice() > 0;
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

                // Always fetch services so the wizard is usable
                await this.fetchServices();

                // Auto-select the waitlisted service if we have one
                if (result?.success && result.serviceId) {
                    const service = this.services.find(s => s.id === result.serviceId);
                    if (service) {
                        await this.selectService(service);
                        if (result.locationId) {
                            this.locationId = result.locationId;
                            const loc = this.locations.find(l => l.id === result.locationId);
                            if (loc) this.selectedLocation = loc;
                        }
                        if (result.employeeId) {
                            this.employeeId = result.employeeId;
                        }
                        if (result.preferredDate) {
                            this.date = result.preferredDate;
                            this.fetchSlots();
                            this.step = 5;
                        }
                    }
                }

                this.loading = false;
            },

            async fetchServices() {
                const requestLocationId = this.locationId;
                this.loading = true;
                try {
                    // Pass site handle to get services for the current site
                    const siteHandle = this.config.siteHandle || '';
                    const url = siteHandle
                        ? `/actions/booked/booking-data/get-services?site=${encodeURIComponent(siteHandle)}`
                        : '/actions/booked/booking-data/get-services';
                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = await response.json();
                    if (data.success && this.locationId === requestLocationId) {
                        this.services = data.services;
                    }
                } finally {
                    if (this.locationId === requestLocationId) {
                        this.loading = false;
                    }
                }
            },

            async fetchExtras() {
                if (!this.serviceId) return;
                const requestServiceId = this.serviceId;
                this.loading = true;
                try {
                    const response = await fetch(`/actions/booked/booking-data/get-service-extras?serviceId=${requestServiceId}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = await response.json();
                    if (data.success && this.serviceId === requestServiceId) {
                        this.extras = data.extras;
                        this.hasExtras = this.extras.length > 0;
                        this.skipExtrasStep = !this.hasExtras;

                        // Initialize selectedExtras with required extras set to 1
                        this.selectedExtras = {};
                        this.extras.forEach(extra => {
                            if (extra.isRequired) {
                                this.selectedExtras[extra.id] = 1;
                            }
                        });
                    }
                } finally {
                    if (this.serviceId === requestServiceId) {
                        this.loading = false;
                    }
                }
            },

            async fetchEmployees() {
                const requestServiceId = this.serviceId;
                this.loading = true;
                const params = new URLSearchParams();
                if (this.locationId) params.append('locationId', this.locationId);
                if (requestServiceId) params.append('serviceId', requestServiceId);

                try {
                    const response = await fetch(`/actions/booked/booking-data/get-employees?${params.toString()}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = await response.json();
                    if (data.success && this.serviceId === requestServiceId) {
                        this.employees = data.employees;
                        this.employeeRequired = data.employeeRequired;
                        this.hasSchedules = data.hasSchedules;
                        this.serviceHasSchedule = data.serviceHasSchedule || false;

                        // Handle locations from response (only relevant locations for this service)
                        if (data.locations) {
                            this.locations = data.locations;
                        } else {
                            this.locations = [];
                        }

                        // Skip location step if 0 or 1 locations available for this service
                        this.skipLocationStep = this.locations.length <= 1;

                        // Auto-select if exactly one location
                        if (this.locations.length === 1) {
                            this.locationId = this.locations[0].id;
                            this.selectedLocation = this.locations[0];
                        } else if (this.locations.length === 0) {
                            // No locations - clear any previous selection
                            this.locationId = null;
                            this.selectedLocation = null;
                        }

                        return data;
                    }
                } finally {
                    if (this.serviceId === requestServiceId) {
                        this.loading = false;
                    }
                }
                return null;
            },

            async fetchAvailableDates() {
                const currentMonth = this.calendarMonth || new Date().toISOString().slice(0, 7);
                const extrasDur = this.getExtrasDuration ? this.getExtrasDuration() : 0;
                const fetchKey = `${currentMonth}-s${this.serviceId ?? 'any'}-e${this.employeeId ?? 'any'}-l${this.locationId ?? 'any'}-q${this.quantity || 1}-x${extrasDur}`;

                if (Object.prototype.hasOwnProperty.call(this.dayDatesCache, fetchKey)) {
                    this.availableStartDates = this.dayDatesCache[fetchKey];
                    this.bookingError = null;
                    if (this.flatpickrInstance) this.flatpickrInstance.redraw();
                    return;
                }
                if (this.dayDatesFetching.has(fetchKey)) return;

                this.dayDatesFetching.add(fetchKey);
                this.loading = true;
                try {
                    const result = await window.BookedAvailability.getDates(currentMonth, {
                        serviceId: this.serviceId,
                        employeeId: this.employeeId,
                        locationId: this.locationId,
                        quantity: this.quantity,
                        extrasDuration: extrasDur,
                    });
                    const dates = result?.availableDates || [];
                    // Don't cache error responses so the user can retry.
                    if (!result?.error) {
                        this.dayDatesCache[fetchKey] = dates;
                    }
                    this.availableStartDates = dates;
                    if (result?.error) {
                        this.bookingError = result.error;
                        this.announce(result.error);
                    } else {
                        this.bookingError = null;
                    }
                    if (this.flatpickrInstance) this.flatpickrInstance.redraw();
                } catch (e) {
                    console.error('Failed to fetch available dates:', e);
                    this.availableStartDates = [];
                } finally {
                    this.dayDatesFetching.delete(fetchKey);
                    this.loading = false;
                }
            },

            async fetchDayRangeCapacity() {
                if (!this.isDayService || !this.date || !this.endDate || !this.serviceId) {
                    this.dayRangeCapacity = null;
                    return false;
                }
                // Snapshot selection so a stale fetch can't overwrite state or
                // advance the wizard if the user re-picks dates mid-flight.
                const reqStart = this.date;
                const reqEnd = this.endDate;
                const reqServiceId = this.serviceId;
                this.dayRangeCapacityLoading = true;
                try {
                    const result = await window.BookedAvailability.getRangeCapacity(reqStart, reqEnd, {
                        serviceId: reqServiceId,
                        employeeId: this.employeeId,
                        locationId: this.locationId,
                    });
                    if (this.date !== reqStart || this.endDate !== reqEnd || this.serviceId !== reqServiceId) {
                        return false;
                    }
                    const remaining = result?.remainingCapacity ?? null;
                    this.dayRangeCapacity = remaining;
                    if (typeof remaining === 'number') {
                        if (this.quantity > remaining) this.quantity = remaining;
                        if (this.quantity < 1) this.quantity = 1;
                    }
                    return true;
                } catch (e) {
                    console.error('Failed to fetch day-range capacity:', e);
                    if (this.date === reqStart && this.endDate === reqEnd && this.serviceId === reqServiceId) {
                        this.dayRangeCapacity = null;
                    }
                    return false;
                } finally {
                    this.dayRangeCapacityLoading = false;
                }
            },

            dayRangeCapacityNeedsPicker() {
                return typeof this.dayRangeCapacity === 'number' && this.dayRangeCapacity > 1;
            },

            incrementDayQuantity() {
                const max = typeof this.dayRangeCapacity === 'number' ? this.dayRangeCapacity : 99;
                if (this.quantity < max) this.quantity++;
            },

            decrementDayQuantity() {
                if (this.quantity > 1) this.quantity--;
            },

            validateDayQuantity() {
                const max = typeof this.dayRangeCapacity === 'number' ? this.dayRangeCapacity : 99;
                if (!Number.isFinite(this.quantity) || this.quantity < 1) this.quantity = 1;
                if (this.quantity > max) this.quantity = max;
            },

            async fetchValidEndDates(startDate) {
                try {
                    const result = await window.BookedAvailability.getValidEndDates(startDate, {
                        serviceId: this.serviceId,
                        employeeId: this.employeeId,
                        locationId: this.locationId,
                        quantity: this.quantity,
                    });
                    this.validEndDates = result.validEndDates || [];
                    this.flexMinDays = result.minDays || 1;
                    this.flexMaxDays = result.maxDays || 7;
                } catch (e) {
                    console.error('Failed to fetch valid end dates:', e);
                    this.validEndDates = [];
                }
            },

            async fetchSlots() {
                if (this.isDayService) {
                    return this.fetchAvailableDates();
                }
                if (!this.date) return;
                const requestDate = this.date;
                this.loading = true;
                try {
                    const data = await window.BookedAvailability.getSlots(requestDate, {
                        serviceId: this.serviceId,
                        employeeId: this.employeeId,
                        locationId: this.locationId,
                        quantity: this.quantity,
                        extrasDuration: this.getExtrasDuration()
                    });
                    if (data.success && this.date === requestDate) {
                        // Filter out slots with zero capacity (fully booked)
                        this.availableSlots = data.slots.filter(slot => {
                            const capacity = slot.availableCapacity;
                            // Keep slots where capacity is null (unlimited) or > 0
                            return capacity === null || capacity === undefined || capacity > 0;
                        });
                        // Update waitlist availability from API response
                        this.waitlistAvailable = data.waitlistAvailable ?? false;
                        // Announce slot count for screen readers
                        const count = this.availableSlots.length;
                        this.announce(count > 0 ? (this.config.messages?.slotsAvailableAnnounce || '{count} time slots available').replace('{count}', count) : (this.config.messages?.noSlotsAnnounce || 'No time slots available'));
                        this.scrollToTimeSlots();
                    }
                } finally {
                    if (this.date === requestDate) {
                        this.loading = false;
                    }
                }
            },

            async selectService(service) {
                this.serviceId = service.id;
                this.selectedService = service;
                // Detect day-based service and store duration
                this.isDayService = (service.durationType === 'days' || service.durationType === 'flexible_days');
                this.isFlexibleDayService = (service.durationType === 'flexible_days');
                this.serviceDuration = service.duration || 0;
                this.flexMinDays = service.minDays || 1;
                this.flexMaxDays = service.maxDays || 7;
                this.endDate = null;
                this.selectingEndDate = false;
                this.validEndDates = [];
                this.hoveredDate = null;
                this.availableStartDates = [];
                // Clear cached availability when service changes
                this.availabilityCalendar = {};
                this.slotCache = {};
                this.prefetchedMonths = {};
                this.dayDatesCache = {};

                // Fetch extras for this service
                await this.fetchExtras();

                // Fetch employees first - this also returns locations and determines skipLocationStep
                await this.fetchEmployees();

                if (this.hasExtras) {
                    this.step = 2; // Go to extras
                } else if (this.skipLocationStep) {
                    // Skip location step (employee-less service or 0-1 locations)
                    await this.proceedAfterLocation();
                } else {
                    // Go to location selection (step 3)
                    this.step = 3;
                }
            },

            // Extras management
            incrementExtra(extraId, maxQuantity) {
                const current = this.selectedExtras[extraId] || 0;
                if (maxQuantity && current >= maxQuantity) return;
                this.selectedExtras[extraId] = current + 1;
            },

            decrementExtra(extraId) {
                const current = this.selectedExtras[extraId] || 0;
                const extra = this.extras.find(e => e.id === extraId);
                // Don't go below 1 for required extras, 0 for optional
                const minQuantity = extra?.isRequired ? 1 : 0;
                if (current > minQuantity) {
                    this.selectedExtras[extraId] = current - 1;
                }
            },

            toggleExtra(extraId) {
                // Toggle between 0 and 1 for single-quantity extras
                const current = this.selectedExtras[extraId] || 0;
                this.selectedExtras[extraId] = current > 0 ? 0 : 1;
            },

            getExtrasTotal() {
                let total = 0;
                for (const [extraId, quantity] of Object.entries(this.selectedExtras)) {
                    const extra = this.extras.find(e => e.id === parseInt(extraId));
                    if (extra && quantity > 0) {
                        total += extra.price * quantity;
                    }
                }
                return total;
            },

            getExtrasDuration() {
                let total = 0;
                for (const [extraId, quantity] of Object.entries(this.selectedExtras)) {
                    const extra = this.extras.find(e => e.id === parseInt(extraId));
                    if (extra && quantity > 0) {
                        total += (extra.duration || 0) * quantity;
                    }
                }
                return total;
            },

            getServicePrice() {
                const basePrice = this.selectedService?.price || 0;
                // Per-unit day pricing: price × number of days
                if (this.isDayService && this.selectedService?.pricingMode === 'per_unit' && this.getDurationDays() > 0) {
                    return basePrice * this.getDurationDays();
                }
                return basePrice;
            },

            getTotalPrice() {
                const servicePrice = this.getServicePrice() * this.quantity;
                const extrasTotal = this.getExtrasTotal();
                return servicePrice + extrasTotal;
            },

            // Calculate end time based on start time and service duration
            calculateEndTime() {
                if (!this.time || !this.selectedService?.duration) return '';
                try {
                    const [hours, minutes] = this.time.split(':').map(Number);
                    const totalMinutes = hours * 60 + minutes + this.selectedService.duration + this.getExtrasDuration();
                    const endHours = Math.floor(totalMinutes / 60) % 24;
                    const endMinutes = totalMinutes % 60;
                    return String(endHours).padStart(2, '0') + ':' + String(endMinutes).padStart(2, '0');
                } catch (e) {
                    return '';
                }
            },

            /**
             * Format a YYYY-MM-DD date string for display using the site locale.
             */
            formatDisplayDate(dateStr) {
                if (!dateStr) return '';
                try {
                    const [year, month, day] = dateStr.split('-').map(Number);
                    const d = new Date(year, month - 1, day);
                    return d.toLocaleDateString(this.config.locale || undefined, {
                        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
                    });
                } catch (e) {
                    return dateStr;
                }
            },

            /**
             * Format a date range (startDate to endDate) for display.
             * Used in the review step for multi-day services.
             */
            formatDisplayDateRange(startDateStr, endDateStr) {
                if (!startDateStr) return '';
                const start = this.formatDisplayDate(startDateStr);
                if (!endDateStr || endDateStr === startDateStr) return start;
                const end = this.formatDisplayDate(endDateStr);
                return `${start} – ${end}`;
            },

            getDurationDays() {
                if (!this.date || !this.endDate) return 0;
                const start = new Date(this.date);
                const end = new Date(this.endDate);
                return Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
            },

            resetMultiDayState() {
                if (this.softLockToken) {
                    this.releaseSoftLock(this.softLockToken);
                    this.softLockToken = null;
                }
                this.date = null;
                this.endDate = null;
                this.selectingEndDate = false;
                this.validEndDates = [];
                this.hoveredDate = null;
                this.availableStartDates = [];
            },

            /**
             * Get the date/time display string for the review step.
             * Returns a date range for multi-day services, or date + time for regular services.
             */
            getReviewDateTimeDisplay() {
                if (this.isDayService) {
                    return this.formatDisplayDateRange(this.date, this.endDate);
                }
                const datePart = this.formatDisplayDate(this.date);
                const timePart = this.formatDisplayTime(this.time);
                return timePart ? `${datePart} ${timePart}` : datePart;
            },

            /**
             * Format a HH:MM time string for display using the site locale.
             */
            formatDisplayTime(timeStr) {
                if (!timeStr) return '';
                try {
                    const [hours, minutes] = timeStr.split(':').map(Number);
                    const d = new Date(2000, 0, 1, hours, minutes);
                    return d.toLocaleTimeString(this.config.locale || undefined, {
                        hour: 'numeric', minute: '2-digit'
                    });
                } catch (e) {
                    return timeStr;
                }
            },

            getSelectedExtrasCount() {
                return Object.values(this.selectedExtras).filter(q => q > 0).length;
            },

            getSelectedExtrasDetails() {
                const details = [];
                for (const [extraId, quantity] of Object.entries(this.selectedExtras)) {
                    if (quantity > 0) {
                        const extra = this.extras.find(e => e.id === parseInt(extraId));
                        if (extra) {
                            details.push({
                                id: extra.id,
                                title: extra.title,
                                quantity: quantity,
                                price: extra.price,
                                subtotal: extra.price * quantity
                            });
                        }
                    }
                }
                return details;
            },

            areRequiredExtrasSelected() {
                for (const extra of this.extras) {
                    if (extra.isRequired && (!this.selectedExtras[extra.id] || this.selectedExtras[extra.id] < 1)) {
                        return false;
                    }
                }
                return true;
            },

            async confirmExtras() {
                // Clear cached availability since extras duration affects slot generation
                this.availabilityCalendar = {};
                this.slotCache = {};
                this.prefetchedMonths = {};
                this.dayDatesCache = {};

                // Proceed from extras step
                // Employees were already fetched in selectService(), so we know skipLocationStep
                if (this.skipLocationStep) {
                    this.proceedAfterLocation();
                } else {
                    this.step = 3; // Go to location
                }
            },

            async selectLocation(location) {
                this.locationId = location.id;
                this.selectedLocation = location;
                // Clear employee selection and employees list when location changes
                this.employeeId = null;
                this.selectedEmployee = null;
                this.employees = []; // Clear employees array before fetching new ones
                this.skipEmployeeStep = false; // Reset skip flag
                // Clear cached availability and multi-day state when location changes
                this.availabilityCalendar = {};
                this.slotCache = {};
                this.prefetchedMonths = {};
                this.dayDatesCache = {};
                this.resetMultiDayState();
                await this.checkEmployeesAndProceed();
            },

            async checkEmployeesAndProceed() {
                // Fetch employees and determine next step
                const employeeData = await this.fetchEmployees();

                if (!employeeData) {
                    // Failed to fetch employees - show error
                    this.step = 4;
                    return;
                }

                // Get service schedule status from backend (most up-to-date)
                const serviceHasSchedule = employeeData.serviceHasSchedule === true;
                
                // Logic: If we have employees, ALWAYS show employee step (even if service has schedule)
                // Service schedule only allows skipping employee step when there are NO employees
                if (this.employees.length > 0) {
                    // Has employees - show employee selection step
                    if (this.employees.length === 1) {
                        this.employeeId = this.employees[0].id;
                        this.selectedEmployee = this.employees[0];
                    }
                    this.skipEmployeeStep = false;
                    this.step = 4;
                    return;
                }

                // No employees - check if we can proceed without them
                if (serviceHasSchedule) {
                    // Service has its own schedule and no employees - skip employee step
                    this.employeeId = null;
                    this.selectedEmployee = null;
                    this.skipEmployeeStep = true;
                    this.serviceHasSchedule = true;
                    this.initDateSelection(); // Go directly to date selection
                    return;
                }

                // No employees and no service schedule - show error
                this.step = 4;
            },

            // Proceed after location step (or when skipping location step)
            // Used when employees have already been fetched
            proceedAfterLocation() {
                // Logic: If we have employees, ALWAYS show employee step
                // Service schedule only allows skipping employee step when there are NO employees
                if (this.employees.length > 0) {
                    // Has employees - show employee selection step
                    if (this.employees.length === 1) {
                        this.employeeId = this.employees[0].id;
                        this.selectedEmployee = this.employees[0];
                    }
                    this.skipEmployeeStep = false;
                    this.step = 4;
                    return;
                }

                // No employees - check if we can proceed without them
                if (this.serviceHasSchedule) {
                    // Service has its own schedule and no employees - skip employee step
                    this.employeeId = null;
                    this.selectedEmployee = null;
                    this.skipEmployeeStep = true;
                    this.initDateSelection(); // Go directly to date selection
                    return;
                }

                // No employees and no service schedule - show error
                this.step = 4;
            },

            selectEmployee(employee) {
                this.employeeId = employee.id;
                this.selectedEmployee = employee;
                // Clear cached availability and multi-day state when employee changes
                this.availabilityCalendar = {};
                this.slotCache = {};
                this.prefetchedMonths = {};
                this.dayDatesCache = {};
                this.resetMultiDayState();
                this.initDateSelection();
            },

            skipEmployee() {
                // User chose "Any available" employee
                this.employeeId = null;
                this.selectedEmployee = null;
                // Clear cached availability and multi-day state when employee changes
                this.availabilityCalendar = {};
                this.slotCache = {};
                this.prefetchedMonths = {};
                this.dayDatesCache = {};
                this.resetMultiDayState();
                this.initDateSelection();
            },

            initDateSelection() {
                // Initialize date to today if not set
                if (!this.date) {
                    const today = new Date();
                    const year = today.getFullYear();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const day = String(today.getDate()).padStart(2, '0');
                    this.date = `${year}-${month}-${day}`;
                }
                this.step = 5; // Go to date selection
                // Fetch slots for the initial date
                this.fetchSlots();
            },

            /**
             * Initialize Flatpickr datepicker with availability highlighting
             */
            async initDatePicker() {
                const input = this.$refs.dateInput;
                if (!input || !window.flatpickr) {
                    console.warn('[BookingWizard] Flatpickr not available');
                    return;
                }

                // Destroy existing instance if any. Null the reference before
                // the async pre-fetch below so that fetchAvailableDates /
                // fetchAvailabilityCalendar don't try to redraw() a destroyed
                // instance (which throws "Cannot read properties of undefined
                // (reading 'noCalendar')" inside Flatpickr).
                if (this.flatpickrInstance) {
                    this.flatpickrInstance.destroy();
                    this.flatpickrInstance = null;
                }

                // Determine locale from config (set by Craft's app.language) or HTML lang
                const langCode = (this.config.locale || document.documentElement.lang || 'en').split('-')[0];
                // Build locale config: use l10n data if available, override firstDayOfWeek
                let localeConfig = { firstDayOfWeek: 1 };
                if (langCode !== 'en' && window.flatpickr && window.flatpickr.l10ns && window.flatpickr.l10ns[langCode]) {
                    localeConfig = Object.assign({}, window.flatpickr.l10ns[langCode], { firstDayOfWeek: 1 });
                }

                const self = this;
                // Calculate maxDate from maximumAdvanceBookingDays setting
                let maxDate = undefined;
                if (this.config.maximumAdvanceBookingDays > 0) {
                    const max = new Date();
                    max.setDate(max.getDate() + this.config.maximumAdvanceBookingDays);
                    maxDate = max;
                }

                // Pre-fetch availability for the initial month BEFORE creating the
                // flatpickr instance, so the very first onDayCreate pass has data to
                // style with. Without this, the first render paints all days neutral
                // (gray) and only gets colored once an async fetch redraws.
                this.showAvailabilityIndicators = true;
                const initialDate = this.date ? new Date(this.date) : new Date();
                const initYear = initialDate.getFullYear();
                const initMonth = initialDate.getMonth() + 1;
                this.calendarMonth = `${initYear}-${String(initMonth).padStart(2, '0')}`;
                try {
                    if (this.isDayService) {
                        await this.fetchAvailableDates();
                    } else {
                        await this.fetchAvailabilityCalendar(initYear, initMonth);
                    }
                } catch (e) {
                    console.warn('[BookingWizard] Initial availability fetch failed:', e);
                }

                this.flatpickrInstance = flatpickr(input, {
                    locale: localeConfig,
                    dateFormat: 'Y-m-d',
                    minDate: 'today',
                    maxDate: maxDate,
                    inline: true, // Show calendar inline for better UX
                    disableMobile: true, // Use Flatpickr on mobile too

                    // When a date is selected
                    onChange: (selectedDates, dateStr) => {
                        if (dateStr) {
                            self.selectDate(dateStr);
                        }
                    },

                    // When month/year changes, fetch new availability data
                    onMonthChange: (selectedDates, dateStr, instance) => {
                        const year = instance.currentYear;
                        const month = instance.currentMonth + 1; // 0-indexed
                        self.calendarMonth = `${year}-${String(month).padStart(2, '0')}`;
                        if (self.isDayService) {
                            self.fetchAvailableDates();
                        } else {
                            self.fetchAvailabilityCalendar(year, month);
                            self.prefetchAdjacentMonths(year, month);
                        }
                    },

                    onYearChange: (selectedDates, dateStr, instance) => {
                        const year = instance.currentYear;
                        const month = instance.currentMonth + 1;
                        self.calendarMonth = `${year}-${String(month).padStart(2, '0')}`;
                        if (self.isDayService) {
                            self.fetchAvailableDates();
                        } else {
                            self.fetchAvailabilityCalendar(year, month);
                            self.prefetchAdjacentMonths(year, month);
                        }
                    },

                    // Style each day based on availability
                    onDayCreate: (dpiDates, dStr, fp, dayElem) => {
                        // Remove old classes
                        dayElem.classList.remove('booked-available', 'booked-unavailable', 'booked-blacked-out', 'booked-loading');

                        // Only show availability indicators after user has selected a date
                        if (!self.showAvailabilityIndicators) {
                            return;
                        }

                        const dateStr = self.formatDateForCalendar(dayElem.dateObj);

                        // For day-based services, highlight available dates and range
                        if (self.isDayService) {
                            if (self.isFlexibleDayService && self.selectingEndDate && self.date) {
                                // End-date selection mode
                                const firstValidEnd = self.validEndDates.length > 0 ? self.validEndDates[0] : null;

                                if (dateStr === self.date) {
                                    dayElem.classList.add('booked-range-start');
                                } else if (self.validEndDates.includes(dateStr)) {
                                    dayElem.classList.add('booked-available');
                                    dayElem.setAttribute('title', self.config.messages?.available || 'Available');
                                } else if (dateStr > self.date && firstValidEnd && dateStr < firstValidEnd) {
                                    // Between start and first valid end = included in minimum range, not an error
                                    dayElem.classList.add('booked-in-range');
                                }
                                // Dates before start or after valid range: no styling (neutral)

                                // Highlight range between start and hovered/selected end
                                const rangeEnd = self.hoveredDate || self.endDate;
                                if (rangeEnd && dateStr > self.date && dateStr <= rangeEnd && self.validEndDates.includes(rangeEnd)) {
                                    dayElem.classList.add('booked-in-range');
                                }
                            } else if (self.isFlexibleDayService && self.date && self.endDate) {
                                // Range already selected: highlight the full range
                                if (dateStr >= self.date && dateStr <= self.endDate) {
                                    dayElem.classList.add('booked-in-range');
                                    if (dateStr === self.date) dayElem.classList.add('booked-range-start');
                                    if (dateStr === self.endDate) dayElem.classList.add('booked-range-end');
                                }
                            } else {
                                // Start date selection (fixed-day or flexible first click).
                                // Only apply styling once availableStartDates has loaded — otherwise
                                // every day flashes "Fully booked" before the fetch resolves.
                                if (self.availableStartDates.includes(dateStr)) {
                                    dayElem.classList.add('booked-available');
                                    dayElem.setAttribute('title', self.config.messages?.available || 'Available');
                                } else if (self.availableStartDates.length > 0) {
                                    dayElem.classList.add('booked-unavailable');
                                    dayElem.setAttribute('title', self.config.messages?.fullyBooked || 'Fully booked');
                                }
                            }
                            return;
                        }

                        const availability = self.availabilityCalendar[dateStr];

                        // Show availability status if we have data for this date
                        if (availability !== undefined) {
                            if (availability.isBlackedOut) {
                                dayElem.classList.add('booked-blacked-out');
                                dayElem.setAttribute('title', self.config.messages?.unavailable || 'Unavailable');
                            } else if (availability.hasAvailability) {
                                dayElem.classList.add('booked-available');
                                dayElem.setAttribute('title', self.config.messages?.available || 'Available');
                            } else {
                                dayElem.classList.add('booked-unavailable');
                                dayElem.setAttribute('title', self.config.messages?.fullyBooked || 'Fully booked');
                            }
                        } else if (self.calendarFetching.size > 0) {
                            // Show loading state while fetching
                            dayElem.classList.add('booked-loading');
                        }
                    },

                    // When calendar is ready
                    onReady: (selectedDates, dateStr, instance) => {
                        // Make calendar keyboard-accessible: add tabindex so users
                        // can Tab into it, then use arrow keys + Enter (Flatpickr built-in)
                        if (instance.calendarContainer) {
                            instance.calendarContainer.setAttribute('tabindex', '0');
                            instance.calendarContainer.setAttribute('role', 'application');
                            instance.calendarContainer.setAttribute('aria-label', self.config.stepAnnouncements?.['5'] || 'Date picker');
                        }

                        // If date is already set (e.g., navigating back), select it
                        if (self.date) {
                            instance.setDate(self.date, false);
                        }

                        // Initial availability fetch already ran in initDatePicker()
                        // before this instance was created, so onDayCreate has
                        // already painted the right classes. Only schedule
                        // adjacent-month / slot prefetches here.
                        self.showAvailabilityIndicators = true;
                        const year = instance.currentYear;
                        const month = instance.currentMonth + 1;
                        if (!self.isDayService) {
                            setTimeout(() => {
                                if (self.step === 5 && self.flatpickrInstance) {
                                    self.prefetchAdjacentMonths(year, month);
                                    self.prefetchLikelySlots();
                                }
                            }, 500);
                        }

                        // Add hover tracking for flexible day range preview
                        if (self.isFlexibleDayService && instance.calendarContainer) {
                            instance.calendarContainer.addEventListener('mouseover', function(e) {
                                const dayElem = e.target.closest('.flatpickr-day');
                                if (!dayElem || !self.selectingEndDate) return;
                                const dateStr = self.formatDateForCalendar(dayElem.dateObj);
                                if (dateStr !== self.hoveredDate) {
                                    self.hoveredDate = dateStr;
                                    instance.redraw();
                                }
                            });
                            instance.calendarContainer.addEventListener('mouseleave', function() {
                                if (self.selectingEndDate && self.hoveredDate) {
                                    self.hoveredDate = null;
                                    instance.redraw();
                                }
                            });
                        }
                    }
                });
            },

            /**
             * Format a Date object to YYYY-MM-DD string
             */
            formatDateForCalendar(dateObj) {
                const year = dateObj.getFullYear();
                const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                const day = String(dateObj.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            },

            /**
             * Fetch availability calendar for a specific month
             */
            async fetchAvailabilityCalendar(year, month) {
                // Prevent duplicate fetches for the same month
                const fetchKey = `${year}-${month}-s${this.serviceId ?? 'any'}-e${this.employeeId ?? 'any'}-l${this.locationId ?? 'any'}-q${this.quantity || 1}-x${this.getExtrasDuration()}`;
                if (this.calendarFetching.has(fetchKey)) {
                    return;
                }

                // Snapshot current selection state to detect staleness
                const requestServiceId = this.serviceId;
                const requestEmployeeId = this.employeeId;
                const requestLocationId = this.locationId;

                // Calculate date range for the month (with padding for calendar display)
                const startDate = new Date(year, month - 1, 1);
                const endDate = new Date(year, month, 0); // Last day of month

                // Add padding for calendar display (prev/next month days)
                startDate.setDate(startDate.getDate() - 7);
                endDate.setDate(endDate.getDate() + 7);

                const startStr = this.formatDateForCalendar(startDate);
                const endStr = this.formatDateForCalendar(endDate);

                this.calendarFetching.add(fetchKey);

                try {
                    const params = new URLSearchParams({
                        startDate: startStr,
                        endDate: endStr
                    });

                    if (requestServiceId) params.append('serviceId', requestServiceId);
                    if (requestEmployeeId) params.append('employeeId', requestEmployeeId);
                    if (requestLocationId) params.append('locationId', requestLocationId);
                    if (this.quantity > 1) params.append('quantity', this.quantity);
                    const extrasDur = this.getExtrasDuration();
                    if (extrasDur > 0) params.append('extrasDuration', extrasDur);

                    const response = await fetch(`/actions/booked/slot/get-availability-calendar?${params.toString()}`, {
                        headers: { 'Accept': 'application/json' }
                    });

                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = await response.json();

                    // Guard: only apply if selections haven't changed during the fetch
                    if (data.success && data.calendar
                        && this.serviceId === requestServiceId
                        && this.employeeId === requestEmployeeId
                        && this.locationId === requestLocationId) {
                        // Merge new calendar data with existing
                        this.availabilityCalendar = {
                            ...this.availabilityCalendar,
                            ...data.calendar
                        };

                        // Redraw the calendar to apply new styles
                        if (this.flatpickrInstance) {
                            this.flatpickrInstance.redraw();
                        }
                    }
                } catch (error) {
                    console.error('[BookingWizard] Failed to fetch availability calendar:', error);
                } finally {
                    this.calendarFetching.delete(fetchKey);
                }
            },

            /**
             * Prefetch adjacent months' calendar data using idle callbacks
             */
            prefetchAdjacentMonths(year, month) {
                const prefetch = (y, m) => {
                    const key = `${y}-${m}`;
                    if (this.prefetchedMonths[key]) return;
                    this.prefetchedMonths[key] = true;

                    const doFetch = () => this.fetchAvailabilityCalendar(y, m);

                    if ('requestIdleCallback' in window) {
                        requestIdleCallback(doFetch, { timeout: 2000 });
                    } else {
                        setTimeout(doFetch, 100);
                    }
                };

                // Next month
                const next = month === 12 ? { y: year + 1, m: 1 } : { y: year, m: month + 1 };
                prefetch(next.y, next.m);

                // Previous month (only if not in the past)
                const now = new Date();
                if (year > now.getFullYear() || (year === now.getFullYear() && month > now.getMonth() + 1)) {
                    const prev = month === 1 ? { y: year - 1, m: 12 } : { y: year, m: month - 1 };
                    prefetch(prev.y, prev.m);
                }
            },

            /**
             * Prefetch time slots for the next 3 available-looking dates
             */
            prefetchLikelySlots() {
                const today = new Date();
                const prefetchDates = [];

                for (let i = 0; i < 14 && prefetchDates.length < 3; i++) {
                    const d = new Date(today);
                    d.setDate(d.getDate() + i);
                    const dateStr = this.formatDateForCalendar(d);

                    const avail = this.availabilityCalendar[dateStr];
                    if (avail?.isBlackedOut || avail?.hasAvailability === false) continue;
                    const entry = this.slotCache[dateStr];
                    if (entry && (Date.now() - entry.cachedAt) < this.SLOT_CACHE_TTL_MS) continue;

                    prefetchDates.push(dateStr);
                }

                this._prefetchTimers = this._prefetchTimers || [];
                prefetchDates.forEach((dateStr, index) => {
                    const id = setTimeout(() => this.prefetchSlotsForDate(dateStr), index * 200);
                    this._prefetchTimers.push(id);
                });
            },

            /**
             * Prefetch slots for a single date (silent, non-blocking)
             */
            async prefetchSlotsForDate(dateStr) {
                const entry = this.slotCache[dateStr];
                if ((entry && (Date.now() - entry.cachedAt) < this.SLOT_CACHE_TTL_MS) || this.date === dateStr) return;
                try {
                    const data = await window.BookedAvailability.getSlots(dateStr, {
                        serviceId: this.serviceId,
                        employeeId: this.employeeId,
                        locationId: this.locationId,
                        quantity: this.quantity,
                        extrasDuration: this.getExtrasDuration()
                    });
                    if (data.success && data.slots) {
                        this.slotCache[dateStr] = {
                            slots: data.slots.filter(s => s.availableCapacity === null || s.availableCapacity === undefined || s.availableCapacity > 0),
                            waitlistAvailable: data.waitlistAvailable ?? false,
                            cachedAt: Date.now(),
                        };
                    }
                } catch (e) { /* silent fail for prefetch */ }
            },

            selectDate(date) {
                // Fixed-duration day service: compute end date and advance
                if (this.isDayService && !this.isFlexibleDayService) {
                    // Reject clicks on unavailable start dates so users don't
                    // accidentally advance to the next step with a bad date.
                    if (this.availableStartDates.length > 0 && !this.availableStartDates.includes(date)) {
                        if (this.flatpickrInstance) {
                            this.flatpickrInstance.clear();
                            this.flatpickrInstance.redraw();
                        }
                        return;
                    }
                    this.date = date;
                    const duration = Math.max(1, this.serviceDuration);
                    // Use UTC to avoid timezone shift issues with toISOString()
                    const parts = date.split('-');
                    const start = new Date(Date.UTC(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2])));
                    const end = new Date(start);
                    end.setUTCDate(end.getUTCDate() + duration - 1);
                    this.endDate = end.toISOString().slice(0, 10);
                    this.time = null;
                    const fixedReqStart = this.date;
                    const fixedReqEnd = this.endDate;
                    this.fetchDayRangeCapacity().then((fresh) => {
                        if (!fresh) return;
                        if (this.date !== fixedReqStart || this.endDate !== fixedReqEnd) return;
                        if (!this.dayRangeCapacityNeedsPicker()) {
                            this.nextStep();
                        }
                    });
                    return;
                }

                // Flexible day service: two-click selection
                if (this.isFlexibleDayService) {
                    // Clicking the currently selected start date unselects it
                    // and returns to start-date selection mode. This is the
                    // only escape hatch users have when they've picked a start
                    // date they don't want. Preserve the current month view so
                    // the calendar doesn't jump back to today.
                    if (this.selectingEndDate && date === this.date) {
                        this.date = null;
                        this.endDate = null;
                        this.selectingEndDate = false;
                        this.validEndDates = [];
                        this.hoveredDate = null;
                        this.dayRangeCapacity = null;
                        if (this.flatpickrInstance) {
                            const currentYear = this.flatpickrInstance.currentYear;
                            const currentMonth = this.flatpickrInstance.currentMonth;
                            this.flatpickrInstance.clear();
                            this.flatpickrInstance.changeMonth(currentMonth, false);
                            this.flatpickrInstance.currentYear = currentYear;
                            this.flatpickrInstance.redraw();
                        }
                        this.announce(this.config.messages?.pickStartDateHint || 'Pick a start date');
                        return;
                    }

                    if (this.selectingEndDate && date < this.date) {
                        // Clicked before the start date: treat as re-picking a new earlier start
                        this.selectingEndDate = false;
                        this.validEndDates = [];
                        this.hoveredDate = null;
                        this.endDate = null;
                    }

                    if (!this.selectingEndDate) {
                        // Reject clicks on dates that aren't valid start dates
                        if (this.availableStartDates.length > 0 && !this.availableStartDates.includes(date)) {
                            if (this.flatpickrInstance) {
                                this.flatpickrInstance.clear();
                                this.flatpickrInstance.redraw();
                            }
                            return;
                        }
                        // Select start date
                        this.date = date;
                        this.endDate = null;
                        this.selectingEndDate = true;
                        this.dayRangeCapacity = null;
                        this.loading = true;
                        this.announce(this.config.messages?.selectEndDate || 'Select your end date');
                        // Clear Flatpickr's internal selected state so it doesn't override our styling
                        // Preserve the current month view to avoid jumping back
                        if (this.flatpickrInstance) {
                            const currentYear = this.flatpickrInstance.currentYear;
                            const currentMonth = this.flatpickrInstance.currentMonth;
                            this.flatpickrInstance.clear();
                            this.flatpickrInstance.changeMonth(currentMonth, false);
                            this.flatpickrInstance.currentYear = currentYear;
                            this.flatpickrInstance.redraw();
                        }
                        this.fetchValidEndDates(date).then(() => {
                            this.loading = false;
                            if (this.flatpickrInstance) {
                                // redraw() alone doesn't re-run onDayCreate, so the new
                                // validEndDates don't paint. changeMonth(current, false)
                                // forces a full day-cell rebuild without navigating.
                                const m = this.flatpickrInstance.currentMonth;
                                this.flatpickrInstance.changeMonth(m, false);
                            }
                        });
                        return;
                    } else {
                        // Select end date
                        if (!this.validEndDates.includes(date)) {
                            return; // Invalid end date, ignore
                        }
                        this.endDate = date;
                        this.selectingEndDate = false;
                        this.hoveredDate = null;
                        this.time = null;
                        if (this.flatpickrInstance) {
                            this.flatpickrInstance.redraw();
                        }
                        const flexReqStart = this.date;
                        const flexReqEnd = this.endDate;
                        this.fetchDayRangeCapacity().then((fresh) => {
                            if (!fresh) return;
                            if (this.date !== flexReqStart || this.endDate !== flexReqEnd) return;
                            if (!this.dayRangeCapacityNeedsPicker()) {
                                this.nextStep();
                            }
                        });
                        return;
                    }
                }

                this.date = date;
                // Show loading immediately and clear old slots
                this.loading = true;
                this.availableSlots = [];
                this.announce(this.config.messages?.loadingTimesAnnounce || 'Loading available times...');
                this.selectedSlot = null;
                // Release and clear existing lock when date changes
                if (this.softLockToken) {
                    this.releaseSoftLock(this.softLockToken);
                    this.softLockToken = null;
                }

                // After first date selection, enable availability indicators and fetch calendar
                if (!this.showAvailabilityIndicators && this.flatpickrInstance) {
                    this.showAvailabilityIndicators = true;
                    const year = this.flatpickrInstance.currentYear;
                    const month = this.flatpickrInstance.currentMonth + 1;
                    this.fetchAvailabilityCalendar(year, month);
                }

                // Check slot cache first (with TTL)
                const cached = this.slotCache[date];
                if (cached && (Date.now() - cached.cachedAt) < this.SLOT_CACHE_TTL_MS) {
                    this.availableSlots = cached.slots;
                    this.waitlistAvailable = cached.waitlistAvailable;
                    this.loading = false;
                    this.scrollToTimeSlots();
                    return;
                }
                // Expired or missing — remove stale entry
                delete this.slotCache[date];
                this.fetchSlots();
            },

            async selectSlot(slot) {
                // Store selected slot for quantity selection
                this.selectedSlot = slot;
                this.time = slot.time;
                
                this.slotQuantity = 1;
                
                // Only set employeeId if a specific employee was selected
                // If "Any available" was selected (employeeId is null), keep it null
                // The employee will be assigned when the booking is created
                if (slot.employeeId && this.employeeId !== null) {
                    // Specific employee was selected, use the slot's employee
                    this.employeeId = slot.employeeId;
                    // Find the employee object and set selectedEmployee for display
                    const employee = this.employees.find(e => e.id === slot.employeeId);
                    if (employee) {
                        this.selectedEmployee = employee;
                    }
                }
                // If this.employeeId is null (Any available), keep it null and don't set it from slot
                
                // For 1-on-1 slots (capacity = 1 or null), lock immediately and proceed
                // For capacity-based slots, wait for user to confirm quantity
                if (slot.availableCapacity === null || slot.availableCapacity <= 1) {
                    await this.createSoftLock();
                    this.nextStep();
                }
                // Otherwise, slot is selected but quantity selector is shown
                // User clicks "Next" or continues to proceed
            },

            getCapacityText(slot) {
                if (slot.availableCapacity === null || slot.availableCapacity === undefined) {
                    return this.config.messages?.capacityOpen || 'Open';
                }
                if (slot.availableCapacity === 0) {
                    return this.config.messages?.capacityFull || 'Full';
                }
                if (slot.availableCapacity === 1) {
                    return '';
                }
                return slot.availableCapacity + ' ' + (this.config.messages?.capacityAvailable || 'available');
            },

            incrementSlotQuantity(slot) {
                const max = slot.availableCapacity || 99;
                if (this.slotQuantity < max) {
                    this.slotQuantity++;
                    this.slotCache = {};
                    this.validateSlotQuantity(slot);
                }
            },

            decrementSlotQuantity(slot) {
                if (this.slotQuantity > 1) {
                    this.slotQuantity--;
                    this.slotCache = {};
                }
            },

            validateSlotQuantity(slot) {
                const max = slot.availableCapacity || 99;
                if (this.slotQuantity > max) {
                    this.slotQuantity = max;
                }
                if (this.slotQuantity < 1) {
                    this.slotQuantity = 1;
                }
                // Update the quantity for booking
                this.quantity = this.slotQuantity;
            },

            async createSoftLock() {
                // For day-services, require date + endDate instead of time
                if (this.isDayService) {
                    if (!this.date || !this.endDate || !this.serviceId) {
                        return;
                    }
                    return this.createMultiDayLock();
                }
                if (!this.date || !this.time || !this.serviceId) {
                    return;
                }

                try {
                    const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                    const csrfTokenValue = window.csrfTokenValue || '';

                    const formData = new URLSearchParams();
                    formData.append(csrfTokenName, csrfTokenValue);
                    formData.append('date', this.date);
                    formData.append('startTime', this.time);
                    formData.append('serviceId', this.serviceId);
                    if (this.employeeId) {
                        formData.append('employeeId', this.employeeId);
                    }
                    if (this.locationId) {
                        formData.append('locationId', this.locationId);
                    }
                    const extrasDur = this.getExtrasDuration();
                    if (extrasDur > 0) {
                        formData.append('extrasDuration', extrasDur);
                    }
                    if (this.slotQuantity > 1) {
                        formData.append('quantity', this.slotQuantity);
                    }

                    const response = await fetch('/actions/booked/slot/create-lock', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/json'
                        },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const result = await response.json();

                    if (result.success) {
                        this.softLockToken = result.token;
                    } else {
                        // Lock failed - slot might be taken, but continue anyway
                        // The booking will fail at submission time if truly unavailable
                        console.warn('Failed to create soft lock:', result.message);
                        this.softLockToken = null;
                    }
                } catch (error) {
                    console.error('Error creating soft lock:', error);
                    // Continue anyway - lock is not critical for booking
                    this.softLockToken = null;
                }
            },

            async createMultiDayLock() {
                try {
                    const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                    const csrfTokenValue = window.csrfTokenValue || '';

                    const formData = new URLSearchParams();
                    formData.append(csrfTokenName, csrfTokenValue);
                    formData.append('date', this.date);
                    formData.append('endDate', this.endDate);
                    formData.append('serviceId', this.serviceId);
                    if (this.employeeId) {
                        formData.append('employeeId', this.employeeId);
                    }
                    if (this.locationId) {
                        formData.append('locationId', this.locationId);
                    }
                    if (this.slotQuantity > 1) {
                        formData.append('quantity', this.slotQuantity);
                    }

                    const response = await fetch('/actions/booked/slot/create-multi-day-lock', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/json'
                        },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const result = await response.json();

                    if (result.success) {
                        this.softLockToken = result.token;
                    } else {
                        console.warn('Failed to create multi-day soft lock:', result.message);
                        this.softLockToken = null;
                    }
                } catch (error) {
                    console.error('Error creating multi-day soft lock:', error);
                    this.softLockToken = null;
                }
            },

            /**
             * Release a soft lock when user goes back or cancels
             */
            async releaseSoftLock(token) {
                if (!token) {
                    return;
                }

                try {
                    const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                    const csrfTokenValue = window.csrfTokenValue || '';

                    const formData = new URLSearchParams();
                    formData.append(csrfTokenName, csrfTokenValue);
                    formData.append('token', token);

                    const response = await fetch('/actions/booked/slot/release-lock', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/json'
                        },
                        body: formData.toString()
                    });
                    if (!response.ok) {
                        console.warn('Failed to release soft lock:', response.status);
                    }
                } catch (error) {
                    console.warn('Failed to release soft lock:', error);
                    // Not critical - lock will expire naturally
                }
            },

            async nextStep() {
                // If on step 5 (date/time) and a slot with capacity > 1 is selected but quantity not confirmed
                if (this.step === 5 && this.selectedSlot) {
                    // Always sync quantity from slotQuantity when leaving step 5
                    this.quantity = this.slotQuantity;
                }
                
                if (this.step < this.totalSteps) {
                    // If moving from step 5 (date/time) to step 6 (customer info), create lock if not already held
                    if (this.step === 5 && !this.softLockToken) {
                        await this.createSoftLock();
                    }
                    this.step++;
                    
                    // If we land on step 4 (employee selection) but it should be skipped, skip to step 5
                    if (this.step === 4 && this.shouldSkipEmployeeStep()) {
                        this.step = 5;
                        this.$nextTick(() => {
                            const stepEl = document.querySelector('[x-show*="step === 5"], [x-show*="step == 5"]');
                            if (stepEl) {
                                const focusable = stepEl.querySelector('input, select, button, [tabindex]');
                                if (focusable) focusable.focus();
                            }
                        });
                    }
                }
            },

            /**
             * Determine if employee step should be skipped based on current state
             * This is a computed check based on the actual conditions, not just the flag
             */
            shouldSkipEmployeeStep() {
                // Service has its own schedule and no employees needed
                if (this.serviceHasSchedule && this.employees.length === 0) {
                    return true;
                }
                // Always show employee step if there are employees (even if only one)
                // This allows users to see and confirm which employee they're booking with
                return false;
            },
            
            /**
             * Get the previous valid step based on current step and skip conditions
             * This ensures navigation respects all skip logic and is bulletproof
             */
            getPreviousStep() {
                const currentStep = this.step;
                
                // Step 7 (Review) -> Step 6 (Customer Info)
                if (currentStep === 7) {
                    return 6;
                }
                
                // Step 6 (Customer Info) -> Step 5 (Date/Time)
                if (currentStep === 6) {
                    // Release the lock when going back so user can re-select slots
                    if (this.softLockToken) {
                        this.releaseSoftLock(this.softLockToken);
                    }
                    this.softLockToken = null;
                    // Reset selected slot so user can choose a different one
                    this.selectedSlot = null;
                    this.slotQuantity = 1;
                    this.quantity = this.config.defaultQuantity ?? 1;
                    return 5;
                }
                
                // Step 5 (Date/Time) -> Determine based on skip conditions
                if (currentStep === 5) {
                    // Check if employee step was actually skipped using computed logic
                    const employeeStepSkipped = this.shouldSkipEmployeeStep();
                    
                    if (employeeStepSkipped) {
                        // Employee step was skipped, go back to location or extras/service
                        if (this.skipLocationStep) {
                            // Location was also skipped
                            if (this.hasExtras) {
                                return 2; // Back to extras
                            } else {
                                return 1; // Back to service
                            }
                        } else {
                            return 3; // Back to location
                        }
                    } else {
                        // Employee step was shown (multiple employees), go back to it
                        return 4;
                    }
                }
                
                // Step 4 (Employee) -> Determine based on skip conditions
                // Note: This step is only shown if there are multiple employees
                if (currentStep === 4) {
                    if (this.skipLocationStep) {
                        if (this.hasExtras) {
                            return 2; // Back to extras
                        } else {
                            return 1; // Back to service
                        }
                    } else {
                        return 3; // Back to location
                    }
                }
                
                // Step 3 (Location) -> Determine based on skip conditions
                // Note: This step is only shown if skipLocationStep is false
                if (currentStep === 3) {
                    if (this.hasExtras) {
                        return 2; // Back to extras
                    } else {
                        return 1; // Back to service
                    }
                }
                
                // Step 2 (Extras) -> Step 1 (Service)
                if (currentStep === 2) {
                    return 1;
                }
                
                // Step 1 (Service) -> Can't go back further
                if (currentStep === 1) {
                    return 1;
                }
                
                // Fallback: just decrement (should never reach here)
                return Math.max(1, currentStep - 1);
            },
            
            prevStep() {
                if (this.step > 1) {
                    this.bookingError = null;
                    this.step = this.getPreviousStep();
                }
            },

            async submitBooking() {
                if (this.loading) return;

                if (!this.isCustomerInfoValid()) {
                    this.bookingError = this.config.messages?.customerInfoRequired || 'Please complete all required fields.';
                    return;
                }

                this.loading = true;

                // Prepare extras in the format expected by the backend: { extraId: quantity }
                const extrasForSubmit = {};
                for (const [extraId, quantity] of Object.entries(this.selectedExtras)) {
                    if (quantity > 0) {
                        extrasForSubmit[extraId] = quantity;
                    }
                }

                const captchaToken = await this.getCaptchaToken();
                if (this.config.captchaEnabled && captchaToken === null) {
                    this.loading = false;
                    return;
                }

                const data = {
                    serviceId: this.serviceId,
                    employeeId: this.employeeId,
                    locationId: this.locationId,
                    date: this.date,
                    time: this.time,
                    quantity: this.quantity,
                    customerName: this.customerName,
                    customerEmail: this.customerEmail,
                    customerPhone: this.customerPhone,
                    notes: this.notes,
                    extras: extrasForSubmit,
                    softLockToken: this.softLockToken,
                    captchaToken,
                    addToCart: this.addToCartOnly ? '1' : '0',
                    siteHandle: this.config.siteHandle || ''
                };

                if (this.isDayService && this.endDate) {
                    data.endDate = this.endDate;
                    // Don't send time for multi-day bookings
                    delete data.time;
                }

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

            // Join waitlist when no slots are available
            async joinWaitlist() {
                if (!this.customerName || !this.customerEmail) {
                    this.waitlistError = this.config.messages?.waitlistNameEmailRequired || 'Please enter your name and email.';
                    return;
                }

                this.joiningWaitlist = true;
                this.waitlistError = null;

                // For interactive CAPTCHAs (hCaptcha/Turnstile), the widget isn't rendered
                // on the waitlist step — only on the review step. Use token if available
                // (e.g. reCAPTCHA v3 which is invisible), but don't block on missing widget.
                let captchaToken = null;
                if (this.config.captchaEnabled && this.config.captchaProvider === 'recaptcha') {
                    captchaToken = await this.getCaptchaToken();
                    if (captchaToken === null) {
                        this.waitlistError = this.bookingError || this.config.messages?.captchaFailed || 'CAPTCHA verification failed. Please refresh the page and try again.';
                        this.bookingError = null;
                        this.joiningWaitlist = false;
                        return;
                    }
                }

                const data = {
                    serviceId: this.serviceId,
                    employeeId: this.employeeId,
                    locationId: this.locationId,
                    preferredDate: this.date,
                    userName: this.customerName,
                    userEmail: this.customerEmail,
                    userPhone: this.customerPhone,
                    captchaToken
                };
                this.appendHoneypotData(data);

                try {
                    const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
                    const csrfTokenValue = window.csrfTokenValue || '';

                    const siteHandle = this.config.siteHandle || '';
                    const waitlistUrl = siteHandle
                        ? `/actions/booked/waitlist/join-waitlist?site=${encodeURIComponent(siteHandle)}`
                        : '/actions/booked/waitlist/join-waitlist';

                    const formData = new URLSearchParams();
                    formData.append(csrfTokenName, csrfTokenValue);
                    Object.entries(data).forEach(([key, value]) => {
                        if (value !== null && value !== undefined) {
                            formData.append(key, value);
                        }
                    });

                    const response = await fetch(waitlistUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                        },
                        body: formData
                    });

                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const result = await response.json();

                    if (result.success) {
                        this.waitlistSuccess = true;
                        this.showWaitlistForm = false;
                        this.announce(this.config.messages?.waitlistJoinedAnnounce || 'Successfully joined waitlist');
                    } else {
                        this.waitlistError = result.message || this.config.messages?.waitlistJoinError || 'Failed to join waitlist. Please try again.';
                        this.announce(this.waitlistError);
                    }
                } catch (error) {
                    console.error('Waitlist join error:', error);
                    this.waitlistError = this.config.messages?.waitlistJoinFailed || 'An error occurred. Please try again.';
                    this.announce(this.waitlistError);
                } finally {
                    this.joiningWaitlist = false;
                }
            },

            // Reset just the waitlist form (after success, to allow selecting a different date)
            resetWaitlistForm() {
                this.showWaitlistForm = false;
                this.joiningWaitlist = false;
                this.waitlistSuccess = false;
                this.waitlistError = null;
            },

            scrollToTimeSlots() {
                this.$nextTick(() => {
                    const section = this.$el.querySelector('[class$="-time-section"]');
                    if (section) {
                        section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });
            },

            // Accessibility: trap Tab key within a container (for dialogs)
            trapFocus(selector) {
                const container = this.$el.querySelector(selector);
                if (!container) return;

                this._focusTrapHandler = (e) => {
                    if (e.key !== 'Tab') return;
                    const focusable = container.querySelectorAll(
                        'input:not([disabled]), button:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
                    );
                    if (focusable.length === 0) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                };
                document.addEventListener('keydown', this._focusTrapHandler);
            },

            // Accessibility: remove focus trap
            releaseFocusTrap() {
                if (this._focusTrapHandler) {
                    document.removeEventListener('keydown', this._focusTrapHandler);
                    this._focusTrapHandler = null;
                }
            },

            resetWizard() {
                // Release any active soft lock before resetting
                if (this.softLockToken) {
                    this.releaseSoftLock(this.softLockToken);
                    this.softLockToken = null;
                }

                // Reset all form data
                this.step = 1;
                this.serviceId = null;
                this.employeeId = null;
                this.locationId = null;
                this.date = null;
                this.time = null;
                this.quantity = this.config.defaultQuantity;
                this.selectedSlot = null;
                this.slotQuantity = 1;
                this.notes = '';
                this.selectedService = null;
                this.selectedEmployee = null;
                this.selectedLocation = null;
                this.selectedExtras = {};
                this.extras = [];
                this.hasExtras = false;
                this.skipExtrasStep = false;
                this.reservationDetails = null;
                this.employeeRequired = false;
                this.hasSchedules = false;
                this.skipEmployeeStep = false;
                this.serviceHasSchedule = false;
                this.isDayService = false;
                this.isFlexibleDayService = false;
                this.serviceDuration = 0;
                this.endDate = null;
                this.selectingEndDate = false;
                this.validEndDates = [];
                this.hoveredDate = null;
                this.flexMinDays = 1;
                this.flexMaxDays = 7;
                this.availableStartDates = [];

                // Reset waitlist state
                this.showWaitlistForm = false;
                this.joiningWaitlist = false;
                this.waitlistSuccess = false;
                this.waitlistError = null;

                // Reset Flatpickr state
                this.availabilityCalendar = {};
                this.prefetchedMonths = {};
                this.dayDatesCache = {};
                this.slotCache = {};
                this.showAvailabilityIndicators = false;
                if (this.flatpickrInstance) {
                    this.flatpickrInstance.destroy();
                    this.flatpickrInstance = null;
                }

                // Reset CAPTCHA widget
                if (this._captchaWidgetId !== null) {
                    if (this.config.captchaProvider === 'turnstile' && window.turnstile) {
                        window.turnstile.remove(this._captchaWidgetId);
                    } else if (this.config.captchaProvider === 'hcaptcha' && window.hcaptcha) {
                        window.hcaptcha.remove(this._captchaWidgetId);
                    }
                }
                this._captchaWidgetId = null;

                // Re-fill customer info from logged-in user, or clear if not logged in
                if (this.isLoggedIn) {
                    this.checkLoggedInUser();
                } else {
                    this.customerName = '';
                    this.customerEmail = '';
                    this.customerPhone = '';
                }

                // Re-check location skip status
                if (this.locations.length <= 1) {
                    this.skipLocationStep = true;
                    if (this.locations.length === 1) {
                        this.locationId = this.locations[0].id;
                        this.selectedLocation = this.locations[0];
                    }
                }
            }
        }));
    };

    if (window.Alpine) {
        initWizard();
    } else {
        document.addEventListener('alpine:init', initWizard);
    }
})();

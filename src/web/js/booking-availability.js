/**
 * Booked Availability Handler
 *
 * Handles fetching time slots via AJAX.
 */
window.BookedAvailability = {
    /**
     * Fetch available time slots for a specific date
     */
    async getSlots(date, options = {}) {
        const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
        const csrfTokenValue = window.csrfTokenValue || '';

        // Build form data (Craft expects form-encoded data, not JSON body)
        const formData = new URLSearchParams();
        formData.append(csrfTokenName, csrfTokenValue);
        formData.append('date', date);
        if (options.serviceId) formData.append('serviceId', options.serviceId);
        if (options.employeeId) formData.append('employeeId', options.employeeId);
        if (options.locationId) formData.append('locationId', options.locationId);
        if (options.quantity) formData.append('quantity', options.quantity);
        if (options.extrasDuration) formData.append('extrasDuration', options.extrasDuration);

        try {
            const response = await fetch('/actions/booked/slot/get-available-slots', {
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
            return await response.json();
        } catch (error) {
            console.error('Booked: Failed to fetch time slots', error);
            return { success: false, slots: [] };
        }
    },

    /**
     * Fetch available start dates for a day-based service in a given month
     */
    async getDates(month, options = {}) {
        const params = new URLSearchParams();
        params.append('month', month);
        if (options.serviceId) params.append('serviceId', options.serviceId);
        if (options.employeeId) params.append('employeeId', options.employeeId);
        if (options.locationId) params.append('locationId', options.locationId);
        if (options.quantity) params.append('quantity', options.quantity);
        if (options.extrasDuration) params.append('extrasDuration', options.extrasDuration);

        try {
            const response = await fetch('/actions/booked/slot/get-available-dates?' + params.toString(), {
                headers: {
                    'Accept': 'application/json',
                },
            });
            let result = null;
            try { result = await response.json(); } catch (e) { result = null; }
            if (!response.ok) {
                return {
                    availableDates: [],
                    error: (result && result.message) || `Server error: ${response.status}`,
                    status: response.status,
                };
            }
            return { availableDates: result?.availableDates || [] };
        } catch (error) {
            console.error('Booked: Failed to fetch available dates', error);
            return { availableDates: [], error: error.message || 'Network error' };
        }
    },

    /**
     * Fetch valid end dates for a flexible day service given a start date
     */
    async getValidEndDates(startDate, options = {}) {
        const params = new URLSearchParams();
        params.append('startDate', startDate);
        if (options.serviceId) params.append('serviceId', options.serviceId);
        if (options.employeeId) params.append('employeeId', options.employeeId);
        if (options.locationId) params.append('locationId', options.locationId);
        if (options.quantity) params.append('quantity', options.quantity);

        try {
            const response = await fetch('/actions/booked/slot/get-valid-end-dates?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) throw new Error(`Server error: ${response.status}`);
            const result = await response.json();
            return result;
        } catch (error) {
            console.error('Booked: Failed to fetch valid end dates', error);
            return { validEndDates: [], minDays: 1, maxDays: 7 };
        }
    },

    async getRangeCapacity(startDate, endDate, options = {}) {
        const params = new URLSearchParams();
        params.append('startDate', startDate);
        params.append('endDate', endDate);
        if (options.serviceId) params.append('serviceId', options.serviceId);
        if (options.employeeId) params.append('employeeId', options.employeeId);
        if (options.locationId) params.append('locationId', options.locationId);

        try {
            const response = await fetch('/actions/booked/slot/get-range-capacity?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) throw new Error(`Server error: ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('Booked: Failed to fetch range capacity', error);
            return { remainingCapacity: null };
        }
    },

    /**
     * Create a new booking
     */
    async createBooking(data) {
        const csrfTokenName = window.csrfTokenName || 'CRAFT_CSRF_TOKEN';
        const csrfTokenValue = window.csrfTokenValue || '';

        // Build form data (Craft expects form-encoded data, not JSON body)
        const formData = new URLSearchParams();
        formData.append(csrfTokenName, csrfTokenValue);

        // Add all data fields
        Object.keys(data).forEach(key => {
            const value = data[key];
            if (value !== null && value !== undefined) {
                // Handle nested objects (like extras: { extraId: quantity })
                if (typeof value === 'object' && !Array.isArray(value)) {
                    // Format as extras[extraId]=quantity for PHP array parsing
                    Object.keys(value).forEach(subKey => {
                        if (value[subKey] !== null && value[subKey] !== undefined) {
                            formData.append(`${key}[${subKey}]`, value[subKey]);
                        }
                    });
                } else {
                    formData.append(key, value);
                }
            }
        });

        try {
            // Include site handle as query param so Craft resolves the correct site context
            // This ensures emails/SMS are sent in the correct language for multi-site setups
            const siteHandle = data.siteHandle || '';
            const url = siteHandle
                ? `/actions/booked/booking/create-booking?site=${encodeURIComponent(siteHandle)}`
                : '/actions/booked/booking/create-booking';
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: formData.toString()
            });
            // Parse body even on 4xx so handleBookingResult sees the backend's message.
            let result = null;
            try { result = await response.json(); } catch (parseErr) { result = null; }
            if (!response.ok) {
                if (result && typeof result === 'object') {
                    return { success: false, ...result };
                }
                throw new Error(`Server error: ${response.status}`);
            }
            return result;
        } catch (error) {
            console.error('Booked: Failed to create booking', error);
            return { success: false, message: error.message || 'Server error' };
        }
    },

    /**
     * Get Commerce settings (whether Commerce is enabled, currency, etc.)
     */
    async getCommerceSettings() {
        try {
            const response = await fetch('/actions/booked/booking-data/get-commerce-settings', {
                headers: {
                    'Accept': 'application/json'
                }
            });
            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }
            return await response.json();
        } catch (error) {
            console.error('Booked: Failed to fetch Commerce settings', error);
            return { 
                success: false, 
                commerceEnabled: false, 
                requirePayment: false 
            };
        }
    }
};


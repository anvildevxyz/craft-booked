/**
 * Shared wizard utilities for booking-wizard.js and event-wizard.js.
 *
 * Exposes BookedWizardCommon with:
 * - sharedState(config)  — common reactive properties to spread into Alpine data
 * - sharedMethods(opts)  — common methods (use `this` to access Alpine component)
 * - initLoadingWatcher() — call from init() to lock height during loading
 * - initStepAnnouncer()  — call from init() to announce step changes
 */
(function() {
    window.BookedWizardCommon = {

        /**
         * Shared reactive state for customer info, commerce, CAPTCHA, and accessibility.
         */
        sharedState(config) {
            return {
                customerName: '',
                customerEmail: '',
                customerPhone: '',
                notes: '',
                touched: { name: false, email: false, phone: false },
                isLoggedIn: false,
                bookingError: null,
                liveAnnouncement: '',
                _captchaWidgetId: null,
                commerceEnabled: false,
                currency: 'USD',
                currencySymbol: 'USD',
                cartUrl: '',
                checkoutUrl: '',
                reservationDetails: null,
                addToCartOnly: false,
            };
        },

        /**
         * Shared methods. All use `this` which binds to the Alpine component.
         * @param {Object} opts
         * @param {string} opts.captchaContainerPrefix - ID prefix for CAPTCHA containers (e.g. 'booked' or 'booked-event')
         */
        sharedMethods(opts = {}) {
            const captchaPrefix = opts.captchaContainerPrefix || 'booked';

            return {
                async checkLoggedInUser() {
                    try {
                        const response = await fetch('/actions/booked/account/current-user', {
                            headers: { 'Accept': 'application/json' }
                        });
                        if (!response.ok) {
                            throw new Error(`Server error: ${response.status}`);
                        }
                        const data = await response.json();
                        if (data.loggedIn && data.user) {
                            this.customerName = data.user.name || '';
                            this.customerEmail = data.user.email || '';
                            this.customerPhone = data.user.phone || '';
                            this.isLoggedIn = true;
                        }
                    } catch (error) {
                        // Silently fail - user is not logged in
                    }
                },

                async fetchCommerceSettings() {
                    try {
                        const result = await window.BookedAvailability.getCommerceSettings();
                        if (result.success) {
                            this.commerceEnabled = result.commerceEnabled;
                            this.currency = result.currency || 'USD';
                            this.currencySymbol = result.currencySymbol || 'USD';
                            this.cartUrl = result.cartUrl || '';
                            this.checkoutUrl = result.checkoutUrl || '';
                        }
                    } catch (error) {
                        console.warn('Could not fetch Commerce settings:', error);
                    }
                },

                isCustomerInfoValid() {
                    if (!this.customerName || this.customerName.trim().length === 0) {
                        return false;
                    }
                    if (!this.customerEmail || !this.isValidEmail(this.customerEmail)) {
                        return false;
                    }
                    if (this.config.requirePhone && (!this.customerPhone || this.customerPhone.trim().length === 0)) {
                        return false;
                    }
                    return true;
                },

                isValidEmail(email) {
                    return window.BookedValidation && window.BookedValidation.isValidEmail(email);
                },

                formatPrice(price) {
                    if (price == null) return '';
                    return this.currencySymbol + ' ' + parseFloat(price).toFixed(2);
                },

                announce(message) {
                    this.liveAnnouncement = '';
                    this.$nextTick(() => {
                        this.liveAnnouncement = message;
                    });
                },

                focusStep() {
                    const stepContainers = this.$el.querySelectorAll(':scope > div[x-show]');
                    for (const container of stepContainers) {
                        const heading = container.querySelector('h3[tabindex="-1"]');
                        if (heading && container.style.display !== 'none') {
                            heading.focus({ preventScroll: true });
                            return;
                        }
                    }
                },

                renderCaptchaWidget() {
                    if (!this.config.captchaEnabled || !this.config.captchaSiteKey) return;
                    if (this.config.captchaProvider === 'recaptcha') return;

                    if (this.config.captchaProvider === 'turnstile') {
                        const container = document.getElementById(captchaPrefix + '-turnstile-widget');
                        if (!container || !window.turnstile) return;
                        if (this._captchaWidgetId !== null) {
                            try { window.turnstile.remove(this._captchaWidgetId); } catch (e) { /* ignore */ }
                        }
                        container.innerHTML = '';
                        this._captchaWidgetId = window.turnstile.render(container, {
                            sitekey: this.config.captchaSiteKey,
                            theme: 'light',
                        });
                    } else if (this.config.captchaProvider === 'hcaptcha') {
                        const container = document.getElementById(captchaPrefix + '-hcaptcha-widget');
                        if (!container || !window.hcaptcha) return;
                        if (this._captchaWidgetId !== null) {
                            try { window.hcaptcha.remove(this._captchaWidgetId); } catch (e) { /* ignore */ }
                        }
                        container.innerHTML = '';
                        this._captchaWidgetId = window.hcaptcha.render(container, {
                            sitekey: this.config.captchaSiteKey,
                        });
                    }
                },

                resetCaptchaWidget() {
                    if (!this.config.captchaEnabled || !this.config.captchaSiteKey) return;
                    if (this._captchaWidgetId === null) return;

                    try {
                        if (this.config.captchaProvider === 'turnstile' && window.turnstile) {
                            window.turnstile.reset(this._captchaWidgetId);
                        } else if (this.config.captchaProvider === 'hcaptcha' && window.hcaptcha) {
                            window.hcaptcha.reset(this._captchaWidgetId);
                        }
                    } catch (e) {
                        // If reset fails, re-render from scratch
                        this.renderCaptchaWidget();
                    }
                },

                /** Generate CAPTCHA token for submission (reCAPTCHA v3, hCaptcha, or Turnstile). */
                async getCaptchaToken() {
                    if (!this.config.captchaEnabled || !this.config.captchaSiteKey) return null;
                    try {
                        if (this.config.captchaProvider === 'recaptcha') {
                            if (window.grecaptcha) {
                                return await new Promise((resolve) => {
                                    window.grecaptcha.ready(() => {
                                        window.grecaptcha.execute(this.config.captchaSiteKey, { action: this.config.captchaAction || 'booking' })
                                            .then(resolve)
                                            .catch((error) => {
                                                console.error('reCAPTCHA execution failed:', error);
                                                resolve(null);
                                            });
                                    });
                                });
                            }
                        } else if (this.config.captchaProvider === 'hcaptcha') {
                            if (window.hcaptcha && this._captchaWidgetId !== null) {
                                return window.hcaptcha.getResponse(this._captchaWidgetId);
                            }
                        } else if (this.config.captchaProvider === 'turnstile') {
                            if (window.turnstile && this._captchaWidgetId !== null) {
                                return window.turnstile.getResponse(this._captchaWidgetId);
                            }
                        }
                    } catch (error) {
                        console.error('Failed to generate CAPTCHA token:', error);
                    }

                    // CAPTCHA enabled but token unavailable — surface error instead of silently submitting without it
                    this.bookingError = this.config.messages?.captchaFailed || 'CAPTCHA verification failed. Please refresh the page and try again.';
                    return null;
                },

                /** Append honeypot field value to a data object if configured. */
                appendHoneypotData(data) {
                    if (this.config.honeypotFieldName) {
                        const honeypotInput = document.getElementById(this.config.honeypotFieldName);
                        if (honeypotInput) {
                            data[this.config.honeypotFieldName] = honeypotInput.value;
                        }
                    }
                },

                async addToCart() {
                    this.addToCartOnly = true;
                    await this.submitBooking();
                },

                async proceedToCheckout() {
                    this.addToCartOnly = false;
                    await this.submitBooking();
                },

                /** Handle booking result (success/error). Call from submitBooking() after API call. */
                handleBookingResult(result) {
                    if (result.success) {
                        if (result.commerce && result.redirectUrl) {
                            try {
                                const url = new URL(result.redirectUrl, window.location.origin);
                                window.location.href = url.href;
                            } catch (e) {
                                console.error('Invalid Commerce redirect URL:', e);
                            }
                            return true;
                        }
                        this.step = this.totalSteps + 1;
                        this.reservationDetails = result.reservation;
                        this.softLockToken = null;
                        this.announce(this.config.messages?.bookingSuccessAnnouncement || 'Booking successful');
                        this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    } else {
                        const rawMessage = result.message || this.config.messages?.bookingErrorDefault || 'An error occurred while creating your booking.';
                        const tempEl = document.createElement('div');
                        tempEl.textContent = rawMessage;
                        this.bookingError = tempEl.textContent;
                        this.announce(this.bookingError);
                        // Reset CAPTCHA widget so the user can retry
                        this.resetCaptchaWidget();
                        this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    return false;
                },

                handleBookingError(error) {
                    console.error('Booking error:', error);
                    const rawMessage = error.message || this.config.messages?.unexpectedError || 'An unexpected error occurred. Please try again.';
                    const tempEl = document.createElement('div');
                    tempEl.textContent = rawMessage;
                    this.bookingError = tempEl.textContent;
                    this.announce(this.bookingError);
                    this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },
            };
        },

        /** Watch `loading` to lock wizard height and prevent layout collapse. Call from init(). */
        initLoadingWatcher(ctx) {
            ctx.$watch('loading', (isLoading) => {
                if (isLoading) {
                    ctx.$el.style.minHeight = ctx.$el.offsetHeight + 'px';
                } else {
                    ctx.$nextTick(() => { ctx.$el.style.minHeight = ''; });
                }
            });
        },

        /**
         * Watch `step` to announce changes and render CAPTCHA on a specific step. Call from init().
         * @param {Object} ctx - Alpine component (this)
         * @param {number} captchaStep - Step number that triggers CAPTCHA render
         */
        initStepAnnouncer(ctx, captchaStep) {
            ctx.$watch('step', (newStep, oldStep) => {
                if (captchaStep && newStep === captchaStep && oldStep !== captchaStep) {
                    ctx.$nextTick(() => { ctx.renderCaptchaWidget(); });
                }
                if (newStep !== oldStep) {
                    ctx.$nextTick(() => {
                        ctx.focusStep(newStep);
                        const stepName = ctx.config.stepAnnouncements?.[String(newStep)] || '';
                        if (stepName) {
                            ctx.announce(stepName);
                        }
                    });
                }
            });
        },
    };
})();

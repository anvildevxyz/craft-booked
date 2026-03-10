/**
 * Calendar Settings - Test connection buttons
 * Lightswitch toggles are handled natively by Craft's `toggle` parameter.
 */
(function() {
    /**
     * Setup test connection buttons
     */
    function setupTestButtons() {
        var googleBtn = document.getElementById('test-google-connection');
        var outlookBtn = document.getElementById('test-outlook-connection');

        if (googleBtn) {
            googleBtn.addEventListener('click', function() {
                testConnection('google', googleBtn, document.getElementById('google-test-result'));
            });
        }

        if (outlookBtn) {
            outlookBtn.addEventListener('click', function() {
                testConnection('outlook', outlookBtn, document.getElementById('outlook-test-result'));
            });
        }
    }

    /**
     * Test OAuth connection
     */
    async function testConnection(provider, button, resultEl) {
        var clientIdField = document.getElementById(provider === 'google' ? 'googleCalendarClientId' : 'outlookCalendarClientId');
        var clientSecretField = document.getElementById(provider === 'google' ? 'googleCalendarClientSecret' : 'outlookCalendarClientSecret');

        var clientId = clientIdField ? clientIdField.value.trim() : '';
        var clientSecret = clientSecretField ? clientSecretField.value.trim() : '';

        if (!clientId || !clientSecret) {
            showResult(resultEl, false, 'Please enter both Client ID and Client Secret');
            return;
        }

        button.disabled = true;
        var originalText = button.textContent;
        button.textContent = 'Testing...';
        resultEl.textContent = '';
        resultEl.className = 'test-result';

        try {
            var response = await fetch(Craft.getActionUrl('booked/cp/settings/test-calendar-connection'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue
                },
                body: JSON.stringify({
                    provider: provider,
                    clientId: clientId,
                    clientSecret: clientSecret
                })
            });

            if (!response.ok) {
                throw new Error('Server error: ' + response.status);
            }
            var data = await response.json();

            if (data.success) {
                showResult(resultEl, true, data.message || 'Connection successful!');
            } else {
                showResult(resultEl, false, data.error || 'Connection failed');
            }
        } catch (error) {
            showResult(resultEl, false, 'Request failed: ' + error.message);
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    function showResult(el, success, message) {
        if (!el) return;
        el.textContent = message;
        el.className = 'test-result ' + (success ? 'test-success' : 'test-error');
    }

    function init() {
        setupTestButtons();
    }

    $(document).ready(init);
})();

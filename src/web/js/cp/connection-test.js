/**
 * Reusable connection-test button for CP settings pages.
 *
 * Usage:
 *   Booked.setupConnectionTest({
 *       buttonId: 'test-zoom-btn',
 *       resultId: 'zoom-test-result',
 *       action: 'booked/cp/settings/test-zoom',
 *       connectingMessage: 'Connecting to Zoom...'
 *   });
 */
(function() {
    'use strict';

    window.Booked = window.Booked || {};

    /**
     * Wire a test-connection button to a Craft action endpoint.
     *
     * @param {Object} opts
     * @param {string} opts.buttonId          - ID of the test button
     * @param {string} opts.resultId          - ID of the result container (must contain a <pre>)
     * @param {string} opts.action            - Craft action path
     * @param {string} opts.connectingMessage - Message shown while testing
     */
    Booked.setupConnectionTest = function(opts) {
        var btn = document.getElementById(opts.buttonId);
        var resultDiv = document.getElementById(opts.resultId);
        var resultPre = resultDiv ? resultDiv.querySelector('pre') : null;

        if (!btn || !resultDiv || !resultPre) return;

        var defaultLabel = btn.textContent;

        btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.textContent = 'Testing...';
            resultDiv.style.display = 'block';
            resultPre.textContent = opts.connectingMessage || 'Connecting...';
            resultPre.style.color = '#666';

            var formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);

            fetch(Craft.getActionUrl(opts.action), {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) {
                if (!response.ok) throw new Error('Server error: ' + response.status);
                return response.json();
            })
            .then(function(data) {
                resultPre.textContent = data.message;
                resultPre.style.color = data.success ? '#27ae60' : '#e74c3c';
                btn.disabled = false;
                btn.textContent = defaultLabel;
            })
            .catch(function(error) {
                resultPre.textContent = 'Error: ' + error.message;
                resultPre.style.color = '#e74c3c';
                btn.disabled = false;
                btn.textContent = defaultLabel;
            });
        });
    };
})();

/**
 * SMS Settings - Twilio test connection functionality
 * 
 * Requires:
 * - window.BookedSmsSettings config object with:
 *   - testUrl: string (action URL for test endpoint)
 *   - csrfToken: string
 *   - strings: { testing: string, testButton: string }
 */
(function() {
    'use strict';

    function init() {
        const config = window.BookedSmsSettings;
        if (!config) return;

        const testBtn = document.getElementById('test-twilio-btn');
        const resultDiv = document.getElementById('twilio-test-result');
        const resultPre = resultDiv ? resultDiv.querySelector('pre') : null;

        if (!testBtn || !resultDiv || !resultPre) return;

        testBtn.addEventListener('click', async function() {
            testBtn.disabled = true;
            testBtn.textContent = config.strings?.testing || 'Testing...';
            resultDiv.style.display = 'none';

            try {
                const response = await fetch(config.testUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': config.csrfToken,
                    },
                    body: JSON.stringify({}),
                });

                if (!response.ok) {
                    throw new Error('Server error: ' + response.status);
                }
                const data = await response.json();
                
                resultDiv.style.display = 'block';
                if (data.success) {
                    resultPre.style.borderLeft = '4px solid #27ae60';
                    resultPre.style.background = '#f0fff4';
                } else {
                    resultPre.style.borderLeft = '4px solid #e74c3c';
                    resultPre.style.background = '#fff5f5';
                }
                resultPre.textContent = data.message || data.error || 'Unknown response';
            } catch (error) {
                resultDiv.style.display = 'block';
                resultPre.style.borderLeft = '4px solid #e74c3c';
                resultPre.style.background = '#fff5f5';
                resultPre.textContent = 'Error: ' + error.message;
            }

            testBtn.disabled = false;
            testBtn.textContent = config.strings?.testButton || 'Test Twilio Connection';
        });
    }

    // Initialize when ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

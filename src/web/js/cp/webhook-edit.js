/**
 * Webhook CP - Functions for webhook index and edit pages
 */
(function() {
    'use strict';

    /**
     * Copy the signing secret to clipboard
     */
    function copySecret() {
        var secretField = document.getElementById('secret-display');
        if (!secretField) return;

        secretField.select();
        secretField.setSelectionRange(0, 99999); // For mobile

        try {
            navigator.clipboard.writeText(secretField.value).then(function() {
                if (typeof Craft !== 'undefined' && Craft.cp) {
                    Craft.cp.displayNotice(Craft.t('booked', 'webhook.js.secretCopied'));
                }
            }).catch(function() {
                // Fallback for older browsers
                document.execCommand('copy');
                if (typeof Craft !== 'undefined' && Craft.cp) {
                    Craft.cp.displayNotice(Craft.t('booked', 'webhook.js.secretCopied'));
                }
            });
        } catch (err) {
            document.execCommand('copy');
            if (typeof Craft !== 'undefined' && Craft.cp) {
                Craft.cp.displayNotice(Craft.t('booked', 'webhook.js.secretCopied'));
            }
        }
    }

    /**
     * Add a new custom header row
     */
    function addHeader() {
        var container = document.getElementById('custom-headers');
        if (!container) return;

        var row = document.createElement('div');
        row.className = 'flex header-row';
        row.style.marginBottom = '8px';
        row.innerHTML =
            '<input type="text" name="headerKeys[]" placeholder="' + Craft.escapeHtml(Craft.t('booked', 'webhook.headerNamePlaceholder')) + '" class="text" style="width: 200px;">' +
            '<input type="text" name="headerValues[]" placeholder="' + Craft.escapeHtml(Craft.t('booked', 'webhook.headerValuePlaceholder')) + '" class="text flex-grow">' +
            '<button type="button" class="btn small" onclick="Booked.Webhook.removeHeader(this)">×</button>';
        container.appendChild(row);
    }

    /**
     * Remove a header row
     */
    function removeHeader(button) {
        if (button && button.parentElement) {
            button.parentElement.remove();
        }
    }

    /**
     * Delete a webhook with confirmation
     */
    function deleteWebhook(id, redirectUrl) {
        if (confirm(Craft.t('booked', 'webhook.deleteConfirm'))) {
            Craft.sendActionRequest('POST', 'booked/cp/webhooks/delete', {
                data: { id: id }
            }).then(function() {
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                } else {
                    // Remove the row from the index table
                    var row = document.querySelector('tr[data-webhook-id="' + CSS.escape(id) + '"]');
                    if (row) {
                        row.remove();
                        Craft.cp.displayNotice(Craft.t('booked', 'webhook.deleted'));
                        // If no rows left, reload to show empty state
                        if (!document.querySelector('.booked-webhooks-index tbody tr')) {
                            window.location.reload();
                        }
                    }
                }
            }).catch(function(error) {
                Craft.cp.displayError(
                    error.response && error.response.data ? error.response.data.message : 'Delete failed'
                );
            });
        }
    }

    /**
     * Toggle a webhook's enabled status
     */
    function toggleWebhook(id, row) {
        Craft.sendActionRequest('POST', 'booked/cp/webhooks/toggle', {
            data: { id: id }
        }).then(function(response) {
            var enabled = response.data.enabled;
            // Update status dot and title in the table row
            if (row) {
                var statusCell = row.querySelector('.webhook-status');
                if (statusCell) {
                    var dot = statusCell.querySelector('.status');
                    if (dot) {
                        dot.className = enabled ? 'status green' : 'status';
                    }
                    statusCell.title = enabled
                        ? Craft.t('booked', 'webhook.js.enabled')
                        : Craft.t('booked', 'webhook.js.disabled');
                }
            }
            // Update the toggle menu item text (menu may be detached from row by Craft)
            var toggleLink = document.querySelector('.webhook-toggle-action[data-webhook-id="' + CSS.escape(id) + '"]');
            if (toggleLink) {
                toggleLink.textContent = enabled
                    ? Craft.t('booked', 'webhook.js.disable')
                    : Craft.t('booked', 'webhook.js.enable');
            }
            Craft.cp.displayNotice(
                enabled
                    ? Craft.t('booked', 'webhook.js.webhookEnabled')
                    : Craft.t('booked', 'webhook.js.webhookDisabled')
            );
        }).catch(function(error) {
            Craft.cp.displayError(
                error.response && error.response.data ? error.response.data.message : 'Toggle failed'
            );
        });
    }

    /**
     * Send a test webhook via a separate form submission (avoids nested form issue with fullPageForm)
     */
    function sendTest(id) {
        var form = document.createElement('form');
        form.method = 'post';
        form.style.display = 'none';

        var csrfInput = document.querySelector('input[name="CRAFT_CSRF_TOKEN"]');
        if (csrfInput) {
            var csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = 'CRAFT_CSRF_TOKEN';
            csrf.value = csrfInput.value;
            form.appendChild(csrf);
        }

        var actionField = document.createElement('input');
        actionField.type = 'hidden';
        actionField.name = 'action';
        actionField.value = 'booked/cp/webhooks/test';
        form.appendChild(actionField);

        var idField = document.createElement('input');
        idField.type = 'hidden';
        idField.name = 'id';
        idField.value = id;
        form.appendChild(idField);

        document.body.appendChild(form);
        form.submit();
    }

    /**
     * Regenerate the signing secret via AJAX
     */
    function regenerateSecret(id) {
        if (!confirm(Craft.t('booked', 'webhook.js.regenerateConfirm'))) {
            return;
        }
        Craft.sendActionRequest('POST', 'booked/cp/webhooks/regenerate-secret', {
            data: { id: id }
        }).then(function(response) {
            var secretField = document.getElementById('secret-display');
            if (secretField && response.data.secret) {
                secretField.value = response.data.secret;
            }
            Craft.cp.displayNotice(Craft.t('booked', 'webhook.js.secretRegenerated'));
        }).catch(function(error) {
            Craft.cp.displayError(
                error.response && error.response.data ? error.response.data.message : 'Regenerate failed'
            );
        });
    }

    // Initialize delete button on edit page
    (function() {
        var btn = document.getElementById('delete-webhook-btn');
        if (btn) {
            btn.addEventListener('click', function() {
                deleteWebhook(btn.dataset.webhookId, btn.dataset.redirectUrl);
            });
        }

        // Initialize test button on edit page
        var testBtn = document.getElementById('send-test-btn');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var idInput = document.querySelector('input[name="id"]');
                if (idInput) {
                    sendTest(idInput.value);
                }
            });
        }

        // Initialize regenerate secret button on edit page
        var regenBtn = document.getElementById('regenerate-secret-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function() {
                regenerateSecret(regenBtn.dataset.webhookId);
            });
        }
    })();

    // Expose functions globally
    window.Booked = window.Booked || {};
    window.Booked.Webhook = {
        copySecret: copySecret,
        addHeader: addHeader,
        removeHeader: removeHeader,
        deleteWebhook: deleteWebhook,
        toggleWebhook: toggleWebhook
    };

    // Also expose at window level for backwards compatibility with onclick handlers
    window.copySecret = copySecret;
    window.addHeader = addHeader;
})();

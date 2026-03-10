/**
 * Calendar Action Buttons - Handles connect/disconnect calendar actions on employee edit page
 */
(function() {
    'use strict';

    function init() {
        document.querySelectorAll('.calendar-action-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = this.dataset.action;
                var employeeId = this.dataset.employeeId;
                var provider = this.dataset.provider;

                btn.classList.add('loading');
                btn.disabled = true;

                Craft.sendActionRequest('POST', action, {
                    data: { employeeId: employeeId, provider: provider }
                }).then(function(response) {
                    Craft.cp.displayNotice(response.data.message || 'Done');
                    window.location.reload();
                }).catch(function(error) {
                    var msg = (error.response && error.response.data && error.response.data.message) || 'An error occurred';
                    Craft.cp.displayError(msg);
                    btn.classList.remove('loading');
                    btn.disabled = false;
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

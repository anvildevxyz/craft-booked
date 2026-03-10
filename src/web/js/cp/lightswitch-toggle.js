/**
 * Shared lightswitch toggle utility for CP settings pages.
 *
 * Usage:
 *   Booked.setupLightswitchToggle('switchId', 'settingsId');
 *   Booked.setupLightswitchToggle('switchId', 'settingsId', { onToggle: function($el, isOn) { ... } });
 */
(function() {
    'use strict';

    window.Booked = window.Booked || {};

    /**
     * Wire a Craft lightswitch to show/hide a settings container.
     *
     * @param {string} switchId   - ID of the lightswitch input element
     * @param {string} settingsId - ID of the container to show/hide
     * @param {Object} [options]
     * @param {function} [options.onToggle] - Custom callback: function($settings, isOn). Overrides default hidden-class toggle.
     */
    Booked.setupLightswitchToggle = function(switchId, settingsId, options) {
        var $switch = $('#' + switchId);
        var $settings = $('#' + settingsId);

        if (!$switch.length || !$settings.length) return;

        var onToggle = options && options.onToggle;

        function update() {
            var isOn = $switch.hasClass('on');
            if (onToggle) {
                onToggle($settings, isOn);
            } else {
                $settings.toggleClass('hidden', !isOn);
            }
        }

        $switch.on('change', update);
        update();
    };
})();

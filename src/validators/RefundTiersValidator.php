<?php

namespace anvildev\booked\validators;

/**
 * Validates refund tier configurations.
 *
 * Each tier must be an array with:
 * - hoursBeforeStart: numeric >= 0 (hours before booking start time)
 * - refundPercentage: numeric 0-100 (percentage to refund)
 */
class RefundTiersValidator
{
    /**
     * Validate that tiers are correctly structured.
     *
     * Accepts null or empty array (no tiers defined).
     * Each tier must have hoursBeforeStart >= 0 and refundPercentage 0-100.
     */
    public function isValid(mixed $tiers): bool
    {
        if ($tiers === null || $tiers === []) {
            return true;
        }

        if (!is_array($tiers)) {
            return false;
        }

        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                return false;
            }

            if (!isset($tier['hoursBeforeStart'], $tier['refundPercentage'])) {
                return false;
            }

            if (!is_numeric($tier['hoursBeforeStart']) || $tier['hoursBeforeStart'] < 0) {
                return false;
            }

            if (!is_numeric($tier['refundPercentage']) || $tier['refundPercentage'] < 0 || $tier['refundPercentage'] > 100) {
                return false;
            }
        }

        return true;
    }
}

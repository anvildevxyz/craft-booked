<?php

namespace anvildev\booked\events;

use anvildev\booked\records\PaymentRecord;
use yii\base\Event;

/**
 * Raised after a direct (Commerce-free) payment is successfully refunded, in
 * full or in part. Amounts are in the currency's minor units, matching
 * {@see PaymentRecord}. The Commerce-mode failure counterpart is
 * {@see RefundFailedEvent}.
 */
class PaymentRefundedEvent extends Event
{
    public int $reservationId;
    public PaymentRecord $record;

    /** The amount refunded by this operation, in minor units. */
    public int $amount;

    /** The record's cumulative refunded total after this operation, in minor units. */
    public int $totalRefunded;
}

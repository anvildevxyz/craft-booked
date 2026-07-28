<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;
use ReflectionMethod;

/**
 * Regression guards for the 1.4.0 payment-concurrency hardening (from the
 * adversarial verification pass). These lock in the specific fixes so a future
 * change can't silently reintroduce the money/race bugs. Full behavioral proof
 * of the interleavings lives in the integration suite.
 */
class PaymentHardeningTest extends TestCase
{
    private static function src(string $class, string $method): string
    {
        $rm = new ReflectionMethod($class, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    /** CRITICAL: GC must not cancel a booking a webhook just confirmed. */
    public function testGcCancelIsGuardedByPendingStatus(): void
    {
        $src = self::src('anvildev\booked\services\MaintenanceService', 'cancelStaleReservation');
        // The UPDATE carries a status=PENDING precondition so a confirmed row is untouched.
        $this->assertStringContainsString("'status' => ReservationRecord::STATUS_PENDING", $src);
        // And the audit log only fires when a row was actually cancelled.
        $this->assertStringContainsString('if ($affected > 0)', $src);
    }

    /** HIGH: only one caller flips pending→confirmed, so notifications don't double-fire. */
    public function testConfirmReservationUsesAtomicConditionalUpdate(): void
    {
        $src = self::src('anvildev\booked\services\PaymentService', 'confirmReservation');
        $this->assertStringContainsString('STATUS_CONFIRMED', $src);
        $this->assertStringContainsString('STATUS_PENDING', $src);
        $this->assertStringContainsString('if ($affected < 1)', $src);
        // No longer a read-then-save (which was the TOCTOU).
        $this->assertStringNotContainsString('->save(false)', $src);
    }

    /** HIGH: an out-of-order refund webhook must not lower the refunded total. */
    public function testApplyRefundSyncIsMonotonic(): void
    {
        $src = self::src('anvildev\booked\services\PaymentService', 'applyRefundSync');
        $this->assertStringContainsString('if ($refundedAmount <= (int) ($record->refundedAmount ?? 0))', $src);
    }

    /** HIGH: concurrent refunds on one reservation serialize under a mutex. */
    public function testRefundSerializesUnderMutex(): void
    {
        $src = self::src('anvildev\booked\services\PaymentService', 'refund');
        $this->assertStringContainsString('getMutex()', $src);
        $this->assertStringContainsString("acquire(\$mutexKey", $src);
        $this->assertStringContainsString('release($mutexKey)', $src);
        $this->assertStringContainsString('finally', $src);
    }

    /** HIGH: distinct same-amount refunds get distinct Stripe idempotency keys. */
    public function testRefundIdempotencyKeyIncludesRunningTotal(): void
    {
        $src = self::src('anvildev\booked\gateways\StripeGateway', 'refund');
        $this->assertStringContainsString("'booked_re_' . \$payment->id . '_' . (int) (\$payment->refundedAmount ?? 0) . '_' . \$amount", $src);
    }
}

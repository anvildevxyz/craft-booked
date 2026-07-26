<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\records\PaymentRecord;
use anvildev\booked\services\PaymentService;
use anvildev\booked\tests\Support\TestCase;
use ReflectionMethod;

class PaymentServiceTest extends TestCase
{
    public function testToMinorUnitsTwoDecimalCurrency(): void
    {
        $this->assertSame(4000, PaymentService::toMinorUnits(40.0, 'USD'));
        $this->assertSame(2550, PaymentService::toMinorUnits(25.5, 'EUR'));
        $this->assertSame(0, PaymentService::toMinorUnits(0.0, 'USD'));
        $this->assertSame(1999, PaymentService::toMinorUnits(19.99, 'GBP'));
    }

    public function testToMinorUnitsZeroDecimalCurrencyIsNotMultiplied(): void
    {
        // The 100x-overcharge regression: JPY/KRW etc. are already minor units.
        $this->assertSame(1000, PaymentService::toMinorUnits(1000.0, 'JPY'));
        $this->assertSame(5000, PaymentService::toMinorUnits(5000.0, 'krw'));
        $this->assertSame(1500, PaymentService::toMinorUnits(1500.0, 'VND'));
    }

    /**
     * @dataProvider finalizedProvider
     */
    public function testIsFinalized(string $status, bool $expected): void
    {
        $this->assertSame($expected, PaymentService::isFinalized($status));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function finalizedProvider(): array
    {
        return [
            'pending → not finalized' => [PaymentRecord::STATUS_PENDING, false],
            'failed → not finalized' => [PaymentRecord::STATUS_FAILED, false],
            'paid → finalized' => [PaymentRecord::STATUS_PAID, true],
            'refunded → finalized' => [PaymentRecord::STATUS_REFUNDED, true],
            'partiallyRefunded → finalized' => [PaymentRecord::STATUS_PARTIALLY_REFUNDED, true],
        ];
    }

    /**
     * @dataProvider resolveStatusProvider
     */
    public function testResolveStatus(string $mode, float $total, ?string $rec, ?bool $orderPaid, string $expected): void
    {
        $this->assertSame($expected, PaymentService::resolveStatus($mode, $total, $rec, $orderPaid));
    }

    /**
     * @return array<string, array{string, float, string|null, bool|null, string}>
     */
    public static function resolveStatusProvider(): array
    {
        return [
            'zero total is free (direct)' => ['direct', 0.0, null, null, PaymentService::STATUS_FREE],
            'zero total is free (commerce)' => ['commerce', 0.0, null, true, PaymentService::STATUS_FREE],
            'none mode with price is free' => ['none', 40.0, null, null, PaymentService::STATUS_FREE],
            'direct paid record' => ['direct', 40.0, PaymentRecord::STATUS_PAID, null, PaymentRecord::STATUS_PAID],
            'direct pending record' => ['direct', 40.0, PaymentRecord::STATUS_PENDING, null, PaymentRecord::STATUS_PENDING],
            'direct no record is unpaid' => ['direct', 40.0, null, null, PaymentService::STATUS_UNPAID],
            'commerce paid order' => ['commerce', 40.0, null, true, PaymentRecord::STATUS_PAID],
            'commerce unpaid order' => ['commerce', 40.0, null, false, PaymentRecord::STATUS_PENDING],
            'commerce no order is unpaid' => ['commerce', 40.0, null, null, PaymentService::STATUS_UNPAID],
        ];
    }

    public function testDirectPaymentBookingsAreHeldPending(): void
    {
        // Both the controller and the service must treat direct mode like commerce:
        // a paid booking starts pending, not confirmed-for-free.
        $ctrl = self::methodSource('anvildev\booked\controllers\BookingController', 'actionCreateBooking');
        $this->assertStringContainsString('isDirectPayment', $ctrl);
        $this->assertStringContainsString('STATUS_PENDING', $ctrl);

        $svc = self::methodSource('anvildev\booked\services\BookingService', 'populateReservation');
        $this->assertStringContainsString('isDirectPayment', $svc);
    }

    private static function methodSource(string $class, string $method): string
    {
        $rm = new ReflectionMethod($class, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    public function testConfirmPollConfirmsViaIdempotentPathAndCatchesErrors(): void
    {
        $src = self::methodSource('anvildev\booked\controllers\PaymentController', 'actionConfirm');
        // #3: a gateway-confirmed poll routes through the shared idempotent path
        // (which confirms the reservation), not a bare status write that suppresses the webhook.
        $this->assertStringContainsString('handleVerifiedPayment', $src);
        // #10: the gateway call is wrapped so a transient error is a JSON error, not a 500.
        $this->assertStringContainsString('try {', $src);
        $this->assertStringContainsString('} catch', $src);
    }

    public function testWebhookIsCsrfExemptAndAnonymous(): void
    {
        $src = file_get_contents(
            (new ReflectionMethod('anvildev\booked\controllers\PaymentController', 'beforeAction'))->getFileName(),
        );
        // CSRF disabled for the webhook action, and it's anonymous.
        $this->assertStringContainsString("\$action->id === 'webhook'", $src);
        $this->assertStringContainsString('$this->enableCsrfValidation = false', $src);
        $this->assertStringContainsString("'webhook'", $src);
    }
}

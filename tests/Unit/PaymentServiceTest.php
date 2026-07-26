<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\records\PaymentRecord;
use anvildev\booked\services\PaymentService;
use anvildev\booked\tests\Support\TestCase;
use ReflectionMethod;

class PaymentServiceTest extends TestCase
{
    public function testToMinorUnits(): void
    {
        $this->assertSame(4000, PaymentService::toMinorUnits(40.0));
        $this->assertSame(2550, PaymentService::toMinorUnits(25.5));
        $this->assertSame(0, PaymentService::toMinorUnits(0.0));
        $this->assertSame(1999, PaymentService::toMinorUnits(19.99));
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

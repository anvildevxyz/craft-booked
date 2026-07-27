<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\contracts\PaymentGatewayInterface;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\events\RegisterPaymentGatewaysEvent;
use anvildev\booked\payments\PaymentContext;
use anvildev\booked\payments\PaymentResult;
use anvildev\booked\payments\PaymentSession;
use anvildev\booked\payments\RefundResult;
use anvildev\booked\records\PaymentRecord;
use anvildev\booked\services\PaymentGatewayService;
use anvildev\booked\tests\Support\TestCase;
use craft\web\Request;
use yii\base\Event;

class PaymentGatewayServiceTest extends TestCase
{
    public function testDummyGatewayRegistersAndResolvesByHandle(): void
    {
        $dummy = self::dummyGateway('dummy');
        $handler = static function(RegisterPaymentGatewaysEvent $e) use ($dummy) {
            $e->gateways[] = $dummy;
        };
        Event::on(PaymentGatewayService::class, PaymentGatewayService::EVENT_REGISTER_PAYMENT_GATEWAYS, $handler);

        try {
            $service = new PaymentGatewayService();
            $this->assertTrue($service->hasGateway('dummy'));
            $this->assertSame($dummy, $service->getGateway('dummy'));
            $this->assertArrayHasKey('dummy', $service->getGateways());
            $this->assertNull($service->getGateway('nonexistent'));
        } finally {
            Event::off(PaymentGatewayService::class, PaymentGatewayService::EVENT_REGISTER_PAYMENT_GATEWAYS, $handler);
        }
    }

    public function testValueObjectsAreImmutableCarriers(): void
    {
        $ctx = new PaymentContext(1000, 'USD', 'Haircut', 'https://example.test/return', ['ref' => 'BKD-1']);
        $this->assertSame(1000, $ctx->amount);
        $this->assertSame('USD', $ctx->currency);
        $this->assertSame(['ref' => 'BKD-1'], $ctx->metadata);

        $session = new PaymentSession('pi_123', PaymentRecord::STATUS_PENDING, 'secret_123');
        $this->assertSame('pi_123', $session->externalId);
        $this->assertSame('secret_123', $session->clientSecret);

        $result = new PaymentResult(PaymentRecord::STATUS_PAID, 'pi_123', 1000, true);
        $this->assertTrue($result->paid);

        $refund = new RefundResult(true, 1000, 're_123');
        $this->assertTrue($refund->success);
        $this->assertSame(1000, $refund->refundedAmount);
    }

    private static function dummyGateway(string $handle): PaymentGatewayInterface
    {
        return new class($handle) implements PaymentGatewayInterface {
            public function __construct(private string $handle)
            {
            }

            public function getHandle(): string
            {
                return $this->handle;
            }

            public function getDisplayName(): string
            {
                return 'Dummy';
            }

            public function createPayment(ReservationInterface $reservation, PaymentContext $context): PaymentSession
            {
                return new PaymentSession('ext_1', PaymentRecord::STATUS_PENDING);
            }

            public function confirmPayment(string $externalId): PaymentResult
            {
                return new PaymentResult(PaymentRecord::STATUS_PAID, $externalId, 0, true);
            }

            public function refund(PaymentRecord $payment, int $amount): RefundResult
            {
                return new RefundResult(true, $amount);
            }

            public function verifyWebhook(Request $request): ?\anvildev\booked\payments\WebhookEvent
            {
                return null;
            }

            public function getFrontendConfig(ReservationInterface $reservation): array
            {
                return [];
            }

            public function supportsPartialRefunds(): bool
            {
                return true;
            }
        };
    }
}

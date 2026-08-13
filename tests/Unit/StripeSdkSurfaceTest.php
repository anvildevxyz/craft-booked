<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The Stripe SDK surface {@see \anvildev\booked\gateways\StripeGateway} stands on.
 *
 * composer.json accepts stripe/stripe-php v13 through v21, so Booked can be
 * installed next to plugins that cap the same SDK lower — Freeform at ^15,
 * Formie at ^16, Craft Commerce's Stripe gateway at ^13. The lock pins the
 * ceiling, so nothing else in the suite would notice the floor breaking.
 *
 * These assertions are the promise that range makes. They exercise the SDK
 * itself rather than the adapter, and reach no network: the client is built but
 * never called, and the webhook payload is signed here.
 */
class StripeSdkSurfaceTest extends TestCase
{
    private const SECRET = 'whsec_test_surface';

    public function testClientTakesABareApiKey(): void
    {
        // StripeGateway::client() passes the secret key as a string, not a config array.
        $client = new StripeClient('sk_test_surface');

        $this->assertInstanceOf(StripeClient::class, $client);
    }

    /**
     * @dataProvider serviceMethodProvider
     */
    public function testClientExposesTheServicesTheGatewayCalls(string $service, string $method): void
    {
        $client = new StripeClient('sk_test_surface');

        $this->assertTrue(
            method_exists($client->$service, $method),
            "stripe-php no longer exposes {$service}->{$method}()",
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function serviceMethodProvider(): array
    {
        return [
            'createPayment' => ['paymentIntents', 'create'],
            'confirmPayment' => ['paymentIntents', 'retrieve'],
            'refund' => ['refunds', 'create'],
        ];
    }

    /**
     * Both service calls pass an options array second (the idempotency key), so
     * a one-argument signature would break the adapter without failing above.
     *
     * @dataProvider serviceMethodProvider
     */
    public function testTheServiceMethodsTakeAnOptionsArgument(string $service, string $method): void
    {
        $client = new StripeClient('sk_test_surface');
        $reflection = new \ReflectionMethod($client->$service, $method);

        $this->assertGreaterThanOrEqual(
            2,
            $reflection->getNumberOfParameters(),
            "{$service}->{$method}() no longer accepts request options",
        );
    }

    public function testConstructEventVerifiesASignedPayload(): void
    {
        $payload = self::payload();
        $event = Webhook::constructEvent($payload, self::signatureFor($payload), self::SECRET);

        // Every property verifyWebhook() reads off the event.
        $this->assertSame('evt_surface', $event->id);
        $this->assertSame('charge.refunded', $event->type);
        $this->assertIsArray($event->toArray());

        $object = $event->data->object;
        $this->assertIsObject($object);
        $this->assertSame('pi_surface', $object->payment_intent);
        $this->assertSame(500, $object->amount_refunded);
        $this->assertSame(2000, $object->amount);
        $this->assertSame('ch_surface', $object->id);
    }

    public function testConstructEventRejectsATamperedSignature(): void
    {
        $payload = self::payload();
        $signature = self::signatureFor('{"tampered":true}');

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($payload, $signature, self::SECRET);
    }

    public function testPaymentIntentExposesTheFieldsTheAdapterReads(): void
    {
        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_surface',
            'status' => 'succeeded',
            'amount' => 2000,
            'client_secret' => 'pi_surface_secret',
        ]);

        $this->assertSame('pi_surface', $intent->id);
        $this->assertSame('succeeded', $intent->status);
        $this->assertSame(2000, $intent->amount);
        $this->assertSame('pi_surface_secret', $intent->client_secret);
        $this->assertSame('pi_surface', $intent->toArray()['id']);
    }

    private static function payload(): string
    {
        return json_encode([
            'id' => 'evt_surface',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_surface',
                    'object' => 'charge',
                    'payment_intent' => 'pi_surface',
                    'amount' => 2000,
                    'amount_refunded' => 500,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** A `Stripe-Signature` header for the payload, built the way Stripe builds it. */
    private static function signatureFor(string $payload): string
    {
        $timestamp = time();
        $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        return "t={$timestamp},v1={$hash}";
    }
}

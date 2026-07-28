<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\Booked;
use anvildev\booked\models\Settings;
use anvildev\booked\tests\Support\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Payments settings tab wiring (the CP UI for `paymentMode` + Stripe keys).
 *
 * The full render/save is verified live in the CP; these assert every piece of
 * the wiring exists so a future change can't silently drop the tab (which would
 * leave payments only configurable via the database).
 */
class PaymentsSettingsTabTest extends TestCase
{
    private static function srcDir(): string
    {
        return dirname((new ReflectionClass(Booked::class))->getFileName());
    }

    public function testControllerActionExists(): void
    {
        $this->assertTrue(
            method_exists('anvildev\booked\controllers\cp\SettingsController', 'actionPayments'),
            'SettingsController::actionPayments() must exist',
        );
        $rm = new ReflectionMethod('anvildev\booked\controllers\cp\SettingsController', 'actionPayments');
        $src = implode('', array_slice(file($rm->getFileName()), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
        $this->assertStringContainsString("booked/settings/payments", $src);
        $this->assertStringContainsString('webhookUrl', $src, 'the tab should surface the webhook endpoint URL');
    }

    public function testTemplateExists(): void
    {
        $this->assertFileExists(self::srcDir() . '/templates/settings/payments.twig');
    }

    public function testRouteRegistered(): void
    {
        $src = file_get_contents(self::srcDir() . '/Booked.php');
        $this->assertStringContainsString("'booked/settings/payments' => 'booked/cp/settings/payments'", $src);
    }

    public function testSidebarLinksTheTab(): void
    {
        $src = file_get_contents(self::srcDir() . '/templates/settings/_sidebar.twig');
        $this->assertStringContainsString("'payments'", $src);
        $this->assertStringContainsString('booked/settings/payments', $src);
    }

    public function testSafeAttributesCoverEveryEditedField(): void
    {
        $settings = new Settings();
        $safe = $settings->safeAttributesForTab('payments');
        foreach (['paymentMode', 'stripePublishableKey', 'stripeSecretKey', 'stripeWebhookSecret', 'pendingPaymentTtlMinutes', 'defaultCurrency'] as $attr) {
            $this->assertContains($attr, $safe, "'{$attr}' must be savable on the payments tab (else the form silently drops it)");
        }
    }

    public function testEnglishAndGermanLabelsExist(): void
    {
        foreach (['en', 'de'] as $locale) {
            $t = require self::srcDir() . "/translations/{$locale}/booked.php";
            foreach (['settings.sidebar.payments', 'settings.payments.title', 'settings.payments.mode.label', 'settings.payments.stripe.secretKey'] as $key) {
                $this->assertArrayHasKey($key, $t, "{$locale}: missing '{$key}'");
            }
        }
    }
}

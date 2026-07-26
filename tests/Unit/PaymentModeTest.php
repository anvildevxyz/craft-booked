<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\models\Settings;
use anvildev\booked\tests\Support\TestCase;

/**
 * Pure unit tests for payment-mode resolution + the automatic migration from
 * the legacy `commerceEnabled` flag. No Craft init — getPaymentMode() and
 * isDirectPayment() are edition- and app-agnostic (the Pro/commerce gate lives
 * in isCommerceEnabled(), which needs Craft and is asserted structurally).
 */
class PaymentModeTest extends TestCase
{
    public function testExplicitModeWins(): void
    {
        $s = new Settings();
        $s->paymentMode = Settings::PAYMENT_MODE_DIRECT;
        $this->assertSame('direct', $s->getPaymentMode());
        $this->assertTrue($s->isDirectPayment());
    }

    public function testExplicitNoneIsNotDirect(): void
    {
        $s = new Settings();
        $s->paymentMode = Settings::PAYMENT_MODE_NONE;
        $this->assertSame('none', $s->getPaymentMode());
        $this->assertFalse($s->isDirectPayment());
    }

    public function testLegacyCommerceEnabledMigratesToCommerce(): void
    {
        // Existing paid install: commerceEnabled=true, no explicit mode.
        $s = new Settings();
        $s->paymentMode = null;
        $s->commerceEnabled = true;
        $this->assertSame('commerce', $s->getPaymentMode());
    }

    public function testLegacyFreeInstallMigratesToNone(): void
    {
        $s = new Settings();
        $s->paymentMode = null;
        $s->commerceEnabled = false;
        $this->assertSame('none', $s->getPaymentMode());
    }

    public function testInvalidModeFallsBackToLegacy(): void
    {
        $s = new Settings();
        $s->paymentMode = 'bogus';
        $s->commerceEnabled = true;
        $this->assertSame('commerce', $s->getPaymentMode());
    }
}

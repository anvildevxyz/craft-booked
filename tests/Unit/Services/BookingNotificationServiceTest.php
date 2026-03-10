<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\BookingNotificationService;
use anvildev\booked\tests\Support\TestCase;

/**
 * BookingNotificationService Test
 *
 * All methods require Craft::$app->getQueue() or Booked::getInstance()->getSettings()
 * and are tested via integration tests. This file covers service structure only.
 */
class BookingNotificationServiceTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    public function testServiceIsComponent(): void
    {
        $service = new BookingNotificationService();
        $this->assertInstanceOf(BookingNotificationService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new BookingNotificationService();
        $this->assertTrue(method_exists($service, 'queueBookingEmail'));
        $this->assertTrue(method_exists($service, 'queueCalendarSync'));
        $this->assertTrue(method_exists($service, 'queueOwnerNotification'));
        $this->assertTrue(method_exists($service, 'queueCancellationNotification'));
        $this->assertTrue(method_exists($service, 'queueSmsConfirmation'));
        $this->assertTrue(method_exists($service, 'queueSmsCancellation'));
    }
}

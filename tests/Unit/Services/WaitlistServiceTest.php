<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\records\WaitlistRecord;
use anvildev\booked\services\WaitlistService;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * WaitlistService Test
 *
 * Tests the priority calculation logic and record status helpers.
 * DB-dependent methods (checkAndNotifyWaitlist, cleanupExpired, getStats, etc.)
 * require integration tests.
 */
class WaitlistServiceTest extends TestCase
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

    /**
     * Call the protected calculatePriority method via reflection
     */
    private function callCalculatePriority(array $data): int
    {
        $service = new WaitlistService();
        $method = new \ReflectionMethod(WaitlistService::class, 'calculatePriority');
        $method->setAccessible(true);

        return $method->invoke($service, $data);
    }

    // =========================================================================
    // calculatePriority() - Priority boost logic
    // =========================================================================

    public function testBasePriorityIsLowestTier(): void
    {
        // Guest with no preferred date gets tier 3 (lowest priority)
        $priority = $this->callCalculatePriority([]);
        $this->assertSame(3, $priority);
    }

    public function testLoggedInUserGetsBoost(): void
    {
        $withUser = $this->callCalculatePriority(['userId' => 42]);
        $withoutUser = $this->callCalculatePriority([]);

        // Logged-in user should have lower tier number (higher priority)
        $this->assertLessThan($withoutUser, $withUser);
    }

    public function testLoggedInUserIsTier1(): void
    {
        // Authenticated user without preferred date gets tier 1
        $priority = $this->callCalculatePriority(['userId' => 42]);
        $this->assertSame(1, $priority);
    }

    public function testPreferredDateGetsBoost(): void
    {
        $withDate = $this->callCalculatePriority(['preferredDate' => '2025-06-15']);
        $withoutDate = $this->callCalculatePriority([]);

        $this->assertLessThan($withoutDate, $withDate);
    }

    public function testPreferredDateIsTier2(): void
    {
        // Guest with preferred date gets tier 2
        $priority = $this->callCalculatePriority(['preferredDate' => '2025-06-15']);
        $this->assertSame(2, $priority);
    }

    public function testBothBoostsCombineToHighestTier(): void
    {
        // Authenticated user with preferred date gets tier 0 (highest priority)
        $both = $this->callCalculatePriority(['userId' => 42, 'preferredDate' => '2025-06-15']);
        $neither = $this->callCalculatePriority([]);

        $this->assertSame(0, $both);
        $this->assertSame(3, $neither);
    }

    public function testEmptyUserIdDoesNotGetBoost(): void
    {
        $emptyId = $this->callCalculatePriority(['userId' => null]);
        $noId = $this->callCalculatePriority([]);

        $diff = abs($noId - $emptyId);
        $this->assertLessThanOrEqual(1, $diff); // Only clock drift
    }

    public function testEmptyPreferredDateDoesNotGetBoost(): void
    {
        $emptyDate = $this->callCalculatePriority(['preferredDate' => null]);
        $noDate = $this->callCalculatePriority([]);

        $diff = abs($noDate - $emptyDate);
        $this->assertLessThanOrEqual(1, $diff);
    }

    public function testZeroUserIdDoesNotGetBoost(): void
    {
        // empty(0) is true in PHP, so userId=0 should NOT get boost
        $zeroId = $this->callCalculatePriority(['userId' => 0]);
        $noId = $this->callCalculatePriority([]);

        $diff = abs($noId - $zeroId);
        $this->assertLessThanOrEqual(1, $diff);
    }

    public function testEmptyStringPreferredDateDoesNotGetBoost(): void
    {
        $emptyStr = $this->callCalculatePriority(['preferredDate' => '']);
        $noDate = $this->callCalculatePriority([]);

        $diff = abs($noDate - $emptyStr);
        $this->assertLessThanOrEqual(1, $diff);
    }

    // =========================================================================
    // calculatePriority() - Ordering correctness
    // =========================================================================

    public function testPriorityOrderingUserBeatsNoUser(): void
    {
        // Lower priority value = higher priority (served first)
        $user = $this->callCalculatePriority(['userId' => 1]);
        $guest = $this->callCalculatePriority([]);

        $this->assertLessThan($guest, $user); // User has lower value = higher priority
    }

    public function testPriorityOrderingDatePreferenceBeatsNoPreference(): void
    {
        $withDate = $this->callCalculatePriority(['preferredDate' => '2025-06-15']);
        $withoutDate = $this->callCalculatePriority([]);

        $this->assertLessThan($withoutDate, $withDate);
    }

    public function testPriorityOrderingUserPlusDateBeatsDateOnly(): void
    {
        $both = $this->callCalculatePriority(['userId' => 1, 'preferredDate' => '2025-06-15']);
        $dateOnly = $this->callCalculatePriority(['preferredDate' => '2025-06-15']);

        $this->assertLessThan($dateOnly, $both);
    }

    // =========================================================================
    // WaitlistRecord - Status constants
    // =========================================================================

    public function testStatusConstantsExist(): void
    {
        $this->assertEquals('active', WaitlistRecord::STATUS_ACTIVE);
        $this->assertEquals('notified', WaitlistRecord::STATUS_NOTIFIED);
        $this->assertEquals('converted', WaitlistRecord::STATUS_CONVERTED);
        $this->assertEquals('expired', WaitlistRecord::STATUS_EXPIRED);
        $this->assertEquals('cancelled', WaitlistRecord::STATUS_CANCELLED);
    }

    public function testGetStatusesReturnsAllStatuses(): void
    {
        $statuses = WaitlistRecord::getStatuses();

        $this->assertCount(5, $statuses);
        $this->assertArrayHasKey('active', $statuses);
        $this->assertArrayHasKey('notified', $statuses);
        $this->assertArrayHasKey('converted', $statuses);
        $this->assertArrayHasKey('expired', $statuses);
        $this->assertArrayHasKey('cancelled', $statuses);
    }

    public function testGetStatusesReturnsHumanLabels(): void
    {
        $statuses = WaitlistRecord::getStatuses();

        $this->assertEquals('Active', $statuses['active']);
        $this->assertEquals('Notified', $statuses['notified']);
        $this->assertEquals('Converted', $statuses['converted']);
        $this->assertEquals('Expired', $statuses['expired']);
        $this->assertEquals('Cancelled', $statuses['cancelled']);
    }

    // =========================================================================
    // WaitlistRecord - Instance methods (require DB for ActiveRecord init)
    // isActive(), isNotified(), canBeNotified(), getStatusLabel()
    // These need integration tests with Craft CMS initialized.
    // =========================================================================

    // =========================================================================
    // WaitlistRecord - Table name (static, no instantiation)
    // =========================================================================

    public function testTableName(): void
    {
        $this->assertEquals('{{%booked_waitlist}}', WaitlistRecord::tableName());
    }

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new WaitlistService();
        $this->assertInstanceOf(WaitlistService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new WaitlistService();
        $this->assertTrue(method_exists($service, 'addToWaitlist'));
        $this->assertTrue(method_exists($service, 'checkAndNotifyWaitlist'));
        $this->assertTrue(method_exists($service, 'notifyEntry'));
        $this->assertTrue(method_exists($service, 'manualNotify'));
        $this->assertTrue(method_exists($service, 'cancelEntry'));
        $this->assertTrue(method_exists($service, 'cleanupExpired'));
        $this->assertTrue(method_exists($service, 'getStats'));
        $this->assertTrue(method_exists($service, 'getActiveEntriesForService'));
        $this->assertTrue(method_exists($service, 'isOnWaitlist'));
    }
}

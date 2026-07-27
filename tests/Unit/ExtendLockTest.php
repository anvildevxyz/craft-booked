<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;
use ReflectionMethod;

class ExtendLockTest extends TestCase
{
    public function testExtendLockReportsClampedExpiryNotFullDuration(): void
    {
        $rm = new ReflectionMethod('anvildev\booked\controllers\SlotController', 'actionExtendLock');
        $lines = file($rm->getFileName());
        $src = implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));

        // expiresIn is derived from the real (clamped) expiry, not the requested duration.
        $this->assertStringContainsString('$newExpiry->getTimestamp()', $src);
        $this->assertStringNotContainsString("'expiresIn' => \$durationMinutes * 60", $src);
    }
}

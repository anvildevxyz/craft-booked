<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\records\PaymentRecord;
use anvildev\booked\tests\Support\TestCase;

class PaymentRecordTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%booked_payments}}', PaymentRecord::tableName());
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('pending', PaymentRecord::STATUS_PENDING);
        $this->assertSame('paid', PaymentRecord::STATUS_PAID);
        $this->assertSame('refunded', PaymentRecord::STATUS_REFUNDED);
        $this->assertSame('partiallyRefunded', PaymentRecord::STATUS_PARTIALLY_REFUNDED);
    }
}

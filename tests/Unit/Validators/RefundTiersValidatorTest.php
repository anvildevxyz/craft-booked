<?php

namespace anvildev\booked\tests\Unit\Validators;

use anvildev\booked\tests\Support\TestCase;
use anvildev\booked\validators\RefundTiersValidator;

class RefundTiersValidatorTest extends TestCase
{
    private RefundTiersValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RefundTiersValidator();
    }

    public function testNullIsValid(): void
    {
        $this->assertTrue($this->validator->isValid(null));
    }

    public function testEmptyArrayIsValid(): void
    {
        $this->assertTrue($this->validator->isValid([]));
    }

    public function testValidSingleTier(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testValidMultipleTiers(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => 24, 'refundPercentage' => 50],
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testStringIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('not an array'));
    }

    public function testIntegerIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid(42));
    }

    public function testTierWithoutHoursBeforeStartIsInvalid(): void
    {
        $tiers = [
            ['refundPercentage' => 100],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testTierWithoutRefundPercentageIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNegativeHoursIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => -1, 'refundPercentage' => 100],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNegativePercentageIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => -10],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testPercentageOver100IsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 101],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNonNumericHoursIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 'abc', 'refundPercentage' => 100],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNonNumericPercentageIsInvalid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 'full'],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testNonArrayTierIsInvalid(): void
    {
        $tiers = ['not a tier'];
        $this->assertFalse($this->validator->isValid($tiers));
    }

    public function testZeroHoursIsValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 0, 'refundPercentage' => 0],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testBoundaryPercentage100IsValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testBoundaryPercentage0IsValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 0],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testFloatHoursAreValid(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 2.5, 'refundPercentage' => 50],
        ];
        $this->assertTrue($this->validator->isValid($tiers));
    }

    public function testMixedValidAndInvalidTiers(): void
    {
        $tiers = [
            ['hoursBeforeStart' => 48, 'refundPercentage' => 100],
            ['hoursBeforeStart' => -1, 'refundPercentage' => 50],
        ];
        $this->assertFalse($this->validator->isValid($tiers));
    }
}

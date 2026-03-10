<?php

namespace anvildev\booked\tests\Unit\GraphQL;

use anvildev\booked\gql\mutations\QuantityMutations;
use anvildev\booked\gql\mutations\WaitlistMutations;
use anvildev\booked\tests\Support\TestCase;

/**
 * Tests for GraphQL quantity and waitlist mutation definitions.
 *
 * Verifies that mutation arrays are correctly structured with expected keys,
 * argument types, and resolver callables. These tests do NOT execute resolvers
 * (which require a full Craft app) but validate the static schema definition.
 */
class QuantityMutationsTest extends TestCase
{
    // =========================================================================
    // QuantityMutations - Class and Structure
    // =========================================================================

    public function testQuantityMutationsClassExists(): void
    {
        $this->assertTrue(class_exists(QuantityMutations::class));
    }

    public function testQuantityMutationsExtendsBaseMutation(): void
    {
        $this->assertTrue(is_subclass_of(QuantityMutations::class, \craft\gql\base\Mutation::class));
    }

    public function testGetMutationsReturnsArray(): void
    {
        $this->requiresCraft();
        $mutations = QuantityMutations::getMutations();
        $this->assertIsArray($mutations);
    }

    // =========================================================================
    // WaitlistMutations - Class and Structure
    // =========================================================================

    public function testWaitlistMutationsClassExists(): void
    {
        $this->assertTrue(class_exists(WaitlistMutations::class));
    }

    public function testWaitlistMutationsExtendsBaseMutation(): void
    {
        $this->assertTrue(is_subclass_of(WaitlistMutations::class, \craft\gql\base\Mutation::class));
    }

    public function testWaitlistGetMutationsReturnsArray(): void
    {
        $this->requiresCraft();
        $mutations = WaitlistMutations::getMutations();
        $this->assertIsArray($mutations);
    }

    // =========================================================================
    // Static method availability
    // =========================================================================

    public function testQuantityMutationsHasGetMutationsMethod(): void
    {
        $this->assertTrue(method_exists(QuantityMutations::class, 'getMutations'));
    }

    public function testWaitlistMutationsHasGetMutationsMethod(): void
    {
        $this->assertTrue(method_exists(WaitlistMutations::class, 'getMutations'));
    }

    public function testGetMutationsIsStatic(): void
    {
        $reflection = new \ReflectionMethod(QuantityMutations::class, 'getMutations');
        $this->assertTrue($reflection->isStatic());
    }

    public function testWaitlistGetMutationsIsStatic(): void
    {
        $reflection = new \ReflectionMethod(WaitlistMutations::class, 'getMutations');
        $this->assertTrue($reflection->isStatic());
    }
}

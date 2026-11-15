<?php

namespace anvildev\booked\tests\Unit\Gql;

use anvildev\booked\gql\interfaces\elements\ReservationInterface as GqlReservationInterface;
use anvildev\booked\gql\mutations\ReservationMutations;
use anvildev\booked\gql\types\input\CreateReservationInput;
use anvildev\booked\gql\types\input\UpdateReservationInput;
use anvildev\booked\tests\Support\TestCase;
use ReflectionMethod;

/**
 * There is no custom JSON scalar registered in this schema, so fieldValues
 * (#146) travels as a JSON object string both ways — the same one-way
 * convention ScheduleInterface::workingHours already uses for output, here
 * also decoded on input. Behavioural proof against a real resolver and a
 * real database lives in
 * tests/integration-live/graphql-reservation-field-values.php.
 */
class ReservationMutationsFieldValuesTest extends TestCase
{
    private function decodeFieldValues(?string $json): array
    {
        $method = new ReflectionMethod(ReservationMutations::class, 'decodeFieldValues');
        $method->setAccessible(true);

        return $method->invoke(null, $json);
    }

    public function testNullDecodesToAnEmptyArray(): void
    {
        $this->assertSame([], $this->decodeFieldValues(null));
    }

    public function testEmptyStringDecodesToAnEmptyArray(): void
    {
        $this->assertSame([], $this->decodeFieldValues(''));
    }

    public function testAValidJsonObjectDecodesToItsArray(): void
    {
        $this->assertSame(
            ['tShirtSize' => 'Large', 'waiverAccepted' => true],
            $this->decodeFieldValues('{"tShirtSize":"Large","waiverAccepted":true}'),
        );
    }

    /**
     * Malformed JSON from a client is dropped, not thrown — the same
     * fail-safe posture as every other field this resolver never received.
     */
    public function testMalformedJsonDecodesToAnEmptyArray(): void
    {
        $this->assertSame([], $this->decodeFieldValues('{not valid json'));
    }

    public function testAJsonScalarDecodesToAnEmptyArray(): void
    {
        $this->assertSame([], $this->decodeFieldValues('"just a string"'));
    }

    /**
     * getType() needs Craft::$app (GqlEntityRegistry), so this checks the
     * field declaration in source rather than building the real type.
     */
    public function testCreateReservationInputDeclaresFieldValues(): void
    {
        $source = $this->sourceOfMethod(CreateReservationInput::class, 'getType');
        $this->assertStringContainsString("'fieldValues' => [", $source);
    }

    public function testUpdateReservationInputDeclaresFieldValues(): void
    {
        $source = $this->sourceOfMethod(UpdateReservationInput::class, 'getType');
        $this->assertStringContainsString("'fieldValues' => [", $source);
    }

    public function testResolveCreateForwardsDecodedFieldValues(): void
    {
        $source = $this->sourceOfMethod(ReservationMutations::class, 'resolveCreate');
        $this->assertStringContainsString("'fieldValues' => self::decodeFieldValues(\$input['fieldValues'] ?? null)", $source);
    }

    public function testResolveUpdateAppliesDecodedFieldValues(): void
    {
        $source = $this->sourceOfMethod(ReservationMutations::class, 'resolveUpdate');
        $this->assertStringContainsString('setFieldValues(self::decodeFieldValues(', $source);
    }

    /**
     * getFieldDefinitions() needs Craft::$app (Craft::$app->getGql()), so
     * this checks the field declaration in source rather than the real type.
     */
    public function testReservationInterfaceDeclaresFieldValues(): void
    {
        $source = $this->sourceOfMethod(GqlReservationInterface::class, 'getFieldDefinitions');
        $this->assertStringContainsString("'fieldValues' => [", $source);
        $this->assertStringContainsString("method_exists(\$source, 'getFieldValues')", $source);
    }
}

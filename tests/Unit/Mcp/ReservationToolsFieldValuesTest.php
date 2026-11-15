<?php

namespace anvildev\booked\tests\Unit\Mcp;

use anvildev\booked\mcp\ReservationTools;
use anvildev\booked\mcp\support\Presenter;
use anvildev\booked\tests\Support\TestCase;
use ReflectionMethod;

/**
 * booked_create_booking/booked_update_reservation accept answers to the
 * booked service's custom questions (#146) the same way
 * CatalogTools::createEmployee() already accepts $workingHours — a generic
 * object parameter, since the actual fields vary per service and can't be
 * declared individually. Behavioural proof (a real MCP call against a real
 * database, settings toggle included) lives in
 * tests/integration-live/mcp-reservation-field-values.php.
 */
class ReservationToolsFieldValuesTest extends TestCase
{
    /**
     * @dataProvider methodsWithFieldValuesProvider
     */
    public function testMethodAcceptsFieldValuesAsAGenericObjectParam(string $method): void
    {
        $parameters = (new ReflectionMethod(ReservationTools::class, $method))->getParameters();

        $fieldValuesParam = null;
        foreach ($parameters as $parameter) {
            if ($parameter->getName() === 'fieldValues') {
                $fieldValuesParam = $parameter;
                break;
            }
        }

        $this->assertNotNull($fieldValuesParam, "{$method}() must declare a \$fieldValues parameter");
        $this->assertTrue($fieldValuesParam->allowsNull());
        $this->assertTrue($fieldValuesParam->isOptional());
        $this->assertSame('array', (string) $fieldValuesParam->getType()->getName());

        $schemaAttributes = $fieldValuesParam->getAttributes('Mcp\\Capability\\Attribute\\Schema');
        $this->assertNotEmpty($schemaAttributes, "{$method}()'s \$fieldValues must be annotated #[Schema(type: 'object')]");
        $this->assertSame('object', $schemaAttributes[0]->getArguments()['type'] ?? null);
    }

    public static function methodsWithFieldValuesProvider(): array
    {
        return [
            'createBooking' => ['createBooking'],
            'updateReservation' => ['updateReservation'],
        ];
    }

    public function testCreateBookingForwardsFieldValuesToTheDataArray(): void
    {
        $source = $this->sourceOfMethod(ReservationTools::class, 'createBooking');
        $this->assertStringContainsString("'fieldValues' => \$fieldValues", $source);
    }

    public function testUpdateReservationForwardsFieldValuesToTheDataArray(): void
    {
        $source = $this->sourceOfMethod(ReservationTools::class, 'updateReservation');
        $this->assertStringContainsString("'fieldValues' => \$fieldValues", $source);
    }

    public function testPresenterIncludesFieldValuesGuardedByMethodExists(): void
    {
        $source = $this->sourceOfMethod(Presenter::class, 'reservation');
        $this->assertStringContainsString("method_exists(\$reservation, 'getFieldValues')", $source);
        $this->assertStringContainsString("'fieldValues' => \$fieldValues", $source);
    }
}

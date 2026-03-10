<?php

namespace anvildev\booked\tests\Unit\GraphQL;

use anvildev\booked\gql\interfaces\elements\BlackoutDateInterface;
use anvildev\booked\gql\interfaces\elements\EmployeeInterface;
use anvildev\booked\gql\interfaces\elements\EventDateInterface;
use anvildev\booked\gql\interfaces\elements\LocationInterface;
use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use anvildev\booked\gql\interfaces\elements\ScheduleInterface;
use anvildev\booked\gql\interfaces\elements\ServiceInterface;
use anvildev\booked\tests\Support\TestCase;

/**
 * GraphQL Contract Test
 *
 * Tests that GraphQL type definitions maintain their contract.
 * These tests verify field names and interface names don't change unexpectedly.
 */
class GraphQLContractTest extends TestCase
{
    // =========================================================================
    // Interface Name Tests - Ensures API contracts are stable
    // =========================================================================

    public function testReservationInterfaceName(): void
    {
        $this->assertEquals('ReservationInterface', ReservationInterface::getName());
    }

    public function testServiceInterfaceName(): void
    {
        $this->assertEquals('ServiceInterface', ServiceInterface::getName());
    }

    public function testEmployeeInterfaceName(): void
    {
        $this->assertEquals('EmployeeInterface', EmployeeInterface::getName());
    }

    public function testScheduleInterfaceName(): void
    {
        $this->assertEquals('ScheduleInterface', ScheduleInterface::getName());
    }

    // =========================================================================
    // Type Generator Tests - Ensures types are properly configured
    // =========================================================================

    public function testReservationInterfaceHasTypeGenerator(): void
    {
        $generator = ReservationInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('ReservationType', $generator);
    }

    public function testServiceInterfaceHasTypeGenerator(): void
    {
        $generator = ServiceInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('ServiceType', $generator);
    }

    public function testEmployeeInterfaceHasTypeGenerator(): void
    {
        $generator = EmployeeInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('EmployeeType', $generator);
    }

    public function testScheduleInterfaceHasTypeGenerator(): void
    {
        $generator = ScheduleInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('ScheduleType', $generator);
    }

    // =========================================================================
    // Input Type Class Existence Tests
    // =========================================================================

    public function testCreateReservationInputClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\input\CreateReservationInput::class),
            'CreateReservationInput class should exist'
        );
    }

    public function testUpdateReservationInputClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\input\UpdateReservationInput::class),
            'UpdateReservationInput class should exist'
        );
    }

    // =========================================================================
    // Query Class Existence Tests
    // =========================================================================

    public function testReservationQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\ReservationQuery::class),
            'ReservationQuery class should exist'
        );
    }

    public function testServiceQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\ServiceQuery::class),
            'ServiceQuery class should exist'
        );
    }

    public function testEmployeeQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\EmployeeQuery::class),
            'EmployeeQuery class should exist'
        );
    }

    public function testScheduleQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\ScheduleQuery::class),
            'ScheduleQuery class should exist'
        );
    }

    public function testServiceExtrasQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\ServiceExtrasQuery::class),
            'ServiceExtrasQuery class should exist'
        );
    }

    // =========================================================================
    // Resolver Class Existence Tests
    // =========================================================================

    public function testReservationResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\ReservationResolver::class),
            'ReservationResolver class should exist'
        );
    }

    public function testServiceResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\ServiceResolver::class),
            'ServiceResolver class should exist'
        );
    }

    public function testEmployeeResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\EmployeeResolver::class),
            'EmployeeResolver class should exist'
        );
    }

    public function testScheduleResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\ScheduleResolver::class),
            'ScheduleResolver class should exist'
        );
    }

    // =========================================================================
    // Mutation Class Existence Tests
    // =========================================================================

    public function testReservationMutationsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\mutations\ReservationMutations::class),
            'ReservationMutations class should exist'
        );
    }

    // =========================================================================
    // Type Generator Class Existence Tests
    // =========================================================================

    public function testReservationTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\ReservationType::class),
            'ReservationType generator class should exist'
        );
    }

    public function testServiceTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\ServiceType::class),
            'ServiceType generator class should exist'
        );
    }

    public function testEmployeeTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\EmployeeType::class),
            'EmployeeType generator class should exist'
        );
    }

    public function testScheduleTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\ScheduleType::class),
            'ScheduleType generator class should exist'
        );
    }

    // =========================================================================
    // Element Type Class Existence Tests
    // =========================================================================

    public function testReservationElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\Reservation::class),
            'Reservation element type class should exist'
        );
    }

    public function testServiceElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\Service::class),
            'Service element type class should exist'
        );
    }

    public function testEmployeeElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\Employee::class),
            'Employee element type class should exist'
        );
    }

    public function testScheduleElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\Schedule::class),
            'Schedule element type class should exist'
        );
    }

    // =========================================================================
    // Arguments Class Existence Tests
    // =========================================================================

    public function testReservationArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\ReservationArguments::class),
            'ReservationArguments class should exist'
        );
    }

    public function testServiceArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\ServiceArguments::class),
            'ServiceArguments class should exist'
        );
    }

    public function testEmployeeArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\EmployeeArguments::class),
            'EmployeeArguments class should exist'
        );
    }

    public function testScheduleArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\ScheduleArguments::class),
            'ScheduleArguments class should exist'
        );
    }

    // =========================================================================
    // Special Types Existence Tests
    // =========================================================================

    public function testMutationErrorTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\MutationError::class),
            'MutationError type class should exist'
        );
    }

    public function testServiceExtraTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\ServiceExtraType::class),
            'ServiceExtraType class should exist'
        );
    }

    public function testReservationExtraTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\ReservationExtraType::class),
            'ReservationExtraType class should exist'
        );
    }

    // =========================================================================
    // CreateReservationInput Method Tests
    // =========================================================================

    public function testCreateReservationInputHasGetTypeMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\gql\types\input\CreateReservationInput::class, 'getType'),
            'CreateReservationInput should have getType() method'
        );
    }

    public function testUpdateReservationInputHasGetTypeMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\gql\types\input\UpdateReservationInput::class, 'getType'),
            'UpdateReservationInput should have getType() method'
        );
    }

    // =========================================================================
    // Location GraphQL Tests
    // =========================================================================

    public function testLocationInterfaceName(): void
    {
        $this->assertEquals('LocationInterface', LocationInterface::getName());
    }

    public function testLocationInterfaceHasTypeGenerator(): void
    {
        $generator = LocationInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('LocationType', $generator);
    }

    public function testLocationQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\LocationQuery::class),
            'LocationQuery class should exist'
        );
    }

    public function testLocationResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\LocationResolver::class),
            'LocationResolver class should exist'
        );
    }

    public function testLocationTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\LocationType::class),
            'LocationType generator class should exist'
        );
    }

    public function testLocationElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\Location::class),
            'Location element type class should exist'
        );
    }

    public function testLocationArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\LocationArguments::class),
            'LocationArguments class should exist'
        );
    }

    // =========================================================================
    // EventDate GraphQL Tests
    // =========================================================================

    public function testEventDateInterfaceName(): void
    {
        $this->assertEquals('EventDateInterface', EventDateInterface::getName());
    }

    public function testEventDateInterfaceHasTypeGenerator(): void
    {
        $generator = EventDateInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('EventDateType', $generator);
    }

    public function testEventDateQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\EventDateQuery::class),
            'EventDateQuery class should exist'
        );
    }

    public function testEventDateResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\EventDateResolver::class),
            'EventDateResolver class should exist'
        );
    }

    public function testEventDateTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\EventDateType::class),
            'EventDateType generator class should exist'
        );
    }

    public function testEventDateElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\EventDate::class),
            'EventDate element type class should exist'
        );
    }

    public function testEventDateArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\EventDateArguments::class),
            'EventDateArguments class should exist'
        );
    }

    // =========================================================================
    // BlackoutDate GraphQL Tests
    // =========================================================================

    public function testBlackoutDateInterfaceName(): void
    {
        $this->assertEquals('BlackoutDateInterface', BlackoutDateInterface::getName());
    }

    public function testBlackoutDateInterfaceHasTypeGenerator(): void
    {
        $generator = BlackoutDateInterface::getTypeGenerator();

        $this->assertNotEmpty($generator);
        $this->assertStringContainsString('BlackoutDateType', $generator);
    }

    public function testBlackoutDateQueryClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\queries\BlackoutDateQuery::class),
            'BlackoutDateQuery class should exist'
        );
    }

    public function testBlackoutDateResolverClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\resolvers\elements\BlackoutDateResolver::class),
            'BlackoutDateResolver class should exist'
        );
    }

    public function testBlackoutDateTypeGeneratorExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\generators\BlackoutDateType::class),
            'BlackoutDateType generator class should exist'
        );
    }

    public function testBlackoutDateElementTypeExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\types\elements\BlackoutDate::class),
            'BlackoutDate element type class should exist'
        );
    }

    public function testBlackoutDateArgumentsClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\gql\arguments\elements\BlackoutDateArguments::class),
            'BlackoutDateArguments class should exist'
        );
    }
}

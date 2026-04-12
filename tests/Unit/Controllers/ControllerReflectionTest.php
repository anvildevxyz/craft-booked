<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\tests\Support\TestCase;
use ReflectionClass;

class ControllerReflectionTest extends TestCase
{
    /**
     * @dataProvider allControllerProvider
     */
    public function testControllerExtendsCraftController(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->assertTrue(
            $ref->isSubclassOf(\craft\web\Controller::class),
            "{$className} should extend craft\\web\\Controller"
        );
    }

    /**
     * @dataProvider allControllerProvider
     */
    public function testActionMethodsReturnCorrectType(string $className): void
    {
        $ref = new ReflectionClass($className);
        $allowedTypes = ['craft\web\Response', 'yii\web\Response', 'Response', 'mixed'];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'action')) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $returnType = $method->getReturnType();
            $this->assertNotNull(
                $returnType,
                "{$className}::{$method->getName()} should declare a return type"
            );

            $typeName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : (string)$returnType;
            $this->assertTrue(
                in_array($typeName, $allowedTypes, true),
                "{$className}::{$method->getName()} should return Response or mixed, got {$typeName}"
            );
        }
    }

    /**
     * @dataProvider controllerTraitProvider
     */
    public function testControllerUsesExpectedTraits(string $className, array $expectedTraits): void
    {
        $ref = new ReflectionClass($className);
        $actualTraits = array_keys($ref->getTraits());

        if (empty($expectedTraits)) {
            $bookedTraits = array_filter($actualTraits, fn($t) => str_starts_with($t, 'anvildev\\booked\\'));
            $this->assertEmpty(
                $bookedTraits,
                "{$className} should not use any booked controller traits"
            );
            return;
        }

        foreach ($expectedTraits as $trait) {
            $this->assertContains(
                $trait,
                $actualTraits,
                "{$className} should use {$trait}"
            );
        }
    }

    /**
     * @dataProvider actionCountProvider
     */
    public function testControllerActionCount(string $className, int $expectedCount): void
    {
        $ref = new ReflectionClass($className);
        $actionCount = 0;

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'action') && $method->getDeclaringClass()->getName() === $className) {
                $actionCount++;
            }
        }

        $this->assertSame(
            $expectedCount,
            $actionCount,
            "{$className} should have {$expectedCount} action methods, found {$actionCount}"
        );
    }

    public static function allControllerProvider(): array
    {
        return array_map(fn($class) => [$class], self::allControllerClasses());
    }

    public static function controllerTraitProvider(): array
    {
        $json = 'anvildev\booked\controllers\traits\JsonResponseTrait';
        $exc = 'anvildev\booked\controllers\traits\HandlesExceptionsTrait';
        $help = 'anvildev\booked\controllers\traits\BookingHelpersTrait';

        return [
            'BookingController' => ['anvildev\booked\controllers\BookingController', [$json, $exc, $help]],
            'SlotController' => ['anvildev\booked\controllers\SlotController', [$json, $exc, $help]],
            'WaitlistController' => ['anvildev\booked\controllers\WaitlistController', [$json, $help]],
            'BookingDataController' => ['anvildev\booked\controllers\BookingDataController', [$json, $help]],
            'BookingManagementController' => ['anvildev\booked\controllers\BookingManagementController', [$json, $help]],
            'EmployeeScheduleController' => ['anvildev\booked\controllers\EmployeeScheduleController', [$json]],
            'AccountController' => ['anvildev\booked\controllers\AccountController', [$json, $help]],
            'CalendarConnectController' => ['anvildev\booked\controllers\CalendarConnectController', []],
            'cp/DashboardController' => ['anvildev\booked\controllers\cp\DashboardController', []],
            'cp/CalendarViewController' => ['anvildev\booked\controllers\cp\CalendarViewController', [$json]],
            'cp/ReportsController' => ['anvildev\booked\controllers\cp\ReportsController', []],
            'cp/ServicesController' => ['anvildev\booked\controllers\cp\ServicesController', [$json]],
            'cp/ServiceExtraController' => ['anvildev\booked\controllers\cp\ServiceExtraController', [$json]],
            'cp/EmployeesController' => ['anvildev\booked\controllers\cp\EmployeesController', [$json]],
            'cp/SchedulesController' => ['anvildev\booked\controllers\cp\SchedulesController', []],
            'cp/LocationsController' => ['anvildev\booked\controllers\cp\LocationsController', []],
            'cp/BlackoutDatesController' => ['anvildev\booked\controllers\cp\BlackoutDatesController', []],
            'cp/EventDatesController' => ['anvildev\booked\controllers\cp\EventDatesController', [$json, $exc]],
            'cp/BookingsController' => ['anvildev\booked\controllers\cp\BookingsController', [$json, $exc]],
            'cp/SettingsController' => ['anvildev\booked\controllers\cp\SettingsController', [$json]],
            'cp/WaitlistController' => ['anvildev\booked\controllers\cp\WaitlistController', []],
            'cp/WebhooksController' => ['anvildev\booked\controllers\cp\WebhooksController', [$json]],
            'cp/CalendarController' => ['anvildev\booked\controllers\cp\CalendarController', [$json]],
        ];
    }

    public static function actionCountProvider(): array
    {
        return [
            'BookingController' => ['anvildev\booked\controllers\BookingController', 1],
            'SlotController' => ['anvildev\booked\controllers\SlotController', 9],
            'WaitlistController' => ['anvildev\booked\controllers\WaitlistController', 2],
            'BookingDataController' => ['anvildev\booked\controllers\BookingDataController', 4],
            'BookingManagementController' => ['anvildev\booked\controllers\BookingManagementController', 7],
            'EmployeeScheduleController' => ['anvildev\booked\controllers\EmployeeScheduleController', 6],
            'AccountController' => ['anvildev\booked\controllers\AccountController', 7],
            'CalendarConnectController' => ['anvildev\booked\controllers\CalendarConnectController', 4],
            'cp/DashboardController' => ['anvildev\booked\controllers\cp\DashboardController', 1],
            'cp/CalendarViewController' => ['anvildev\booked\controllers\cp\CalendarViewController', 5],
            'cp/ReportsController' => ['anvildev\booked\controllers\cp\ReportsController', 11],
            'cp/ServicesController' => ['anvildev\booked\controllers\cp\ServicesController', 4],
            'cp/ServiceExtraController' => ['anvildev\booked\controllers\cp\ServiceExtraController', 7],
            'cp/EmployeesController' => ['anvildev\booked\controllers\cp\EmployeesController', 3],
            'cp/SchedulesController' => ['anvildev\booked\controllers\cp\SchedulesController', 3],
            'cp/LocationsController' => ['anvildev\booked\controllers\cp\LocationsController', 3],
            'cp/BlackoutDatesController' => ['anvildev\booked\controllers\cp\BlackoutDatesController', 5],
            'cp/EventDatesController' => ['anvildev\booked\controllers\cp\EventDatesController', 6],
            'cp/BookingsController' => ['anvildev\booked\controllers\cp\BookingsController', 10],
            'cp/SettingsController' => ['anvildev\booked\controllers\cp\SettingsController', 14],
            'cp/WaitlistController' => ['anvildev\booked\controllers\cp\WaitlistController', 6],
            'cp/WebhooksController' => ['anvildev\booked\controllers\cp\WebhooksController', 9],
            'cp/CalendarController' => ['anvildev\booked\controllers\cp\CalendarController', 4],
        ];
    }

    /** @return string[] */
    private static function allControllerClasses(): array
    {
        return [
            'anvildev\booked\controllers\BookingController',
            'anvildev\booked\controllers\SlotController',
            'anvildev\booked\controllers\WaitlistController',
            'anvildev\booked\controllers\BookingDataController',
            'anvildev\booked\controllers\BookingManagementController',
            'anvildev\booked\controllers\EmployeeScheduleController',
            'anvildev\booked\controllers\AccountController',
            'anvildev\booked\controllers\CalendarConnectController',
            'anvildev\booked\controllers\cp\DashboardController',
            'anvildev\booked\controllers\cp\CalendarViewController',
            'anvildev\booked\controllers\cp\ReportsController',
            'anvildev\booked\controllers\cp\ServicesController',
            'anvildev\booked\controllers\cp\ServiceExtraController',
            'anvildev\booked\controllers\cp\EmployeesController',
            'anvildev\booked\controllers\cp\SchedulesController',
            'anvildev\booked\controllers\cp\LocationsController',
            'anvildev\booked\controllers\cp\BlackoutDatesController',
            'anvildev\booked\controllers\cp\EventDatesController',
            'anvildev\booked\controllers\cp\BookingsController',
            'anvildev\booked\controllers\cp\SettingsController',
            'anvildev\booked\controllers\cp\WaitlistController',
            'anvildev\booked\controllers\cp\WebhooksController',
            'anvildev\booked\controllers\cp\CalendarController',
        ];
    }
}

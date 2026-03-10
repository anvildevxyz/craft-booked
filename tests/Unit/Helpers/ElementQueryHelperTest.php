<?php

namespace anvildev\booked\tests\Unit\Helpers;

use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\tests\Support\TestCase;

/**
 * ElementQueryHelper Test
 *
 * All methods depend on Craft::$app->getSites() and ElementQuery.
 * Structure test only; functional tests require integration environment.
 */
class ElementQueryHelperTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(ElementQueryHelper::class));
    }

    public function testForCurrentSiteMethodExists(): void
    {
        $this->assertTrue(method_exists(ElementQueryHelper::class, 'forCurrentSite'));
    }

    public function testForSiteMethodExists(): void
    {
        $this->assertTrue(method_exists(ElementQueryHelper::class, 'forSite'));
    }

    public function testForAllSitesMethodExists(): void
    {
        $this->assertTrue(method_exists(ElementQueryHelper::class, 'forAllSites'));
    }

}

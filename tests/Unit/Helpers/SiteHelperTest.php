<?php

namespace anvildev\booked\tests\Unit\Helpers;

use anvildev\booked\helpers\SiteHelper;
use anvildev\booked\tests\Support\TestCase;

/**
 * SiteHelper Test
 *
 * All methods depend on Craft::$app->getSites() and Request.
 * Structure test only; functional tests require integration environment.
 */
class SiteHelperTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(SiteHelper::class));
    }

    public function testGetSiteForRequestMethodExists(): void
    {
        $this->assertTrue(method_exists(SiteHelper::class, 'getSiteForRequest'));
    }
}

<?php

namespace anvildev\booked\contracts;

/**
 * Empty stand-in for PurchasableInterface when Craft Commerce is not installed.
 * This allows the Reservation element to be loaded without a fatal error.
 */
interface PurchasableShim
{
}

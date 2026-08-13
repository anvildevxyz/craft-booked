<?php

namespace anvildev\booked\tests\Support;

use yii\console\ErrorHandler;

/**
 * The suite's error handler.
 *
 * Yii turns any error inside the `error_reporting()` mask into an exception, and
 * phpunit.xml sets that mask to -1. On PHP 8.4 that makes every implicit-nullable
 * parameter fatal — including the ones in packages we do not own. Craft's own
 * `craft\console\Controller::output(string $string = null)` is one, still present
 * in 5.10.13.2, and loading any console controller of ours through it took six
 * tests down with it.
 *
 * Silencing deprecations wholesale would have hidden ours too, so this narrows it
 * to the one thing we cannot fix: E_DEPRECATED raised from a file under vendor/.
 * Our own deprecations stay fatal.
 */
class TestErrorHandler extends ErrorHandler
{
    /**
     * @param int $code
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function handleError($code, $message, $file, $line)
    {
        if ($code === E_DEPRECATED && self::isVendorFile($file)) {
            // Handled: tell PHP not to run its internal handler either.
            return true;
        }

        return parent::handleError($code, $message, $file, $line);
    }

    private static function isVendorFile(string $file): bool
    {
        return str_starts_with($file, CRAFT_VENDOR_PATH . DIRECTORY_SEPARATOR);
    }
}

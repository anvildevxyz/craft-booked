<?php

namespace anvildev\booked\controllers\traits;

use anvildev\booked\exceptions\BookingConflictException;
use anvildev\booked\exceptions\BookingException;
use anvildev\booked\exceptions\BookingNotFoundException;
use anvildev\booked\exceptions\BookingRateLimitException;
use anvildev\booked\exceptions\BookingValidationException;
use anvildev\booked\helpers\ErrorSanitizer;
use Craft;
use craft\base\Model;
use craft\web\Response;

trait HandlesExceptionsTrait
{
    protected function handleException(\Throwable $e, ?Model $model = null): Response
    {
        Craft::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

        [$message, $errorType, $errors] = $this->getExceptionDetails($e);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->jsonError($message, $errorType, $errors ?? []);
        }

        Craft::$app->getSession()->setError($message);
        if ($model && !empty($errors)) {
            $model->addErrors($errors);
        }

        return $this->redirectToPostedUrl($model);
    }

    /** @return array{0: string, 1: string, 2: array|null} */
    private function getExceptionDetails(\Throwable $e): array
    {
        return match (true) {
            $e instanceof BookingRateLimitException => [
                $e->getMessage() ?: Craft::t('booked', 'booking.rateLimit'), 'rate_limit', null,
            ],
            $e instanceof BookingConflictException => [
                $e->getMessage() ?: Craft::t('booked', 'booking.conflict'), 'conflict', null,
            ],
            $e instanceof BookingValidationException => [
                $e->getMessage() ?: Craft::t('booked', 'booking.validationError'), 'validation', $e->getValidationErrors(),
            ],
            $e instanceof BookingNotFoundException => [
                Craft::t('booked', 'errors.bookingNotFound'), 'not_found', null,
            ],
            $e instanceof BookingException => [
                Craft::$app->getConfig()->general->devMode
                    ? ErrorSanitizer::sanitize($e->getMessage())
                    : Craft::t('booked', 'booking.genericError'),
                'general', null,
            ],
            default => [
                Craft::$app->getConfig()->general->devMode
                    ? ErrorSanitizer::sanitize($e->getMessage())
                    : 'An unexpected error occurred. Please try again.',
                'general', null,
            ],
        };
    }
}

<?php

namespace anvildev\booked\exceptions;

use Craft;

class BookingValidationException extends BookingException
{
    public function __construct(string $message = '', private array $validationErrors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function getName(): string
    {
        return Craft::t('booked', 'exceptions.bookingValidation');
    }
}

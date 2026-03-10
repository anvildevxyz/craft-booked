<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Handles waitlist-to-booking conversion via expiring tokens.
 *
 * When a waitlist entry is notified, a conversion token is generated.
 * The customer clicks a link with that token, which validates and returns
 * the waitlist entry data so the frontend can pre-fill the booking wizard.
 */
class WaitlistConversionController extends Controller
{
    use JsonResponseTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = ['convert'];
    public $enableCsrfValidation = true;

    public function actionConvert(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_waitlist_convert_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $token = $this->request->getRequiredParam('conversionToken');

        $waitlistService = Booked::getInstance()->waitlist;
        $entry = $waitlistService->validateConversionToken($token);

        if (!$entry) {
            return $this->jsonError(Craft::t('booked', 'waitlist.conversionTokenInvalid'));
        }

        return $this->asJson([
            'success' => true,
            'waitlistEntryId' => $entry->id,
            'serviceId' => $entry->serviceId,
            'eventDateId' => $entry->eventDateId,
            'employeeId' => $entry->employeeId,
            'locationId' => $entry->locationId,
            'preferredDate' => $entry->preferredDate,
            'preferredTimeStart' => $entry->preferredTimeStart,
            'preferredTimeEnd' => $entry->preferredTimeEnd,
            'userName' => $entry->userName,
            'userEmail' => $entry->userEmail,
            'userPhone' => $entry->userPhone,
        ]);
    }
}

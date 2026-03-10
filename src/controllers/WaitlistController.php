<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Service;
use anvildev\booked\services\BookingSecurityService;
use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * Frontend waitlist actions for fully booked services.
 */
class WaitlistController extends Controller
{
    use JsonResponseTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = ['join-waitlist', 'join-event-waitlist'];
    public $enableCsrfValidation = true;

    public function init(): void
    {
        parent::init();
    }

    public function actionJoinWaitlist(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->request;

        $securityService = Booked::getInstance()->bookingSecurity;
        $ipAddress = $request->getUserIP();
        $honeypotFieldName = $securityService->getHoneypotFieldName();
        $honeypotValue = $honeypotFieldName ? $request->getBodyParam($honeypotFieldName) : null;
        $captchaToken = $request->getBodyParam('captchaToken');

        // Interactive CAPTCHAs (hCaptcha/Turnstile) require a visible widget which isn't
        // available on the waitlist step. Skip CAPTCHA for waitlist joins when no token
        // is provided — honeypot, rate limiting, and duplicate-email checks still apply.
        $settings = Booked::getInstance()->getSettings();
        $skipCaptcha = empty($captchaToken) && in_array($settings->captchaProvider, ['hcaptcha', 'turnstile'], true);

        $securityResult = $securityService->validateRequest($ipAddress, $captchaToken, $honeypotValue, $skipCaptcha);
        if (!$securityResult['valid']) {
            if (($securityResult['errorType'] ?? null) === BookingSecurityService::RESULT_SPAM_DETECTED) {
                return $request->getAcceptsJson()
                    ? $this->jsonSuccess(Craft::t('booked', 'waitlist.addedShort'), ['waitlistId' => 0])
                    : $this->redirectToPostedUrl();
            }

            $errorType = $securityResult['errorType'] ?? null;
            $isRateLimit = in_array($errorType, [BookingSecurityService::RESULT_RATE_LIMITED, BookingSecurityService::RESULT_IP_BLOCKED], true);
            $msg = $securityResult['error'];
            return $request->getAcceptsJson() ? $this->jsonError($msg, statusCode: $isRateLimit ? 429 : 200) : $this->redirectToPostedUrl();
        }

        $serviceId = $request->getRequiredBodyParam('serviceId');
        $service = Service::findOne($serviceId);

        $waitlistEnabled = Booked::getInstance()->getSettings()->enableWaitlist;
        if ($service && $service->enableWaitlist !== null) {
            $waitlistEnabled = $service->enableWaitlist;
        }

        if (!$waitlistEnabled) {
            $msg = Craft::t('booked', 'waitlist.notEnabledForService');
            if ($request->getAcceptsJson()) {
                return $this->jsonError($msg);
            }
            Craft::$app->session->setError($msg);
            return $this->redirectToPostedUrl();
        }

        $data = [
            'serviceId' => (int) $serviceId,
            'employeeId' => $request->getBodyParam('employeeId') ? (int) $request->getBodyParam('employeeId') : null,
            'locationId' => $request->getBodyParam('locationId') ? (int) $request->getBodyParam('locationId') : null,
            'preferredDate' => $request->getBodyParam('preferredDate'),
            'preferredTimeStart' => $request->getBodyParam('preferredTimeStart'),
            'preferredTimeEnd' => $request->getBodyParam('preferredTimeEnd'),
            'userName' => substr(strip_tags(trim($request->getRequiredBodyParam('userName'))), 0, 255),
            'userEmail' => strtolower(trim($request->getRequiredBodyParam('userEmail'))),
            'userPhone' => substr(strip_tags($request->getBodyParam('userPhone') ?? ''), 0, 50),
            'notes' => substr(strip_tags($request->getBodyParam('notes') ?? ''), 0, 5000),
        ];

        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $data['userId'] = $currentUser->id;
        }

        $waitlistService = Booked::getInstance()->waitlist;
        if ($waitlistService->isOnWaitlist($data['userEmail'], (int)$data['serviceId'])) {
            $msg = Craft::t('booked', 'waitlist.alreadyOnWaitlist');
            if ($request->getAcceptsJson()) {
                return $this->jsonError($msg);
            }
            Craft::$app->session->setError($msg);
            return $this->redirectToPostedUrl();
        }

        try {
            $entry = $waitlistService->addToWaitlist($data);
            if (!$entry) {
                throw new \Exception('Failed to add to waitlist');
            }

            if ($request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('booked', 'waitlist.addedShort'), [
                    'waitlistId' => $entry->id,
                ]);
            }
            Craft::$app->session->setNotice(Craft::t('booked', 'waitlist.addedLong'));
            return $this->redirectToPostedUrl();
        } catch (\Throwable $e) {
            Craft::error("Failed to add to waitlist: " . $e->getMessage(), __METHOD__);
            $msg = Craft::t('booked', 'waitlist.joinFailed');
            if ($request->getAcceptsJson()) {
                return $this->jsonError($msg);
            }
            Craft::$app->session->setError($msg);
            return $this->redirectToPostedUrl();
        }
    }

    public function actionJoinEventWaitlist(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->request;

        $securityService = Booked::getInstance()->bookingSecurity;
        $ipAddress = $request->getUserIP();
        $honeypotFieldName = $securityService->getHoneypotFieldName();
        $honeypotValue = $honeypotFieldName ? $request->getBodyParam($honeypotFieldName) : null;
        $captchaToken = $request->getBodyParam('captchaToken');

        $settings = Booked::getInstance()->getSettings();
        $skipCaptcha = empty($captchaToken) && in_array($settings->captchaProvider, ['hcaptcha', 'turnstile'], true);

        $securityResult = $securityService->validateRequest($ipAddress, $captchaToken, $honeypotValue, $skipCaptcha);
        if (!$securityResult['valid']) {
            if (($securityResult['errorType'] ?? null) === BookingSecurityService::RESULT_SPAM_DETECTED) {
                return $request->getAcceptsJson()
                    ? $this->jsonSuccess(Craft::t('booked', 'waitlist.addedShort'), ['waitlistId' => 0])
                    : $this->redirectToPostedUrl();
            }

            $errorType = $securityResult['errorType'] ?? null;
            $isRateLimit = in_array($errorType, [BookingSecurityService::RESULT_RATE_LIMITED, BookingSecurityService::RESULT_IP_BLOCKED], true);
            $msg = $securityResult['error'];
            return $request->getAcceptsJson() ? $this->jsonError($msg, statusCode: $isRateLimit ? 429 : 200) : $this->redirectToPostedUrl();
        }

        $eventDateId = (int) $request->getRequiredBodyParam('eventDateId');
        $eventDate = EventDate::find()->siteId('*')->id($eventDateId)->one();

        if (!$eventDate) {
            $msg = Craft::t('booked', 'errors.eventNotFound');
            return $request->getAcceptsJson() ? $this->jsonError($msg) : $this->redirectToPostedUrl();
        }

        $globalWaitlistEnabled = Booked::getInstance()->getSettings()->enableWaitlist;
        $waitlistEnabled = $eventDate->enableWaitlist ?? $globalWaitlistEnabled;

        if (!$waitlistEnabled) {
            $msg = Craft::t('booked', 'waitlist.notEnabledForEvent');
            if ($request->getAcceptsJson()) {
                return $this->jsonError($msg);
            }
            Craft::$app->session->setError($msg);
            return $this->redirectToPostedUrl();
        }

        $data = [
            'eventDateId' => $eventDateId,
            'userName' => substr(strip_tags(trim($request->getRequiredBodyParam('userName'))), 0, 255),
            'userEmail' => strtolower(trim($request->getRequiredBodyParam('userEmail'))),
            'userPhone' => substr(strip_tags($request->getBodyParam('userPhone') ?? ''), 0, 50),
            'notes' => substr(strip_tags($request->getBodyParam('notes') ?? ''), 0, 5000),
        ];

        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $data['userId'] = $currentUser->id;
        }

        $waitlistService = Booked::getInstance()->waitlist;
        if ($waitlistService->isOnEventWaitlist($data['userEmail'], $eventDateId)) {
            $msg = Craft::t('booked', 'waitlist.alreadyOnWaitlist');
            if ($request->getAcceptsJson()) {
                return $this->jsonError($msg);
            }
            Craft::$app->session->setError($msg);
            return $this->redirectToPostedUrl();
        }

        try {
            $entry = $waitlistService->addToEventWaitlist($data);
            if (!$entry) {
                throw new \Exception('Failed to add to event waitlist');
            }

            if ($request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('booked', 'waitlist.addedShort'), [
                    'waitlistId' => $entry->id,
                ]);
            }
            Craft::$app->session->setNotice(Craft::t('booked', 'waitlist.addedLong'));
            return $this->redirectToPostedUrl();
        } catch (\Throwable $e) {
            Craft::error("Failed to add to event waitlist: " . $e->getMessage(), __METHOD__);
            $msg = Craft::t('booked', 'waitlist.joinFailed');
            if ($request->getAcceptsJson()) {
                return $this->jsonError($msg);
            }
            Craft::$app->session->setError($msg);
            return $this->redirectToPostedUrl();
        }
    }
}

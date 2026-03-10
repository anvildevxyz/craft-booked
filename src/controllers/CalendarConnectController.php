<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\records\CalendarInviteRecord;
use anvildev\booked\records\OAuthStateTokenRecord;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use craft\web\View;

/**
 * Frontend OAuth flow for employee calendar connections via email invite tokens.
 */
class CalendarConnectController extends Controller
{
    protected array|bool|int $allowAnonymous = ['connect', 'callback', 'success', 'error'];
    public $enableCsrfValidation = true;

    public function beforeAction($action): bool
    {
        if ($action->id === 'callback') {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    /**
     * Initiate OAuth flow from email invite link
     * URL: /booked/calendar/connect?token=xxx
     */
    public function actionConnect(): Response
    {
        $token = (string) Craft::$app->request->getRequiredParam('token');

        $invite = CalendarInviteRecord::findValid($token);
        if (!$invite) {
            Craft::warning("Invalid or expired calendar invite token: {$token}", __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=invalid');
        }

        $employee = $invite->getEmployee();
        if (!$employee) {
            Craft::error("Employee not found for invite: {$invite->employeeId}", __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=invalid');
        }

        Craft::$app->session->set('booked_calendar_invite', $token);
        $stateRecord = OAuthStateTokenRecord::createToken($employee->id, $invite->provider);
        $calendarSync = Booked::getInstance()->getCalendarSync();
        $callbackUrl = $this->getFrontendCallbackUrl();

        if ($invite->provider === 'google') {
            $client = $calendarSync->getGoogleClient();
            $client->setState($stateRecord->token);
            $client->setRedirectUri($callbackUrl);
            $authUrl = $client->createAuthUrl();
        } elseif ($invite->provider === 'outlook') {
            $authUrl = $calendarSync->getOutlookClient()->getAuthorizationUrl([
                'state' => $stateRecord->token,
                'scope' => ['openid', 'offline_access', 'https://graph.microsoft.com/Calendars.ReadWrite'],
                'redirect_uri' => $callbackUrl,
            ]);
        } else {
            Craft::error("Unknown provider: {$invite->provider}", __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=invalid');
        }

        Craft::info("Redirecting employee {$employee->id} to {$invite->provider} OAuth", __METHOD__);
        return $this->redirect($authUrl);
    }

    /**
     * Handle OAuth callback (frontend version)
     * URL: /booked/calendar/frontend-callback
     */
    public function actionCallback(): Response
    {
        $request = Craft::$app->request;
        $error = (string) $request->getParam('error');

        if ($error) {
            $errorDescription = (string) $request->getParam('error_description');
            Craft::warning("OAuth error: {$error} - {$errorDescription}", __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=denied');
        }

        $stateToken = $request->getParam('state');
        $code = $request->getParam('code');

        if (!$stateToken) {
            Craft::error('OAuth callback missing state parameter', __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=invalid');
        }
        if (!$code) {
            Craft::error('OAuth callback missing code parameter', __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=invalid');
        }

        $inviteToken = (string) Craft::$app->session->get('booked_calendar_invite');
        if (!$inviteToken) {
            Craft::warning('Calendar invite token not found in session', __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=session');
        }

        $invite = CalendarInviteRecord::findValid($inviteToken);
        if (!$invite) {
            Craft::warning("Calendar invite expired or already used: {$inviteToken}", __METHOD__);
            return $this->redirect('/booked/calendar/error?reason=expired');
        }

        if (Booked::getInstance()->getCalendarSync()->handleCallback($stateToken, $code, $this->getFrontendCallbackUrl())) {
            $invite->markUsed();
            Craft::$app->session->remove('booked_calendar_invite');
            Craft::info("Successfully connected {$invite->provider} calendar for employee {$invite->employeeId}", __METHOD__);
            return $this->redirect('/booked/calendar/success?provider=' . $invite->provider);
        }

        Craft::error("Failed to exchange OAuth code for employee {$invite->employeeId}", __METHOD__);
        return $this->redirect('/booked/calendar/error?reason=failed');
    }

    public function actionSuccess(): Response
    {
        $validProviders = ['google', 'outlook'];
        $provider = Craft::$app->request->getParam('provider', 'calendar');
        $provider = in_array($provider, $validProviders, true) ? $provider : 'calendar';

        return $this->renderCalendarTemplate('connect-success', [
            'provider' => $provider,
            'providerName' => ucfirst($provider),
            'title' => Craft::t('booked', 'calendarConnect.calendarConnected'),
        ]);
    }

    public function actionError(): Response
    {
        $messages = [
            'invalid' => Craft::t('booked', 'calendarConnect.invalidLink'),
            'expired' => Craft::t('booked', 'calendarConnect.expiredLink'),
            'denied' => Craft::t('booked', 'calendarConnect.deniedAccess'),
            'session' => Craft::t('booked', 'calendarConnect.sessionExpired'),
            'failed' => Craft::t('booked', 'calendarConnect.connectionError'),
            'unknown' => Craft::t('booked', 'calendarConnect.unexpectedError'),
        ];

        $reason = Craft::$app->request->getParam('reason', 'unknown');
        $reason = array_key_exists($reason, $messages) ? $reason : 'unknown';

        return $this->renderCalendarTemplate('connect-error', [
            'reason' => $reason,
            'message' => $messages[$reason] ?? $messages['unknown'],
            'title' => Craft::t('booked', 'calendarConnect.connectionFailed'),
        ]);
    }

    protected function getFrontendCallbackUrl(): string
    {
        return \craft\helpers\UrlHelper::siteUrl('booked/calendar/frontend-callback');
    }

    /**
     * Render calendar template with fallback from site to plugin templates.
     */
    protected function renderCalendarTemplate(string $template, array $variables = []): Response
    {
        $templatePath = "booked/calendar/{$template}";
        $view = Craft::$app->getView();
        $oldTemplateMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            if ($view->doesTemplateExist($templatePath)) {
                return $this->renderTemplate($templatePath, $variables);
            }
        } finally {
            $view->setTemplateMode($oldTemplateMode);
        }

        return $this->renderTemplate($templatePath, $variables);
    }
}

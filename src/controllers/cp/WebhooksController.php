<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\records\WebhookRecord;
use anvildev\booked\services\WebhookService;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebhooksController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageSettings');
        return true;
    }

    public function actionIndex(): Response
    {
        $webhooks = Booked::getInstance()->getWebhook()->getAllWebhooks();
        $webhookStats = [];
        foreach ($webhooks as $webhook) {
            $webhookStats[$webhook->id] = Booked::getInstance()->getWebhook()->getWebhookStats($webhook->id);
        }

        return $this->renderTemplate('booked/webhooks/_index', [
            'webhooks' => $webhooks,
            'webhookStats' => $webhookStats,
            'settings' => Booked::getInstance()->getSettings(),
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $webhook = Booked::getInstance()->getWebhook()->getWebhookById($id)
                ?? throw new NotFoundHttpException('Webhook not found');
            $title = Craft::t('booked', 'webhook.editTitle');
        } else {
            $webhook = new WebhookRecord();
            $webhook->enabled = true;
            $webhook->retryCount = 3;
            $webhook->payloadFormat = 'standard';
            $webhook->events = json_encode([]);
            $webhook->secret = WebhookRecord::generateSecret();
            $title = Craft::t('booked', 'webhook.newTitle');
        }

        return $this->renderTemplate('booked/webhooks/_edit', [
            'webhook' => $webhook,
            'eventTypes' => WebhookService::getEventTypes(),
            'title' => $title,
            'isNew' => !$id,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('id');

        $webhook = $id
            ? (Booked::getInstance()->getWebhook()->getWebhookById($id) ?? throw new NotFoundHttpException('Webhook not found'))
            : new WebhookRecord();

        $webhook->name = $request->getBodyParam('name');
        $webhook->url = $request->getBodyParam('url');
        if ($webhook->url && !preg_match('#^https?://#i', $webhook->url)) {
            $webhook->url = 'https://' . $webhook->url;
        }
        $webhook->enabled = (bool) $request->getBodyParam('enabled');
        $webhook->retryCount = (int) ($request->getBodyParam('retryCount') ?? 3);
        $webhook->payloadFormat = $request->getBodyParam('payloadFormat') ?? 'standard';
        $webhook->siteId = $request->getBodyParam('siteId') ?: null;

        $events = $request->getBodyParam('events');
        $webhook->events = json_encode(is_array($events) ? $events : []);

        // Build custom headers with validation
        /** @var array<int, string> $headerKeys */
        $headerKeys = $request->getBodyParam('headerKeys') ?? [];
        /** @var array<int, string> $headerValues */
        $headerValues = $request->getBodyParam('headerValues') ?? [];
        $headers = [];

        $reservedHeaders = [
            'content-type', 'user-agent', 'x-booked-event', 'x-booked-timestamp',
            'x-booked-webhook-id', 'x-booked-signature', 'host', 'content-length',
            'transfer-encoding', 'connection',
        ];

        foreach ($headerKeys as $i => $key) {
            if (empty($key) || !isset($headerValues[$i])) {
                continue;
            }
            $key = trim($key);
            $value = trim($headerValues[$i]);
            if (empty($key)) {
                continue;
            }
            if (in_array(strtolower($key), $reservedHeaders, true)) {
                Craft::warning("Skipping reserved header: {$key}", __METHOD__);
                continue;
            }
            $key = preg_replace('/[\r\n\x00]/', '', $key);
            $value = preg_replace('/[\r\n\x00]/', '', $value);
            if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+\-.^_`|~]+$/', $key)) {
                Craft::warning("Skipping invalid header name: {$key}", __METHOD__);
                continue;
            }
            $headers[$key] = $value;
        }
        $webhook->headers = $headers ? json_encode($headers) : null;

        if (!$id) {
            $webhook->secret = WebhookRecord::generateSecret();
        }

        // Validate and save
        $renderError = function() use ($webhook, $id) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'webhook.notSaved'));
            return $this->renderTemplate('booked/webhooks/_edit', [
                'webhook' => $webhook,
                'eventTypes' => WebhookService::getEventTypes(),
                'title' => Craft::t('booked', $id ? 'webhook.editTitle' : 'webhook.newTitle'),
                'isNew' => !$id,
            ]);
        };

        if (!$webhook->validate() || !$webhook->save()) {
            return $renderError();
        }

        Craft::$app->getSession()->setNotice(Craft::t('booked', 'webhook.saved'));
        return $this->redirect('booked/webhooks');
    }

    public function actionDelete(?int $id = null): Response
    {
        $this->requirePostRequest();

        $id = $id ?? Craft::$app->getRequest()->getBodyParam('id');
        if (!$id) {
            throw new NotFoundHttpException('Webhook not found');
        }

        if (Booked::getInstance()->getWebhook()->deleteWebhook($id)) {
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'webhook.deleted'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('booked', 'webhook.notDeleted'));
        }

        return $this->redirect('booked/webhooks');
    }

    public function actionLogs(int $id): Response
    {
        $webhook = Booked::getInstance()->getWebhook()->getWebhookById($id)
            ?? throw new NotFoundHttpException('Webhook not found');

        return $this->renderTemplate('booked/webhooks/_logs', [
            'webhook' => $webhook,
            'logs' => Booked::getInstance()->getWebhook()->getLogs($id, 100),
            'stats' => Booked::getInstance()->getWebhook()->getWebhookStats($id),
        ]);
    }

    public function actionTest(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $webhook = Booked::getInstance()->getWebhook()->getWebhookById($id)
            ?? throw new NotFoundHttpException('Webhook not found');

        $result = Booked::getInstance()->getWebhook()->test($webhook);

        if ($result['success']) {
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'webhook.testSuccess', ['code' => $result['responseCode']]));
        } else {
            Craft::$app->getSession()->setError(Craft::t('booked', 'webhook.testFailed', ['error' => $result['errorMessage'] ?? 'Unknown error']));
        }

        return $this->redirect("booked/webhooks/{$id}");
    }

    public function actionRetry(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        if (Booked::getInstance()->getWebhook()->retryFromLog($request->getBodyParam('logId'))) {
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'webhook.retryQueued'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('booked', 'webhook.retryFailed'));
        }

        $webhookId = (int) $request->getBodyParam('webhookId');
        return $this->redirect("booked/webhooks/{$webhookId}/logs");
    }

    public function actionRegenerateSecret(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $webhook = Booked::getInstance()->getWebhook()->getWebhookById($id)
            ?? throw new NotFoundHttpException('Webhook not found');

        $webhook->secret = WebhookRecord::generateSecret();
        $webhook->save(false);

        return $this->jsonSuccess('', ['secret' => $webhook->secret]);
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();

        $webhook = Booked::getInstance()->getWebhook()->getWebhookById(Craft::$app->getRequest()->getBodyParam('id'))
            ?? throw new NotFoundHttpException('Webhook not found');

        $webhook->enabled = !$webhook->enabled;
        $webhook->save();

        return $this->jsonSuccess('', ['enabled' => $webhook->enabled]);
    }
}

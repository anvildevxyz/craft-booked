<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\records\WaitlistRecord;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class WaitlistController extends Controller
{
    public function actionCleanup(): int
    {
        $this->stdout("Cleaning up expired waitlist entries...\n");

        try {
            $count = Booked::getInstance()->waitlist->cleanupExpired();
            $this->stdout(
                $count > 0 ? "✓ Expired {$count} waitlist entries\n" : "No expired entries found\n",
                $count > 0 ? Console::FG_GREEN : null,
            );
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionStats(): int
    {
        $this->stdout("Waitlist Statistics:\n\n");

        try {
            $stats = Booked::getInstance()->waitlist->getStats();

            $this->stdout("─────────────────────────────────\n");
            foreach ([
                'active' => Console::FG_GREEN,
                'notified' => Console::FG_BLUE,
                'converted' => Console::FG_PURPLE,
                'expired' => null,
                'cancelled' => Console::FG_RED,
            ] as $key => $color) {
                $this->stdout(str_pad(ucfirst($key) . ':', 14) . "{$stats[$key]}\n", $color);
            }
            $this->stdout("─────────────────────────────────\n");
            $this->stdout("Total:        {$stats['total']}\n");
            $this->stdout("─────────────────────────────────\n");

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionList(int $limit = 10): int
    {
        $this->stdout("Active Waitlist Entries (limit: {$limit}):\n\n");

        try {
            $entries = WaitlistRecord::find()
                ->where(['status' => WaitlistRecord::STATUS_ACTIVE])
                ->orderBy(['priority' => SORT_ASC, 'dateCreated' => SORT_ASC])
                ->limit($limit)
                ->all();

            if (empty($entries)) {
                $this->stdout("No active waitlist entries found.\n");
                return ExitCode::OK;
            }

            $this->stdout(str_pad("ID", 8) . str_pad("Name", 25) . str_pad("Email", 30) . str_pad("Created", 20) . "\n");
            $this->stdout(str_repeat("-", 83) . "\n");

            foreach ($entries as $entry) {
                $this->stdout(
                    str_pad((string)$entry->id, 8) .
                    str_pad(mb_substr($entry->userName, 0, 23), 25) .
                    str_pad(mb_substr($entry->userEmail, 0, 28), 30) .
                    $entry->dateCreated . "\n"
                );
            }

            $total = (int)WaitlistRecord::find()->where(['status' => WaitlistRecord::STATUS_ACTIVE])->count();
            if ($total > $limit) {
                $this->stdout("\n... and " . ($total - $limit) . " more entries\n");
            }

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionNotifyAll(int $serviceId, string $date, string $startTime, string $endTime): int
    {
        $this->stdout("Notifying waitlist entries for service #{$serviceId}...\n");

        if (!$this->confirm("This will notify ALL active entries for this service. Continue?")) {
            $this->stdout("Cancelled.\n");
            return ExitCode::OK;
        }

        try {
            Booked::getInstance()->waitlist->checkAndNotifyWaitlist($serviceId, $date, $startTime, $endTime);
            $this->stdout("✓ Notifications queued successfully\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}

<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\factories\ReservationFactory;
use craft\console\Controller;
use craft\elements\User;
use yii\console\ExitCode;
use yii\helpers\Console;

class UsersController extends Controller
{
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return $actionID === 'link-bookings'
            ? [...parent::options($actionID), 'dryRun']
            : parent::options($actionID);
    }

    public function actionLinkBookings(): int
    {
        $this->stdout("Linking existing bookings to user accounts...\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        try {
            $reservations = ReservationFactory::find()
                ->where(['userId' => null])
                ->andWhere(['not', ['userEmail' => null]])
                ->andWhere(['not', ['userEmail' => '']])
                ->all();

            $totalCount = count($reservations);
            $this->stdout("Found {$totalCount} bookings without user links\n\n");

            if ($totalCount === 0) {
                $this->stdout("Nothing to do.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            // Group by email
            $emailGroups = [];
            foreach ($reservations as $reservation) {
                $emailGroups[strtolower(trim($reservation->userEmail))][] = $reservation;
            }

            $this->stdout("Processing " . count($emailGroups) . " unique email addresses...\n\n");

            $linkedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            foreach ($emailGroups as $email => $reservationsForEmail) {
                $user = User::find()->email($email)->status(null)->one();

                if (!$user) {
                    $skippedCount += count($reservationsForEmail);
                    $this->stdout("  ⊘ No user found for: {$email} (" . count($reservationsForEmail) . " bookings)\n", Console::FG_YELLOW);
                    continue;
                }

                foreach ($reservationsForEmail as $reservation) {
                    if ($this->dryRun) {
                        $linkedCount++;
                        $this->stdout("  ✓ Would link booking #{$reservation->getId()} to user #{$user->id} ({$email})\n", Console::FG_GREEN);
                        continue;
                    }

                    try {
                        $reservation->userId = $user->id;
                        if ($reservation->save(false)) {
                            $linkedCount++;
                            $this->stdout("  ✓ Linked booking #{$reservation->getId()} to user #{$user->id} ({$email})\n", Console::FG_GREEN);
                        } else {
                            $errorCount++;
                            $this->stderr("  ✗ Failed to save booking #{$reservation->getId()}\n", Console::FG_RED);
                        }
                    } catch (\Throwable $e) {
                        $errorCount++;
                        $this->stderr("  ✗ Error linking booking #{$reservation->getId()}: {$e->getMessage()}\n", Console::FG_RED);
                    }
                }
            }

            $this->stdout("\n─────────────────────────────────\n");
            $this->stdout("Summary:\n");
            $this->stdout("  Total bookings processed: {$totalCount}\n");
            $this->stdout("  Linked to users: {$linkedCount}\n", Console::FG_GREEN);
            $this->stdout("  Skipped (no user): {$skippedCount}\n", Console::FG_YELLOW);
            if ($errorCount > 0) {
                $this->stdout("  Errors: {$errorCount}\n", Console::FG_RED);
            }
            $this->stdout("─────────────────────────────────\n");

            if ($this->dryRun) {
                $this->stdout("\nDRY RUN - Run without --dry-run to apply changes\n", Console::FG_YELLOW);
            }

            return $errorCount > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionStats(): int
    {
        $this->stdout("Booking User Link Statistics:\n\n");

        try {
            $linkedCount = ReservationFactory::find()->where(['not', ['userId' => null]])->count();
            $unlinkedCount = ReservationFactory::find()->where(['userId' => null])->count();

            $unlinkedEmails = (new \yii\db\Query())
                ->select(['userEmail'])
                ->from('{{%booked_reservations}}')
                ->where(['userId' => null])
                ->andWhere(['not', ['userEmail' => null]])
                ->andWhere(['not', ['userEmail' => '']])
                ->distinct()
                ->column();

            $linkableCount = 0;
            foreach ($unlinkedEmails as $email) {
                if (User::find()->email($email)->status(null)->one()) {
                    $linkableCount += (int)ReservationFactory::find()
                        ->where(['userId' => null, 'userEmail' => $email])
                        ->count();
                }
            }

            $totalCount = $linkedCount + $unlinkedCount;

            $this->stdout("─────────────────────────────────\n");
            $this->stdout("Total bookings:        {$totalCount}\n");
            $this->stdout("Linked to users:       {$linkedCount}\n", Console::FG_GREEN);
            $this->stdout("Not linked:            {$unlinkedCount}\n");
            $this->stdout("  - Can be linked:     {$linkableCount}\n", Console::FG_YELLOW);
            $this->stdout("  - No matching user:  " . ($unlinkedCount - $linkableCount) . "\n");
            $this->stdout("─────────────────────────────────\n");

            if ($linkableCount > 0) {
                $this->stdout("\nRun 'php craft booked/users/link-bookings' to link bookings\n");
            }

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}

<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class SmsController extends Controller
{
    public function actionTest(string $phone): int
    {
        $this->stdout("Sending test SMS to {$phone}...\n");

        $result = Booked::getInstance()->getTwilioSms()->sendTestSms($phone);

        if ($result['success']) {
            $this->stdout("{$result['message']}\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr("{$result['message']}\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
}

<?php

/**
 * Fixture CLI for the schedule-capacity browser E2E.
 *
 * Lets the Playwright spec (which runs on the host) drive database state inside
 * the container without embedding SQL in the test:
 *
 *   ddev exec php plugins/craft-booked/tests/integration-live/capacity-fixture.php pick-date
 *   ddev exec php plugins/craft-booked/tests/integration-live/capacity-fixture.php capacity 3
 *   ddev exec php plugins/craft-booked/tests/integration-live/capacity-fixture.php seed 2 --date=2026-08-07
 *   ddev exec php plugins/craft-booked/tests/integration-live/capacity-fixture.php reset
 *
 * Options are passed as flags, not environment variables — `ddev exec` does not
 * forward the caller's environment into the container, so an env-var date would
 * silently fall back to the default and seed the wrong day.
 *
 *   --date=YYYY-MM-DD   day to seed (default: today + 30)
 *   --service=<id>      service under test (default: 1236)
 *   --time=HH:MM        slot to fill (default: 09:00)
 *
 * `reset` clears the seeded bookings and restores the schedule's original
 * capacity, so the dev database is left exactly as it was found.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Service;

const MARKER = 'issue85-e2e';
/** Where the original per-day capacities are parked while the E2E runs. */
const BACKUP_PATH = '/tmp/booked-issue85-capacity-backup.json';

$db = Craft::$app->getDb();

$flags = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $flags[$m[1]] = $m[2];
    } else {
        $positional[] = $arg;
    }
}

$command = $positional[0] ?? '';
$argument = $positional[1] ?? null;

$serviceId = (int)($flags['service'] ?? 1236);
$date = $flags['date'] ?? date('Y-m-d', strtotime('+30 days'));
$slotTime = $flags['time'] ?? '09:00';

$service = Service::find()->siteId('*')->id($serviceId)->status(null)->one();
if (!$service) {
    fwrite(STDERR, "Service {$serviceId} not found\n");
    exit(1);
}

// Only the capacity-mutating commands need a service-level schedule. `pick-date`
// is also used against employee-backed services, which have none — their staff
// carry their own schedules.
$scheduleId = (int)$db->createCommand(
    'SELECT scheduleId FROM {{%booked_service_schedule_assignments}} WHERE serviceId = :s ORDER BY sortOrder LIMIT 1',
    [':s' => $serviceId],
)->queryScalar();

if (!$scheduleId && in_array($command, ['capacity', 'reset'], true)) {
    fwrite(STDERR, "Service {$serviceId} has no assigned schedule to set a capacity on\n");
    exit(1);
}

$readWorkingHours = fn(): array => json_decode((string)$db->createCommand(
    'SELECT workingHours FROM {{%booked_schedules}} WHERE id = :id',
    [':id' => $scheduleId],
)->queryScalar(), true) ?: [];

// The availability calendar endpoint caches per-day bookability for 5 minutes,
// so every state change here has to drop it or the browser keeps seeing the
// day as it was before the fixture ran.
$flushCaches = function() {
    Craft::$app->getCache()->flush();
    Booked::getInstance()->getScheduleAssignment()->clearServiceScheduleCache();
    Booked::getInstance()->getAvailability()->clearSlotCache();
};

// workingHours is a JSON column — write arrays, never pre-encoded strings.
$writeWorkingHours = function(array $hours) use ($db, $scheduleId, $flushCaches) {
    $db->createCommand()->update('{{%booked_schedules}}', ['workingHours' => $hours], ['id' => $scheduleId])->execute();
    $flushCaches();
};

$clearBookings = function() use ($db, $flushCaches, $serviceId, $date) {
    $deleted = $db->createCommand()
        ->delete('{{%booked_reservations}}', ['like', 'userEmail', MARKER . '%', false])
        ->execute();

    // Selecting a slot in the wizard takes a soft lock, and a held seat legitimately
    // counts against remaining capacity. Release the ones for the day under test so
    // a previous scenario's selection doesn't leak into the next one's arithmetic.
    $db->createCommand()->delete('{{%booked_soft_locks}}', ['serviceId' => $serviceId, 'date' => $date])->execute();

    $flushCaches();
    return $deleted;
};

switch ($command) {
    case 'pick-date':
        // The browser can only reach days the availability calendar marks bookable:
        // inside its 90-day horizon, not blacked out, and actually carrying slots.
        // The probe slot must also be untouched — the dev database already holds
        // bookings that would otherwise be mistaken for the ones seeded here.
        $plugin = Booked::getInstance();
        for ($offset = 7; $offset <= 85; $offset++) {
            $candidate = date('Y-m-d', strtotime("+{$offset} days"));
            if ($plugin->getBlackoutDate()->isDateBlackedOut($candidate, null, null)) {
                continue;
            }

            $plugin->getAvailability()->clearSlotCache();
            $candidateSlots = $plugin->getAvailability()->getAvailableSlots($candidate, null, null, $serviceId);

            // `--time=any` just wants a day that has availability at all, which is
            // what employee-backed services need — they keep their own shifts and
            // may never open at the group service's probe time.
            if ($slotTime === 'any') {
                if (!empty($candidateSlots)) {
                    echo $candidate;
                    exit(0);
                }
                continue;
            }

            foreach ($candidateSlots as $slot) {
                if (($slot['time'] ?? '') !== $slotTime) {
                    continue;
                }
                if ($slot['availableCapacity'] !== null && $slot['availableCapacity'] === $slot['maxCapacity']) {
                    echo $candidate;
                    exit(0);
                }
            }
        }
        fwrite(STDERR, "No date with an unbooked {$slotTime} slot for service {$serviceId} within the calendar horizon\n");
        exit(1);

    case 'capacity':
        if (!file_exists(BACKUP_PATH)) {
            file_put_contents(BACKUP_PATH, json_encode($readWorkingHours()));
        }
        $capacity = $argument === 'null' ? null : (int)$argument;
        $hours = $readWorkingHours();
        foreach (array_keys($hours) as $day) {
            $hours[$day]['capacity'] = $capacity;
        }
        $writeWorkingHours($hours);
        echo "capacity set to " . var_export($capacity, true) . " on schedule {$scheduleId}\n";
        break;

    case 'seed':
        $count = max(1, (int)($argument ?? 1));
        $startTime = $slotTime;
        $endTime = date('H:i:s', strtotime($startTime) + (int)($service->duration ?? 60) * 60);
        for ($i = 0; $i < $count; $i++) {
            $db->createCommand()->insert('{{%booked_reservations}}', [
                'userName' => 'Issue 85 E2E',
                'userEmail' => MARKER . '-' . $i . '@example.test',
                'bookingDate' => $date,
                'startTime' => $startTime . ':00',
                'endTime' => $endTime,
                'status' => 'confirmed',
                'serviceId' => $serviceId,
                'quantity' => 1,
                'confirmationToken' => MARKER . bin2hex(random_bytes(16)),
                'dateCreated' => gmdate('Y-m-d H:i:s'),
                'dateUpdated' => gmdate('Y-m-d H:i:s'),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();
        }
        $flushCaches();
        echo "seeded {$count} booking(s) at {$startTime} on {$date}\n";
        break;

    case 'clear':
        echo 'cleared ' . $clearBookings() . " booking(s)\n";
        break;

    case 'reset':
        $cleared = $clearBookings();
        if (file_exists(BACKUP_PATH)) {
            $writeWorkingHours(json_decode((string)file_get_contents(BACKUP_PATH), true) ?: []);
            unlink(BACKUP_PATH);
            echo "restored original schedule capacity\n";
        }
        echo "cleared {$cleared} booking(s)\n";
        break;

    default:
        fwrite(STDERR, "Usage: capacity-fixture.php pick-date | capacity <n|null> | seed <count> | clear | reset"
            . " [--date=YYYY-MM-DD] [--service=<id>] [--time=HH:MM]\n");
        exit(1);
}

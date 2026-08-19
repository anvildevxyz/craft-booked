# Queue Setup

Booked sends its emails, SMS messages, webhooks, and calendar updates through the **Craft queue**. The queue holds each job until a worker runs it.

**Set up a queue worker on your server.** Without a worker, the jobs stay in the queue. They run only when a person opens the control panel.

> **Symptom of a missing worker:** a customer books on the front end, but the confirmation email comes much later. It comes at the moment when a person opens the control panel.

---

## Why a Worker Is Necessary

The Craft setting `runQueueAutomatically` is `true` by default. With this default, Craft runs the pending jobs from control panel requests only. Front-end requests do not run the queue.

A booking from the front end therefore waits for the next control panel visit. This is standard Craft behaviour. It applies to each plugin that uses the queue.

---

## What Booked Puts in the Queue

| Job | Trigger |
|-----|---------|
| Booking confirmation email | A customer completes a booking |
| Owner notification email | A customer completes a booking |
| Cancellation email | A person cancels a booking |
| Status change email | An admin changes the booking status |
| Quantity change email | An admin changes the number of places |
| SMS message | A confirmation, a reminder, or a cancellation, if Twilio is on |
| Calendar sync | A booking is created, changed, or cancelled |
| Webhook delivery | Each subscribed booking event |
| Waitlist notification | A place becomes free |
| Reminder batch | The `booked/reminders/queue` command |

Booked pushes the confirmation email and the owner notification with priority `512`. The Craft default is `1024`. A smaller number runs first. The emails therefore go before the calendar sync and the webhooks.

---

## Option 1: A Daemon (Recommended)

A daemon gives the fastest delivery. The worker stays in memory and looks for new jobs every 3 seconds.

**Step 1.** Stop the control panel queue runner:

```php
// config/general.php
return [
    '*' => [
        'runQueueAutomatically' => false,
    ],
];
```

**Step 2.** Start the worker with a process monitor.

Supervisor:

```ini
; /etc/supervisor/conf.d/craft-queue.conf
[program:craft-queue]
command=php /path/to/project/craft queue/listen --verbose
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/craft-queue.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status craft-queue
```

systemd:

```ini
# /etc/systemd/system/craft-queue.service
[Unit]
Description=Craft queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/to/project
ExecStart=/usr/bin/php /path/to/project/craft queue/listen --verbose
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now craft-queue
sudo systemctl status craft-queue
```

One worker process is sufficient for most sites. Restart the worker after each deployment. New code does not go into a process that is already in memory.

---

## Option 2: A Cron Job (Simple)

Use a cron job if you cannot run a daemon:

```
* * * * * cd /path/to/project && php craft queue/run >/dev/null 2>&1
```

The `queue/run` command runs the waiting jobs and then stops. An email comes after a maximum delay of one minute.

Set `runQueueAutomatically` to `false` for this option also. The control panel then stays quick.

---

## Managed Hosting

Craft Cloud runs the queue for you. No worker setup is necessary.

Other managed platforms frequently supply a queue worker. Read the documentation of your host, and switch the worker on.

---

## Reminders Need a Cron Job Also

The worker sends the reminder emails, but a cron job must find the due reminders first. The two are not the same task.

```
0 * * * * cd /path/to/project && php craft booked/reminders/queue >/dev/null 2>&1
```

See [Console Commands](CONSOLE_COMMANDS.md) for the reminder commands.

---

## Verify the Setup

Look at the queue:

```bash
php craft booked/doctor   # Shows the number of waiting jobs
php craft queue/info      # Shows the waiting, delayed, reserved, and done jobs
```

Then do a test:

1. Make a test booking on the front end.
2. Do not open the control panel.
3. The confirmation email must come in some seconds (daemon) or in one minute (cron).

If the number of waiting jobs increases and does not decrease, the worker does not run.

For local development with DDEV, run the worker in a second terminal:

```bash
ddev exec php craft queue/listen --verbose
```

---

## Troubleshooting

| Symptom | Cause | Action |
|---------|-------|--------|
| The emails come only after a control panel visit | No worker runs | Set up option 1 or option 2 |
| The number of waiting jobs increases | The worker stopped | Examine the process monitor and `/var/log/craft-queue.log` |
| A job failed in **Utilities → Queue Manager** | The job caused an error | Read `storage/logs/queue.log`, then run `php craft queue/retry all` |
| The queue is empty, but no email comes | The Craft mail settings are incorrect | Run `php craft booked/doctor` |
| The emails come twice | Two workers run | Stop one worker, and set `runQueueAutomatically` to `false` |

---

## Next Steps

- [Email Templates](EMAIL_TEMPLATES.md) - Customize the email notifications
- [Console Commands](CONSOLE_COMMANDS.md) - CLI commands for reminders, cleanup, and diagnostics
- [Configuration Guide](CONFIGURATION.md) - Complete configuration reference

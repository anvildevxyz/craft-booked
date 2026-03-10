<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%booked_settings}}')) {
            $this->createTable('{{%booked_settings}}', [
                'id' => $this->primaryKey(),
                'defaultCurrency' => $this->string(4)->null(),
                'softLockDurationMinutes' => $this->integer()->notNull()->defaultValue(5),
                'minimumAdvanceBookingHours' => $this->integer()->notNull()->defaultValue(0),
                'maximumAdvanceBookingDays' => $this->integer()->notNull()->defaultValue(90),
                'cancellationPolicyHours' => $this->integer()->notNull()->defaultValue(24),
                'enableRateLimiting' => $this->boolean()->notNull()->defaultValue(true),
                'rateLimitPerEmail' => $this->integer()->notNull()->defaultValue(5),
                'rateLimitPerIp' => $this->integer()->notNull()->defaultValue(10),
                'enableVirtualMeetings' => $this->boolean()->notNull()->defaultValue(false),
                'enableCaptcha' => $this->boolean()->notNull()->defaultValue(false),
                'captchaProvider' => $this->string(20)->null(),
                'recaptchaSiteKey' => $this->string(255)->null(),
                'recaptchaSecretKey' => $this->string(255)->null(),
                'hcaptchaSiteKey' => $this->string(255)->null(),
                'hcaptchaSecretKey' => $this->string(255)->null(),
                'turnstileSiteKey' => $this->string(255)->null(),
                'turnstileSecretKey' => $this->string(255)->null(),
                'recaptchaScoreThreshold' => $this->float()->notNull()->defaultValue(0.5),
                'recaptchaAction' => $this->string(100)->notNull()->defaultValue('booking'),
                'enableHoneypot' => $this->boolean()->notNull()->defaultValue(true),
                'honeypotFieldName' => $this->string(50)->notNull()->defaultValue('website'),
                'enableIpBlocking' => $this->boolean()->notNull()->defaultValue(false),
                'blockedIps' => $this->text()->null(),
                'enableTimeBasedLimits' => $this->boolean()->notNull()->defaultValue(true),
                'minimumSubmissionTime' => $this->integer()->notNull()->defaultValue(3),
                'enableAuditLog' => $this->boolean()->notNull()->defaultValue(false),
                'googleCalendarEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'googleCalendarClientId' => $this->string(255)->null(),
                'googleCalendarClientSecret' => $this->string(255)->null(),
                'googleCalendarWebhookUrl' => $this->string(255)->null(),
                'outlookCalendarEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'outlookCalendarClientId' => $this->string(255)->null(),
                'outlookCalendarClientSecret' => $this->string(255)->null(),
                'outlookCalendarWebhookUrl' => $this->string(255)->null(),
                'zoomEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'zoomAccountId' => $this->string(255)->null(),
                'zoomClientId' => $this->string(255)->null(),
                'zoomClientSecret' => $this->string(255)->null(),
                'googleMeetEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'teamsEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'teamsTenantId' => $this->string(255)->null(),
                'teamsClientId' => $this->string(255)->null(),
                'teamsClientSecret' => $this->string(255)->null(),
                'ownerNotificationEnabled' => $this->boolean()->notNull()->defaultValue(true),
                'ownerNotificationSubject' => $this->string(255)->null(),
                'ownerNotificationLanguage' => $this->string(255)->null(),
                'ownerEmail' => $this->string()->null(),
                'ownerName' => $this->string()->null(),
                'bookingConfirmationSubject' => $this->string(),
                'reminderEmailSubject' => $this->string(255)->null(),
                'cancellationEmailSubject' => $this->string(255)->null(),
                'bookingPageUrl' => $this->string(500)->null(),
                'emailRemindersEnabled' => $this->boolean()->notNull()->defaultValue(true),
                'emailReminderHoursBefore' => $this->integer()->notNull()->defaultValue(24),
                'sendCancellationEmail' => $this->boolean()->notNull()->defaultValue(true),
                'smsEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'smsProvider' => $this->string(50)->null(),
                'twilioAccountSid' => $this->string(255)->null(),
                'twilioAuthToken' => $this->string(255)->null(),
                'twilioPhoneNumber' => $this->string(50)->null(),
                'smsRemindersEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'smsReminderHoursBefore' => $this->integer()->notNull()->defaultValue(24),
                'smsConfirmationEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'smsCancellationEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'smsConfirmationTemplate' => $this->text()->null(),
                'smsReminderTemplate' => $this->text()->null(),
                'smsCancellationTemplate' => $this->text()->null(),
                'smsMaxRetries' => $this->integer()->notNull()->defaultValue(3),
                'defaultCountryCode' => $this->string(5)->notNull()->defaultValue('US'),
                'commerceEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'commerceTaxCategoryId' => $this->integer()->null(),
                'pendingCartExpirationHours' => $this->integer()->notNull()->defaultValue(48),
                'commerceCartUrl' => $this->string(255)->notNull()->defaultValue('shop/cart'),
                'commerceCheckoutUrl' => $this->string(255)->notNull()->defaultValue('shop/checkout'),
                'enableAutoRefund' => $this->boolean()->notNull()->defaultValue(false),
                'defaultRefundTiers' => $this->text()->null(),
                'webhooksEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'webhookTimeout' => $this->integer()->notNull()->defaultValue(30),
                'webhookLogEnabled' => $this->boolean()->notNull()->defaultValue(true),
                'webhookLogRetentionDays' => $this->integer()->notNull()->defaultValue(30),
                'enableWaitlist' => $this->boolean()->notNull()->defaultValue(true),
                'waitlistExpirationDays' => $this->integer()->notNull()->defaultValue(30),
                'waitlistNotificationLimit' => $this->integer()->notNull()->defaultValue(10),
                'waitlistConversionMinutes' => $this->integer()->notNull()->defaultValue(30),
                'mutexDriver' => $this->string(10)->notNull()->defaultValue('auto'),
                'defaultTimeSlotLength' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $now = date('Y-m-d H:i:s');
            $this->insert('{{%booked_settings}}', [
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ]);
        }

        if (!$this->db->tableExists('{{%booked_locations}}')) {
            $this->createTable('{{%booked_locations}}', [
                'id' => $this->primaryKey(),
                'timezone' => $this->string(50)->null(),
                'addressLine1' => $this->string()->null(),
                'addressLine2' => $this->string()->null(),
                'locality' => $this->string()->null(),
                'administrativeArea' => $this->string()->null(),
                'postalCode' => $this->string(20)->null(),
                'countryCode' => $this->string(2)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_locations}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_employees}}')) {
            $this->createTable('{{%booked_employees}}', [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'email' => $this->string(255)->null(),
                'workingHours' => $this->json()->null(),
                'serviceIds' => $this->json()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_employees}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%booked_employees}}', 'userId', '{{%users}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%booked_employees}}', 'locationId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->createIndex(null, '{{%booked_employees}}', 'userId');
            $this->createIndex(null, '{{%booked_employees}}', 'locationId');
        }

        if (!$this->db->tableExists('{{%booked_services}}')) {
            $this->createTable('{{%booked_services}}', [
                'id' => $this->primaryKey(),
                'propagationMethod' => $this->string(50)->notNull()->defaultValue('none'),
                'description' => $this->text()->null(),
                'duration' => $this->integer()->null(),
                'bufferBefore' => $this->integer()->null(),
                'bufferAfter' => $this->integer()->null(),
                'price' => $this->decimal(14, 4)->null(),
                'virtualMeetingProvider' => $this->string()->null(),
                'minTimeBeforeBooking' => $this->integer()->null(),
                'timeSlotLength' => $this->integer()->null(),
                'availabilitySchedule' => $this->text()->null(),
                'customerLimitEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'customerLimitCount' => $this->integer()->null(),
                'customerLimitPeriod' => $this->string(20)->null(),
                'customerLimitPeriodType' => $this->string(20)->null(),
                'enableWaitlist' => $this->boolean()->null(),
                'taxCategoryId' => $this->integer()->null(),
                'allowCancellation' => $this->boolean()->notNull()->defaultValue(true),
                'cancellationPolicyHours' => $this->integer()->null(),
                'allowRefund' => $this->boolean()->notNull()->defaultValue(true),
                'refundTiers' => $this->text()->null(),
                'deletedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_services}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_schedules}}')) {
            $this->createTable('{{%booked_schedules}}', [
                'id' => $this->primaryKey(),
                'workingHours' => $this->json()->notNull(),
                'startDate' => $this->date()->null(),
                'endDate' => $this->date()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_schedules}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->createIndex(null, '{{%booked_schedules}}', ['startDate', 'endDate']);
        }

        if (!$this->db->tableExists('{{%booked_employee_schedule_assignments}}')) {
            $this->createTable('{{%booked_employee_schedule_assignments}}', [
                'id' => $this->primaryKey(),
                'employeeId' => $this->integer()->notNull(),
                'scheduleId' => $this->integer()->notNull(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_employee_schedule_assignments}}', ['employeeId', 'scheduleId'], true);
            $this->createIndex(null, '{{%booked_employee_schedule_assignments}}', ['employeeId', 'sortOrder']);
            $this->addForeignKey(null, '{{%booked_employee_schedule_assignments}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%booked_employee_schedule_assignments}}', 'scheduleId', '{{%booked_schedules}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_service_schedule_assignments}}')) {
            $this->createTable('{{%booked_service_schedule_assignments}}', [
                'id' => $this->primaryKey(),
                'serviceId' => $this->integer()->notNull(),
                'scheduleId' => $this->integer()->notNull(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_service_schedule_assignments}}', ['serviceId', 'scheduleId'], true);
            $this->createIndex(null, '{{%booked_service_schedule_assignments}}', ['serviceId', 'sortOrder']);
            $this->addForeignKey(null, '{{%booked_service_schedule_assignments}}', 'serviceId', '{{%booked_services}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%booked_service_schedule_assignments}}', 'scheduleId', '{{%booked_schedules}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_event_dates}}')) {
            $this->createTable('{{%booked_event_dates}}', [
                'id' => $this->integer()->notNull(),
                'propagationMethod' => $this->string(50)->notNull()->defaultValue('none'),
                'locationId' => $this->integer()->null(),
                'eventDate' => $this->date()->notNull(),
                'endDate' => $this->date()->null(),
                'startTime' => $this->time()->notNull(),
                'endTime' => $this->time()->notNull(),
                'title' => $this->string(255)->null(),
                'description' => $this->text()->null(),
                'capacity' => $this->integer()->null(),
                'price' => $this->decimal(14, 4)->null(),
                'enableWaitlist' => $this->boolean()->null(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'allowCancellation' => $this->boolean()->notNull()->defaultValue(true),
                'cancellationPolicyHours' => $this->integer()->null(),
                'allowRefund' => $this->boolean()->notNull()->defaultValue(true),
                'refundTiers' => $this->text()->null(),
                'deletedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addPrimaryKey('PK_booked_event_dates', '{{%booked_event_dates}}', 'id');
            $this->addForeignKey(null, '{{%booked_event_dates}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%booked_event_dates}}', 'locationId', '{{%elements}}', 'id', 'SET NULL', 'CASCADE');
            $this->createIndex(null, '{{%booked_event_dates}}', ['eventDate', 'enabled']);
        }

        if (!$this->db->tableExists('{{%booked_service_extras}}')) {
            $this->createTable('{{%booked_service_extras}}', [
                'id' => $this->primaryKey(),
                'propagationMethod' => $this->string(50)->notNull()->defaultValue('none'),
                'price' => $this->decimal(14, 4)->null(),
                'duration' => $this->integer()->notNull()->defaultValue(0),
                'maxQuantity' => $this->integer()->notNull()->defaultValue(1),
                'isRequired' => $this->boolean()->notNull()->defaultValue(false),
                'description' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_service_extras}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_service_extras_services}}')) {
            $this->createTable('{{%booked_service_extras_services}}', [
                'id' => $this->primaryKey(),
                'extraId' => $this->integer()->notNull(),
                'serviceId' => $this->integer()->notNull(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_service_extras_services}}', 'extraId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_service_extras_services}}', 'serviceId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%booked_service_extras_services}}', ['extraId', 'serviceId'], true);
        }

        if (!$this->db->tableExists('{{%booked_service_locations}}')) {
            $this->createTable('{{%booked_service_locations}}', [
                'id' => $this->primaryKey(),
                'serviceId' => $this->integer()->notNull(),
                'locationId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_service_locations}}', 'serviceId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_service_locations}}', 'locationId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%booked_service_locations}}', ['serviceId', 'locationId'], true);
        }

        if (!$this->db->tableExists('{{%booked_reservations}}')) {
            $this->createTable('{{%booked_reservations}}', [
                'id' => $this->primaryKey(),
                'userName' => $this->string()->notNull(),
                'userEmail' => $this->string()->notNull(),
                'userPhone' => $this->string(),
                'userId' => $this->integer()->null(),
                'userTimezone' => $this->string(50)->null(),
                'bookingDate' => $this->date()->notNull(),
                'startTime' => $this->time()->notNull(),
                'endTime' => $this->time()->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('confirmed'),
                'activeSlotKey' => $this->string(255)->null(),
                'employeeId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'serviceId' => $this->integer()->null(),
                'eventDateId' => $this->integer()->null(),
                'siteId' => $this->integer()->null(),
                'quantity' => $this->integer()->notNull()->defaultValue(1),
                'notes' => $this->text(),
                'virtualMeetingUrl' => $this->string()->null(),
                'virtualMeetingProvider' => $this->string(50)->null(),
                'virtualMeetingId' => $this->string()->null(),
                'googleEventId' => $this->string(255)->null(),
                'outlookEventId' => $this->string(255)->null(),
                'notificationSent' => $this->boolean()->notNull()->defaultValue(false),
                'emailReminder24hSent' => $this->boolean()->notNull()->defaultValue(false),
                'emailReminder1hSent' => $this->boolean()->notNull()->defaultValue(false),
                'smsReminder24hSent' => $this->boolean()->notNull()->defaultValue(false),
                'smsConfirmationSent' => $this->boolean()->notNull()->defaultValue(false),
                'smsConfirmationSentAt' => $this->dateTime()->null(),
                'smsCancellationSent' => $this->boolean()->notNull()->defaultValue(false),
                'smsDeliveryStatus' => $this->string(20)->null(),
                'confirmationToken' => $this->string(64)->notNull()->unique(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // No FK on 'id' to 'elements.id' - Reservations can be Elements (Commerce) or standalone ActiveRecords
            $this->addForeignKey(null, '{{%booked_reservations}}', 'employeeId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%booked_reservations}}', 'locationId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%booked_reservations}}', 'serviceId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%booked_reservations}}', 'eventDateId', '{{%booked_event_dates}}', 'id', 'SET NULL', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_reservations}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

            $this->createIndex(null, '{{%booked_reservations}}', ['bookingDate', 'startTime']);
            $this->createIndex(null, '{{%booked_reservations}}', 'userEmail');
            $this->createIndex(null, '{{%booked_reservations}}', 'userId');
            $this->createIndex(null, '{{%booked_reservations}}', 'status');
            $this->createIndex(null, '{{%booked_reservations}}', 'employeeId');
            $this->createIndex(null, '{{%booked_reservations}}', 'locationId');
            $this->createIndex(null, '{{%booked_reservations}}', 'serviceId');
            $this->createIndex('idx_reservations_date_employee_status', '{{%booked_reservations}}', ['bookingDate', 'employeeId', 'status']);
            $this->createIndex('idx_reservations_date_service_status', '{{%booked_reservations}}', ['bookingDate', 'serviceId', 'status']);
            $this->createIndex(null, '{{%booked_reservations}}', 'eventDateId');
            $this->createIndex('idx_reservations_eventdate_status', '{{%booked_reservations}}', ['eventDateId', 'status']);
            $this->createIndex(null, '{{%booked_reservations}}', 'googleEventId');
            $this->createIndex(null, '{{%booked_reservations}}', 'outlookEventId');
            $this->createIndex('idx_confirmationToken', '{{%booked_reservations}}', 'confirmationToken', true);
            // activeSlotKey: "date|time|employeeId" for active bookings, NULL for cancelled/employee-less
            $this->createIndex('idx_unique_active_booking', '{{%booked_reservations}}', 'activeSlotKey', true);
        }

        if (!$this->db->tableExists('{{%booked_reservation_extras}}')) {
            $this->createTable('{{%booked_reservation_extras}}', [
                'id' => $this->primaryKey(),
                'reservationId' => $this->integer()->notNull(),
                'serviceExtraId' => $this->integer()->notNull(),
                'quantity' => $this->integer()->notNull()->defaultValue(1),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_reservation_extras}}', 'reservationId', '{{%booked_reservations}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_reservation_extras}}', 'serviceExtraId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%booked_reservation_extras}}', ['reservationId', 'serviceExtraId'], true);
            $this->createIndex(null, '{{%booked_reservation_extras}}', ['serviceExtraId']);
        }

        if (!$this->db->tableExists('{{%booked_blackout_dates}}')) {
            $this->createTable('{{%booked_blackout_dates}}', [
                'id' => $this->primaryKey(),
                'title' => $this->string()->notNull(),
                'startDate' => $this->date()->notNull(),
                'endDate' => $this->date()->notNull(),
                'isActive' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_blackout_dates}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->createIndex(null, '{{%booked_blackout_dates}}', ['startDate', 'endDate']);
            $this->createIndex(null, '{{%booked_blackout_dates}}', 'isActive');
        }

        if (!$this->db->tableExists('{{%booked_blackout_dates_locations}}')) {
            $this->createTable('{{%booked_blackout_dates_locations}}', [
                'id' => $this->primaryKey(),
                'blackoutDateId' => $this->integer()->notNull(),
                'locationId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_blackout_dates_locations}}', 'blackoutDateId', '{{%booked_blackout_dates}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_blackout_dates_locations}}', 'locationId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%booked_blackout_dates_locations}}', ['blackoutDateId', 'locationId'], true);
        }

        if (!$this->db->tableExists('{{%booked_blackout_dates_employees}}')) {
            $this->createTable('{{%booked_blackout_dates_employees}}', [
                'id' => $this->primaryKey(),
                'blackoutDateId' => $this->integer()->notNull(),
                'employeeId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_blackout_dates_employees}}', 'blackoutDateId', '{{%booked_blackout_dates}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_blackout_dates_employees}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%booked_blackout_dates_employees}}', ['blackoutDateId', 'employeeId'], true);
        }

        if (!$this->db->tableExists('{{%booked_order_reservations}}')) {
            $this->createTable('{{%booked_order_reservations}}', [
                'id' => $this->primaryKey(),
                'orderId' => $this->integer()->notNull(),
                'reservationId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_order_reservations}}', ['orderId', 'reservationId'], true);
            $this->createIndex(null, '{{%booked_order_reservations}}', 'reservationId');
        }

        if (!$this->db->tableExists('{{%booked_soft_locks}}')) {
            $this->createTable('{{%booked_soft_locks}}', [
                'id' => $this->primaryKey(),
                'token' => $this->string(64)->notNull(),
                'sessionHash' => $this->string(64)->null(),
                'serviceId' => $this->integer()->notNull(),
                'employeeId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'date' => $this->date()->notNull(),
                'startTime' => $this->string(10)->notNull(),
                'endTime' => $this->string(10)->notNull(),
                'quantity' => $this->integer()->notNull()->defaultValue(1),
                'expiresAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_soft_locks}}', 'token', true);
            $this->createIndex(null, '{{%booked_soft_locks}}', ['date', 'startTime', 'serviceId']);
            $this->createIndex(null, '{{%booked_soft_locks}}', 'expiresAt');
        }

        if (!$this->db->tableExists('{{%booked_calendar_tokens}}')) {
            $this->createTable('{{%booked_calendar_tokens}}', [
                'id' => $this->primaryKey(),
                'employeeId' => $this->integer()->notNull(),
                'provider' => $this->string(50)->notNull(),
                'accessToken' => $this->text()->notNull(),
                'refreshToken' => $this->text()->null(),
                'expiresAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%booked_calendar_tokens}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%booked_calendar_tokens}}', ['employeeId', 'provider'], true);
        }

        if (!$this->db->tableExists('{{%booked_oauth_state_tokens}}')) {
            $this->createTable('{{%booked_oauth_state_tokens}}', [
                'id' => $this->primaryKey(),
                'token' => $this->string()->notNull()->unique(),
                'provider' => $this->string(50)->notNull(),
                'employeeId' => $this->integer()->null(),
                'expiresAt' => $this->dateTime()->notNull(),
                'createdAt' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_oauth_state_tokens}}', 'token', true);
            $this->createIndex(null, '{{%booked_oauth_state_tokens}}', 'expiresAt');
        }

        if (!$this->db->tableExists('{{%booked_calendar_sync_status}}')) {
            $this->createTable('{{%booked_calendar_sync_status}}', [
                'id' => $this->primaryKey(),
                'employeeId' => $this->integer()->notNull(),
                'provider' => $this->string(20)->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('disconnected'),
                'lastSyncAt' => $this->dateTime()->null(),
                'lastSyncSuccess' => $this->boolean()->null(),
                'lastSyncError' => $this->text()->null(),
                'syncCount' => $this->integer()->notNull()->defaultValue(0),
                'errorCount' => $this->integer()->notNull()->defaultValue(0),
                'webhookSubscriptionId' => $this->string(255)->null(),
                'webhookExpiresAt' => $this->dateTime()->null(),
                'webhookResourceId' => $this->string(255)->null(),
                'webhookResourceUri' => $this->string(500)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_calendar_sync_status}}', ['employeeId', 'provider'], true);
            $this->createIndex(null, '{{%booked_calendar_sync_status}}', 'status');
            $this->createIndex(null, '{{%booked_calendar_sync_status}}', 'lastSyncAt');
            $this->addForeignKey(null, '{{%booked_calendar_sync_status}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_calendar_invites}}')) {
            $this->createTable('{{%booked_calendar_invites}}', [
                'id' => $this->primaryKey(),
                'employeeId' => $this->integer()->notNull(),
                'provider' => $this->string(20)->notNull(),
                'token' => $this->string(64)->notNull(),
                'email' => $this->string(255)->notNull(),
                'expiresAt' => $this->dateTime()->notNull(),
                'usedAt' => $this->dateTime()->null(),
                'createdBy' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_calendar_invites}}', 'token', true);
            $this->createIndex(null, '{{%booked_calendar_invites}}', ['employeeId', 'provider']);
            $this->createIndex(null, '{{%booked_calendar_invites}}', 'expiresAt');
            $this->addForeignKey(null, '{{%booked_calendar_invites}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%booked_calendar_invites}}', 'createdBy', '{{%users}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_webhooks}}')) {
            $this->createTable('{{%booked_webhooks}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'url' => $this->string(500)->notNull(),
                'secret' => $this->text()->null(),
                'events' => $this->json()->notNull(),
                'headers' => $this->json()->null(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'retryCount' => $this->integer()->notNull()->defaultValue(3),
                'payloadFormat' => $this->string(20)->notNull()->defaultValue('standard'),
                'siteId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_webhooks}}', 'enabled');
            $this->createIndex(null, '{{%booked_webhooks}}', 'siteId');
            $this->addForeignKey(null, '{{%booked_webhooks}}', 'siteId', '{{%sites}}', 'id', 'SET NULL', 'CASCADE');
        }

        if (!$this->db->tableExists('{{%booked_webhook_logs}}')) {
            $this->createTable('{{%booked_webhook_logs}}', [
                'id' => $this->primaryKey(),
                'webhookId' => $this->integer()->notNull(),
                'event' => $this->string(50)->notNull(),
                'reservationId' => $this->integer()->null(),
                'url' => $this->string(500)->notNull(),
                'requestHeaders' => $this->json()->null(),
                'requestBody' => $this->text()->null(),
                'responseCode' => $this->integer()->null(),
                'responseBody' => $this->text()->null(),
                'success' => $this->boolean()->notNull(),
                'errorMessage' => $this->text()->null(),
                'duration' => $this->integer()->null(),
                'attempt' => $this->integer()->notNull()->defaultValue(1),
                'dateCreated' => $this->dateTime()->notNull(),
            ]);
            $this->createIndex(null, '{{%booked_webhook_logs}}', ['webhookId', 'dateCreated']);
            $this->createIndex(null, '{{%booked_webhook_logs}}', ['success', 'dateCreated']);
            $this->createIndex(null, '{{%booked_webhook_logs}}', 'reservationId');
            $this->addForeignKey(null, '{{%booked_webhook_logs}}', 'webhookId', '{{%booked_webhooks}}', 'id', 'CASCADE', 'CASCADE');
        }

        if (!$this->db->tableExists('{{%booked_employee_managers}}')) {
            $this->createTable('{{%booked_employee_managers}}', [
                'id' => $this->primaryKey(),
                'employeeId' => $this->integer()->notNull(),
                'managedEmployeeId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_employee_managers}}', ['employeeId', 'managedEmployeeId'], true);
            $this->createIndex(null, '{{%booked_employee_managers}}', 'managedEmployeeId');
            $this->addForeignKey(null, '{{%booked_employee_managers}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%booked_employee_managers}}', 'managedEmployeeId', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%booked_waitlist}}')) {
            $this->createTable('{{%booked_waitlist}}', [
                'id' => $this->primaryKey(),
                'serviceId' => $this->integer()->null(),
                'eventDateId' => $this->integer()->null(),
                'employeeId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'preferredDate' => $this->date()->null(),
                'preferredTimeStart' => $this->time()->null(),
                'preferredTimeEnd' => $this->time()->null(),
                'userName' => $this->string()->notNull(),
                'userEmail' => $this->string()->notNull(),
                'userPhone' => $this->string()->null(),
                'userId' => $this->integer()->null(),
                'priority' => $this->integer()->notNull()->defaultValue(0),
                'notifiedAt' => $this->dateTime()->null(),
                'expiresAt' => $this->dateTime()->null(),
                'status' => $this->string(20)->notNull()->defaultValue('active'),
                'conversionToken' => $this->string(64)->null(),
                'conversionExpiresAt' => $this->dateTime()->null(),
                'notes' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%booked_waitlist}}', ['serviceId', 'status', 'priority']);
            $this->createIndex(null, '{{%booked_waitlist}}', ['userEmail']);
            $this->createIndex(null, '{{%booked_waitlist}}', ['status']);
            $this->createIndex(null, '{{%booked_waitlist}}', ['expiresAt']);
            $this->createIndex(null, '{{%booked_waitlist}}', ['userId']);
            $this->createIndex(null, '{{%booked_waitlist}}', ['conversionToken'], true);
            $this->addForeignKey(null, '{{%booked_waitlist}}', 'serviceId', '{{%elements}}', 'id', 'CASCADE');
            $this->addForeignKey(null, '{{%booked_waitlist}}', 'eventDateId', '{{%booked_event_dates}}', 'id', 'SET NULL');
            $this->addForeignKey(null, '{{%booked_waitlist}}', 'employeeId', '{{%elements}}', 'id', 'SET NULL');
            $this->addForeignKey(null, '{{%booked_waitlist}}', 'locationId', '{{%elements}}', 'id', 'SET NULL');
            $this->addForeignKey(null, '{{%booked_waitlist}}', 'userId', '{{%users}}', 'id', 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $isMySQL = $this->db->getDriverName() === 'mysql';
        if ($isMySQL) {
            $this->execute('SET FOREIGN_KEY_CHECKS = 0');
        }

        foreach ([
            'booked_employee_managers', 'booked_waitlist',
            'booked_webhook_logs', 'booked_webhooks',
            'booked_calendar_invites', 'booked_calendar_sync_status',
            'booked_oauth_state_tokens', 'booked_calendar_tokens',
            'booked_soft_locks', 'booked_order_reservations',
            'booked_blackout_dates_employees', 'booked_blackout_dates_locations', 'booked_blackout_dates',
            'booked_reservation_extras', 'booked_reservations', 'booked_event_dates',
            'booked_service_locations',
            'booked_service_extras_services', 'booked_service_extras',
            'booked_service_schedule_assignments', 'booked_employee_schedule_assignments',
            'booked_schedules', 'booked_services', 'booked_employees', 'booked_locations',
            'booked_settings',
        ] as $table) {
            $this->dropTableIfExists("{{%{$table}}}");
        }

        if ($isMySQL) {
            $this->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return true;
    }
}

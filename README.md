# Booked - Advanced Booking System for Craft CMS

A comprehensive booking and reservation management plugin for Craft CMS, designed with flexibility, performance, and developer experience in mind.

## Features

### Core Booking System
- **Service Management**: Create and manage multiple services with custom durations, pricing, and availability
- **Event-Based Bookings**: Create one-time events with simple capacity management
- **Employee/Resource Scheduling**: Assign employees to services with individual schedules and locations
- **Managed Employees**: Staff users can oversee multiple employees' bookings without each employee needing a Craft account
- **Multi-Location Support**: Manage bookings across multiple physical locations with timezone handling
- **Flexible Availability**: Define recurring schedules, one-time availability windows, and blackout dates
- **Capacity Management**: Support for group bookings with configurable capacity limits
- **Service Extras**: Optional add-ons with pricing and duration (e.g., "Extended consultation +30 min")
- **Waitlist Management**: Automatic waitlist with conversion when slots become available
- **Customer Booking Limits**: Configurable limits per service to prevent overbooking
- **Customer Self-Service Portal**: Logged-in customers can view, manage, and cancel their bookings
- **Multi-Site Support**: Localized services with propagation across multiple sites

### Advanced Features
- **Calendar Sync**: Two-way sync with Google Calendar and Microsoft Outlook
- **Virtual Meetings**: Automatic Zoom, Google Meet, and Microsoft Teams meeting creation for online appointments
- **Payment Integration**: Native Craft Commerce integration
- **Email Notifications**: Customizable email templates for confirmations, reminders, and cancellations
- **SMS Notifications**: Twilio integration for booking confirmations, reminders, and cancellations
- **Webhooks**: Send booking events to Zapier, n8n, Make, or custom endpoints with HMAC signing
- **Anti-Spam Protection**: reCAPTCHA v3, hCaptcha, Turnstile, and honeypot support

### Performance & Scalability
- **Intelligent Caching**: Tag-based cache invalidation for optimal performance
- **Database Optimization**: Composite indexes and query optimization for large datasets
- **Background Processing**: Queue-based email sending and calendar sync
- **Soft Locking**: Race condition protection for concurrent bookings
- **Timezone Support**: Automatic timezone conversion for global bookings

### Developer Experience
- **Event System**: Comprehensive event hooks for custom business logic
- **GraphQL Support**: Full GraphQL API for headless implementations
- **RESTful API**: Query and manage bookings programmatically
- **Extensible Architecture**: Service-based design for easy customization

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- MySQL 8.0.17+ or PostgreSQL 13+
- Composer

## Quick Start

### Installation

```bash
composer require anvildev/craft-booked
php craft plugin/install booked
```

With ddev

```bash
ddev composer require anvildev/craft-booked
ddev php craft plugin/install booked
```

## Documentation

### Getting Started
- [Tutorial](TUTORIAL.md) - **Start here** - Build your first booking system in 15 minutes

### Setup & Configuration
- [Configuration Guide](CONFIGURATION.md) - Complete configuration reference

### Core Features
- [Availability & Schedule System](AVAILABILITY.md) - Complete guide to how availability and schedules work
- [Event-Based Bookings](EVENT_BOOKINGS.md) - Guide to creating and managing one-time events
- [Employee Schedule Management](EMPLOYEE_SCHEDULE_MANAGEMENT.md) - Frontend employee self-service schedule management
- [Field Types](FIELD_TYPES.md) - Custom relation fields for Services and Event Dates

### Notifications & Integrations
- [Email Templates](EMAIL_TEMPLATES.md) - Customize confirmation, reminder, and cancellation emails
- [SMS Notifications](SMS_NOTIFICATIONS.md) - Twilio SMS setup for confirmations, reminders, and cancellations
- [Webhooks](WEBHOOKS.md) - Send events to Zapier, n8n, Make, or custom endpoints

### Planning
- [Roadmap](ROADMAP.md) - Planned features and future direction

### Development
- [Developer Guide](DEVELOPER_GUIDE.md) - API reference and extension guide
- [Event System](EVENT_SYSTEM.md) - Comprehensive event system documentation with examples
- [GraphQL API](GRAPHQL.md) - GraphQL schema and query examples
- [Console Commands](CONSOLE_COMMANDS.md) - CLI commands for reminders, cleanup, and diagnostics

## Support

- **Documentation**: [Full documentation](DEVELOPER_GUIDE.md)
- **Issues**: [GitHub Issues](https://github.com/anvildev/craft-booked/issues)
- **Discussions**: [GitHub Discussions](https://github.com/anvildev/craft-booked/discussions)

## License

Copyright © anvildev. All rights reserved.

## Credits

Developed by anvildev for Craft CMS.

Built with:
- [Craft CMS](https://craftcms.com)
- [Yii Framework](https://www.yiiframework.com)
- [Google Calendar API](https://developers.google.com/calendar)
- [Microsoft Graph API](https://developer.microsoft.com/graph)

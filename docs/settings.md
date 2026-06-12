# Settings

> Configure user preferences, data management, and admin settings to customize the Budget app for your workflow.

## Overview

The Settings page provides control over localization, number formatting, budget periods, notifications, import behavior, security, and data management. Most settings take effect immediately.

Access settings via **Settings** in the sidebar navigation.

## Localization

Configure how the app displays regional information:

- **Default Currency** - Choose from 45+ world currencies (e.g., USD, EUR, GBP, JPY). This sets the default currency for new accounts and display formatting throughout the app.
- **Date Format** - Select your preferred date display format (e.g., DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD).
- **First Day of Week** - Set whether your calendar week starts on Sunday, Monday, or Saturday. Affects weekly reports and date pickers.

> **Tip:** The currency setting only affects display formatting and new account defaults. Existing accounts retain their configured currency.

## Number Format

Control how numbers and amounts are displayed:

- **Decimal Places** - Choose between 0 and 5 decimal places for currency display
- **Decimal Separator** - Select `.` (period) or `,` (comma)
- **Thousands Separator** - Select `,` (comma), `.` (period), or a space

A live preview shows how your formatted numbers will appear with the current settings (e.g., `1,234.56` or `1.234,56`).

> **Note:** These settings affect display only. Stored values always use full precision internally.

## Budget Settings

Configure how your budget periods work:

- **Default Budget Period** - Set the default time period for budget tracking (monthly, weekly, etc.)
- **Budget Start Day** - Choose a day of the month (1-31) for when your budget month begins. Useful if your pay cycle doesn't align with calendar months.

> **Tip:** If you get paid on the 15th, setting the budget start day to 15 means your budget period runs from the 15th of one month to the 14th of the next.

## Notification Settings

Toggle notifications for budget events:

- **Budget Alert Notifications** - Receive alerts when spending approaches or exceeds budget limits (on/off)
- **Forecast Warning Notifications** - Get warned when balance forecasts predict issues like going below zero (on/off)
- **Unusual Spending Alerts** - Notify when a category runs well above its 6-month typical spending. Median-based with a minimum-amount floor and at most one alert per category per month (on by default)
- **Budget Digest** - Opt-in weekly or monthly summary (income vs spending, budget status, upcoming bills, goals, anomalies, new bill suggestions) as a Nextcloud notification, optionally also by email

## Scheduled Reports

Have the previous month's summary report delivered automatically at the start of each month:

- **Monthly Report to Files** - Saves a PDF into `Budget/Reports/<year>/` in your Files; a notification links to it
- **Monthly Report by Email** - Emails the PDF as an attachment (requires the Nextcloud instance to have a working mail server)

Both can be enabled independently; with both off the feature is inactive.

## Import Settings

Configure behavior for transaction imports:

- **Auto-Apply Import Rules** - When enabled, import rules are automatically applied to categorize transactions during CSV imports
- **Skip Duplicate Transactions** - When enabled, transactions that appear to be duplicates are automatically skipped during import
- **Default Export Format** - Set the default file format for data exports

> **Note:** Duplicate detection uses a combination of date, amount, and description to identify potential duplicates.

## Quick Add Page

The **Quick Add Page** is a standalone, minimal page for entering a single transaction on the go — it shows only the entry form, with no balances, account details, or other sensitive information visible. This makes it safe to use in public (e.g. paying at a till) without revealing your finances.

- The settings panel shows a copyable **Quick Add URL** (`/apps/budget/quick-add`) you can bookmark or add to a device home screen.
- The Dashboard's **Quick Add Transaction** tile also has a **Standalone ↗** link that opens it in a new tab.

> **iPhone/iPad tip:** iOS Safari always points "Add to Home Screen" at the site root, so it can't link directly to the Quick Add page. A community workaround using the Apple Shortcuts app is documented here: [Solution: iPhone and Home Screen Link for "Quick Add"](https://github.com/otherworld-dev/Budget/discussions/261).

## System Info

The System Info panel provides diagnostic information useful when reporting issues:

- **App & Server Details** — Budget version, Nextcloud version, PHP version, database type
- **Data Stats** — Account count, transaction count, category count, active rules, bills, bank sync connections, sharing status
- **Browser & Screen** — Browser version and viewport dimensions
- **Client Diagnostics** — Failed API requests and JavaScript errors captured during the current session
- **Server Logs** (admin only) — Recent budget-related entries from the Nextcloud log

Click **Copy to Clipboard** to copy all diagnostic info as plain text for pasting into bug reports.

## Data Migration

Tools for moving your data between instances:

- **Export** - Downloads all your budget data (accounts, transactions, categories, rules, settings, etc.) as a single JSON file. Use this for backups or migrating to a new Nextcloud instance.
- **Import** - Upload a previously exported JSON file to restore your data. This is intended for setting up a new instance with existing data.

> **Warning:** Importing data on an instance that already has budget data may cause duplicates. Use on a fresh installation or after a factory reset.

## Factory Reset

Permanently delete ALL your budget data:

- Removes all accounts, transactions, categories, bills, savings goals, rules, and settings
- Audit logs are preserved for security purposes
- Requires explicit confirmation before proceeding
- **Cannot be undone**

> **Warning:** Factory reset is irreversible. Consider exporting your data first if you might need it later.

## Admin Settings

These settings are only visible to Nextcloud administrators:

- **Enable Bank Sync** - Toggle the [Bank Sync](bank-sync.md) feature on or off for all users on the instance. An experimental feature warning is displayed when this section is visible.

> **Note:** Admin settings affect all users on the Nextcloud instance, not just the administrator's own account.

## Related Features

- [Bank Sync](bank-sync.md) - Automatic transaction imports from external banks
- [Import](import.md) - Manual transaction import from files
- [Exchange Rates](exchange-rates.md) - Multi-currency support configuration

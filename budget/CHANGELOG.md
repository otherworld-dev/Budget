# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.15] - 2026-01-17

### Added
- Split expenses / shared budgeting feature
  - Add contacts to share expenses with (roommates, partners, friends)
  - Track who owes whom with real-time balance updates
  - Split transactions 50/50 or with custom amounts
  - Record settlement payments when debts are paid
  - View detailed history of shared expenses per contact
  - See total owed and owing summary cards
  - Navigate to dedicated Split Expenses section

## [1.0.14] - 2026-01-17

### Added
- Year-over-Year comparison reports
  - Compare spending across multiple years side-by-side
  - Full year comparison with income, expenses, and savings
  - Same month comparison to see how this month stacks up historically
  - Category spending comparison showing trends by category
  - Visual charts for monthly trends across years
  - Percentage change indicators for quick analysis

## [1.0.13] - 2026-01-17

### Added
- Debt payoff planner with avalanche and snowball strategies
  - View all debt accounts (credit cards, loans, mortgages, lines of credit)
  - Calculate payoff timeline based on strategy and extra payments
  - Compare avalanche (highest interest first) vs snowball (smallest balance first)
  - See total interest paid and debt-free date
  - Set minimum payments on liability accounts
  - Dashboard card showing debt summary when debts exist
  - Navigate to dedicated Debt Payoff section

## [1.0.12] - 2026-01-17

### Added
- Bill reminder notifications
  - Set reminders for recurring bills (on due date, 1-14 days before)
  - Receive Nextcloud notifications when bills are due soon
  - Background job checks every 6 hours for upcoming bills
  - One reminder per billing period (avoids duplicate notifications)
  - Overdue bill notifications for missed due dates

## [1.0.11] - 2026-01-17

### Added
- Budget alerts dashboard widget
  - Automatically shows when categories are approaching (80%) or exceeding (100%) their budgets
  - Visual progress bars with warning (yellow) and danger (red) states
  - Shows spent amount vs budget amount for each category
  - Supports all budget periods: weekly, monthly, quarterly, yearly
  - Includes split transaction amounts in budget calculations
  - Card only appears when there are active alerts

## [1.0.10] - 2026-01-17

### Added
- Split transaction feature for allocating transactions across multiple categories
  - Split a single transaction into multiple category allocations
  - Each split can have its own amount and optional description
  - Real-time validation ensures splits sum to transaction total
  - Unsplit transactions to revert to single-category assignment
  - Split indicator badge shown in transaction table for split transactions
  - Minimum 2 splits required for a valid split transaction

## [1.0.9] - 2026-01-17

### Added
- Recurring income tracking feature
  - Track expected income sources (salary, dividends, rental income, etc.)
  - Set frequency (weekly, monthly, quarterly, yearly) and expected day
  - Source field to track who pays the income
  - Link income to categories and accounts
  - Auto-detect pattern for matching transactions
  - Mark income as received to advance to next expected date
  - Summary cards showing expected/received this month and monthly total
  - Filter tabs for All/Expected Soon/Received
  - New "Income" section in navigation

## [1.0.8] - 2026-01-17

### Added
- Net worth history tracking with dashboard chart
  - Daily automatic snapshots via background job
  - Manual snapshot recording option
  - Track total assets, liabilities, and net worth over time
  - Interactive chart with 30-day, 90-day, and 1-year views
  - Shows net worth trend with assets/liabilities reference lines

## [1.0.7] - 2026-01-16

### Added
- Pension tracker for retirement planning
  - Track multiple pension accounts (workplace, personal, SIPP, defined benefit, state)
  - Balance history tracking via manual snapshots
  - One-off contribution tracking with notes
  - Per-pension settings: growth rate, retirement age, currency
  - Projections showing pot value at retirement using compound interest formula
  - Combined projection across all pensions
  - Dashboard card showing total pension worth or projected income
  - Separate "Pensions" section in navigation
- Pension types with different display logic:
  - DC pensions (workplace, personal, SIPP): show pot value with growth projections
  - DB pensions: show annual income at retirement with optional transfer value
  - State pension: show annual amount

## [1.0.6] - 2026-01-15

### Added
- Transaction matching for transfer detection between accounts
- Automatic detection of potential transfer matches (same amount, opposite type, within 3 days)
- Link/unlink transactions as transfer pairs
- Visual indicator for linked transactions in transaction list
- Bulk "Match All" feature for batch transaction matching
  - Auto-links transactions with exactly one match
  - Manual review modal for transactions with multiple potential matches
  - Undo option for auto-matched pairs
- Pagination controls at bottom of transaction table for easier navigation

### Changed
- App icon updated to piggy bank design for better theme compatibility

### Fixed
- PHP 8 deprecation warning: optional parameter declared before required parameters in ReportService
- Transaction page pagination not loading subsequent pages (page parameter was missing from API requests)
- Category creation failing with "updatedAt is not a valid attribute" error (added missing column)

## [1.0.5] - 2026-01-14

### Fixed
- Removed deprecated app.php (IBootstrap handles all bootstrapping)
- Boolean columns made nullable to avoid DBAL compatibility issues across databases

## [1.0.3] - 2026-01-13

### Fixed
- Database index naming collision that prevented installation
- Boolean column default values incompatible with Nextcloud DBAL

## [1.0.0] - 2026-01-13

### Added
- Multi-account management with support for multiple currencies
- Transaction tracking with advanced filtering and search
- Bank statement import (CSV, OFX, QIF formats)
- Automatic vendor matching during import
- Custom import rules for auto-categorization
- Hierarchical categories with drag-and-drop reordering
- Balance forecasting with trend analysis and scenario modeling
- Recurring bill detection and due date monitoring
- Savings goals with progress tracking and achievement forecasting
- Reports and charts for spending patterns, income, and cash flow
- Full data export/import for instance migration
- Audit logging for all financial actions

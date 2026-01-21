# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Configurable transaction table columns - show/hide Date, Description, Vendor, Category, Amount, and Account columns
- Gear icon in transaction table header to access column visibility settings
- Column visibility preferences persist across sessions via settings API
- Vendor column added to transaction table with inline editing support

### Changed
- Removed redundant category dropdown and categorize button from bulk actions panel (use Edit Fields modal instead)
- Improved visibility of column configuration gear icon with grey background and white icon color

### Fixed
- Bulk edit modal appearing in top-left corner instead of centered on screen

## [1.0.34] - 2026-01-21

### Added
- Bulk actions for transactions page (GitHub issue #10)
- Bulk delete: Delete multiple transactions in a single API call
- Bulk reconcile: Mark multiple transactions as reconciled/unreconciled
- Bulk edit: Update category, vendor, reference, and notes for multiple transactions at once
- Three new API endpoints: `/api/transactions/bulk-delete`, `/api/transactions/bulk-reconcile`, `/api/transactions/bulk-edit`
- Bulk edit modal with form validation and theme-consistent styling
- "Mark Reconciled", "Mark Unreconciled", and "Edit Fields..." buttons to bulk actions toolbar
- Input validation and sanitization for all bulk operations
- Rate limiting on bulk endpoints (10 requests/minute)
- Success/failure counts in API responses with detailed error tracking

### Changed
- Bulk delete and bulk categorize now use dedicated bulk API endpoints instead of individual API calls for improved performance
- Bulk actions panel now uses theme-aware CSS variables (`var(--color-background-dark)`) instead of hardcoded light blue colors
- Bulk actions panel adapts to both light and dark themes automatically

## [1.0.33] - 2026-01-20

### Fixed
- Duplicate transaction detection completely broken during statement imports (GitHub issue #6)
- OFX FITID (bank transaction ID) was lost during transaction mapping, preventing bank-provided duplicate detection
- Import IDs used random file identifiers instead of content hashing, causing same transaction to generate different IDs
- Preview methods didn't generate import IDs before duplicate checking, so duplicates were never detected in preview
- Import preview showed no indication of which transactions were duplicates
- "Show duplicates" and "Show uncategorized" checkboxes had no effect on preview display
- Wrong checkbox ID used in JavaScript ('skip-duplicates' vs 'show-duplicates')
- Error status badges and balance amounts too dark to read (GitHub issue #8)
- Installation failure on PostgreSQL: "Column is type Bool and also NotNull, so it can not store false" (GitHub issue #5)
- Migration Version001000017 used `notnull => true` for boolean columns, violating Nextcloud's cross-database compatibility requirements
- Boolean columns `is_settled` and `apply_on_import` now correctly defined as nullable per Nextcloud standards

### Changed
- TransactionNormalizer now preserves OFX transaction 'id' field for duplicate detection
- Import ID generation changed from `fileId_index_hash` to content-based: `ofx_fitid_{id}` for OFX or `hash_{md5(date+amount+description+reference)}` for CSV/QIF
- Same transaction imported multiple times now generates same import ID, enabling proper duplicate detection
- Import preview now includes 'isDuplicate' flag on each transaction
- Duplicate transactions displayed with red "Duplicate" badge, new transactions with green "New" badge
- Duplicate transactions unchecked by default in preview to prevent accidental import
- "Show duplicates" and "Show uncategorized" checkboxes now filter preview table in real-time
- Preview counter updates to reflect filtered results
- Error status badges and balance amounts now use brighter colors for improved readability

## [1.0.32] - 2026-01-19

### Fixed
- Background job ArgumentCountError flooding logs: "Too few arguments to function BillReminderJob::__construct()"
- All background jobs (BillReminderJob, CleanupImportFilesJob, NetWorthSnapshotJob, CleanupAuditLogsJob) now use lazy dependency injection via Server::get()
- Removed manual background job service registrations that weren't used by Nextcloud's cron system

### Added
- SettingService to properly wrap SettingMapper following architectural patterns
- Convenient methods for user settings: get(), set(), getAll(), delete(), exists()

## [1.0.31] - 2026-01-19

### Fixed
- Account balances showing scientific notation (e.g., `9.9920072216264e-15`) due to floating-point precision errors
- Balance calculations now use BCMath for precise decimal arithmetic via MoneyCalculator
- TransactionService, NetWorthService, and DebtPayoffService now prevent precision loss during calculations
- Migration added to automatically clean up existing balances with precision errors

### Changed
- AccountMapper.updateBalance() now accepts both float and string parameters for better precision handling
- All balance arithmetic operations now use string-based BCMath calculations internally

## [1.0.30] - 2026-01-19

### Fixed
- Account numbers displaying as extremely long strings of asterisks when decryption fails
- Added error handling for encryption/decryption failures with proper logging
- Masking functions now detect failed decryption and display "[DECRYPTION FAILED]" message
- Backend now rejects masked values (containing asterisks) when updating accounts
- Prevents re-encryption of masked account numbers sent from frontend during balance updates
- Fixed reflection property sync issue where decrypted values weren't updating the raw property
- Account updates (e.g., balance changes) no longer corrupt encrypted account numbers

## [1.0.29] - 2026-01-18

### Fixed
- Transaction category changes no longer affect account balance (GitHub issue #3)
- Inline category editor now works properly on transactions page
- Fixed double debit bug when updating transaction categories

## [1.0.28] - 2026-01-18

### Fixed
- Fthaixed Version001000018 cleanup migration: getPrefix() error and NOT NULL boolean columns
- All migrations now use system config to get table prefix
- All boolean columns now nullable across all migrations

## [1.0.27] - 2026-01-18

### Fixed
- Database migration error: Boolean columns must be nullable to avoid DBAL compatibility issues
- Changed is_settled and apply_on_import columns from NOT NULL to nullable
- Fixes "cannot store false" error during migrations

## [1.0.26] - 2026-01-18

### Fixed
- Migration error "Call to undefined method OC\DB\ConnectionAdapter::getPrefix()"
- Now uses system config to retrieve table prefix instead of connection method

## [1.0.25] - 2026-01-18

### Fixed
- **FINAL FIX**: Migrations now drop entire tables before recreating them
- Prevents schema reconciliation errors by ensuring clean slate
- Works automatically through Nextcloud Apps UI
- Note: Shared expenses and recurring income data will be lost (features were non-functional anyway due to migration errors)

## [1.0.24] - 2026-01-18

### Fixed
- Cleanup migration that drops and recreates broken tables automatically
- Works through Nextcloud Apps UI - no manual database access required
- Migration 001000018 runs after problematic migrations to fix failed installations
- Users can now update through the UI and the app will self-heal

## [1.0.23] - 2026-01-18

### Fixed
- Database migration errors: Use raw SQL to drop broken columns from actual database
- PreSchemaChange now executes ALTER TABLE DROP COLUMN directly on database
- Ensures broken columns are removed before schema reconciliation begins

## [1.0.22] - 2026-01-18

### Fixed
- Database migration errors: Use preSchemaChange to drop broken columns before schema reconciliation
- Prevents "can not store false" errors by removing broken columns before Nextcloud compares schemas
- Final fix for users stuck on migration 001000015 errors

## [1.0.21] - 2026-01-18

### Fixed
- Database migration robustness: Migrations now detect and repair existing broken boolean columns
- Handles both fresh installs and repairing existing installations in same migration
- Critical fix for users stuck on migration errors from v1.0.18

## [1.0.20] - 2026-01-18

### Fixed
- Database migration error for existing installations: Recreate boolean columns with correct defaults
- Fixes columns is_settled, is_active, is_split, and apply_on_import that were created with incorrect defaults

## [1.0.19] - 2026-01-18

### Fixed
- Database migration error: Boolean column defaults must be integers (0/1) not boolean literals (false/true)
- Fixed migrations 001000011, 001000012, 001000015, and 001000016

## [1.0.18] - 2026-01-18

### Fixed
- Category spending API returning 412 error (missing route and CSRF token header)

## [1.0.17] - 2026-01-18

### Performance
- Categories page loads ~10x faster (fixed O(n²) tree building algorithm)
- Budget analysis uses single batch query instead of N+1 queries per category
- Category rendering pre-computes transaction counts (O(n) instead of O(n*m))
- Initial app load ~2-3x faster (parallel API requests for settings, accounts, categories)

## [1.0.16] - 2026-01-18

### Added
- Standalone Rules feature (decoupled from Import)
- Split and Share buttons on transaction list for quick access to category splitting and expense sharing
  - Rules now accessible from top-level navigation
  - Apply rules to existing transactions at any time
  - Preview rule matches before applying changes
  - Filter by account, date range, or uncategorized transactions only
  - Rules can set multiple fields: category, vendor, notes
  - Option to control whether rules apply during import
  - Compact table-based rules list matching transactions page style
  - Toggle switch to enable/disable rules directly from the table

### Changed
- Reorganized navigation menu into logical groups (Core Data, Budgeting, Goals, Analysis)
- Moved Import, Rules, and Settings to collapsible bottom section (Nextcloud style)
- Removed Import Rules tab from Import page (rules now managed from dedicated Rules page)
- Import wizard includes checkbox to optionally apply rules during import
- Renamed "Split Expenses" to "Shared Expenses" for clarity

### Fixed
- Budget alerts API returning 500 error (incorrect constant reference in TransactionSplitMapper)
- Add Rule button not working (duplicate HTML element IDs)
- Rules API endpoint URL mismatch causing HTTP 500 errors
- Checkbox styling in rule modal (oversized and misaligned)
- Edit/delete buttons invisible in rules table actions column
- Transaction edit/delete/split buttons not responding when clicking on icon
- Transaction updates not saving (magic method setters not being called)
- Category details panel not updating after renaming a category
- Budget page categories not loading on first visit (missing API token)
- Remaining amount text hard to read in dark mode (improved contrast)
- Progress column header misaligned with values on budget page
- Missing formatAccountType and closeModal methods causing JavaScript errors on shared expenses page
- Settlement form not submitting (event handler not attached)
- Share expense modal not loading contacts when accessed from transactions page
- Split modal buttons (Save Splits, Unsplit, Add Split) not responding to clicks
- Split transactions not displaying split indicator in transaction list (isSplit field missing from API)
- TransactionSplit entity causing PHP 8 typed property initialization error

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

### Fixed
- Database migration error "Primary index name too long" on recurring_income table
- Account form defaulting to USD instead of user's configured default currency
- Data export downloading with `.zip_` extension instead of `.zip`

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

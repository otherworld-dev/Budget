# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.1] - 2026-02-07

### Fixed
- Critical database migration error preventing fresh installations: "Column is type Bool and also NotNull, so it can not store false"
- Fixed 4 boolean columns incorrectly created with NOT NULL constraint in migrations 001000024, 001000026, and 001000027:
  - `budget_import_rules.stop_processing`
  - `budget_bills.auto_pay_enabled`
  - `budget_bills.auto_pay_failed`
  - `budget_bills.is_transfer`
- Added cleanup migration (Version001000028) to fix existing installations that already ran broken migrations
- All boolean columns now use `'notnull' => false` as required by Nextcloud's DBAL for cross-database compatibility
- Updated CLAUDE.md with critical boolean column requirements to prevent future occurrences

## [2.1.0] - 2026-02-07

### Added
- **Bills Calendar Report**: Annual overview showing which months bills are due ([#32](https://github.com/otherworld-dev/budget/issues/32))
  - Annual overview table with bill amounts by month
  - Monthly totals bar chart and color-coded heatmap view
  - Year selector (current year ± 2 years)
  - Filter by bill status (active/inactive/all)
  - Option to include/exclude recurring transfers
  - Toggle between table and heatmap visualization
  - Supports all bill frequencies (daily, weekly, monthly, quarterly, yearly, custom)
- **Recurring Transfers**: Track recurring transfers between accounts ([#36](https://github.com/otherworld-dev/budget/issues/36))
  - Define recurring transfers with auto-pay option
  - Transaction description pattern for import matching
  - Monthly equivalent calculation for different frequencies
  - Integrated with bills system infrastructure
  - Summary cards showing active, due, and completed transfers
  - Filtering tabs (All, Due Soon, Overdue, Completed)
- **Advanced Rules Engine**: Complete redesign of import rules system
  - Visual query builder for complex boolean expressions (AND/OR/NOT operators)
  - Support for nested criteria groups with unlimited depth
  - Multiple action types: category, vendor, notes, tags, account, type, reference
  - Action priority ordering and behavior settings (always, if_empty, append, merge, replace)
  - Preview matches before saving rules to test criteria
  - Run rules immediately on existing transactions
  - Comprehensive unit test coverage (50+ tests for CriteriaEvaluator and RuleActionApplicator)
  - Auto-migration of v1 rules to v2 format when edited
- **Auto-Pay Bills**: Automatic bill payment when due date arrives
  - Auto-pay checkbox in bill form (requires account to be set)
  - Notifications for successful payments and failures
  - Auto-disable on failure to prevent retry loops
  - Status badges on bill cards showing auto-pay state
  - Manual payment resets failed state
- **Future Bill Transactions**: Create future transactions for better cash flow planning
  - Option to create future transaction when adding bills
  - Auto-generate transaction when marking bills as paid
  - Link bills to transactions via bill_id column
  - Enhanced TransactionService with createFromBill() method
- **Transfer Transaction Creation**: Create transfers directly from transaction form
  - Select "Transfer" type to create linked debit/credit transactions
  - Automatic account linking between source and destination
  - Reuses existing transaction matching infrastructure
- **Dynamic Budget Period Switching**: Change budget period with automatic pro-rating
  - Switch between weekly, monthly, quarterly, and yearly periods
  - Automatic budget amount pro-rating between periods (e.g., £12,000 yearly = £1,000 monthly)
  - Spending recalculation for selected period's date range
- **Net Worth Tracking Enhancements**: Improved net worth history display
  - Show when last automatic snapshot was taken (hours/days ago)
  - Status indicator displaying last snapshot information
  - Improved empty state messaging with better user guidance

### Improved
- **Import Rules UI**: Completely redesigned modal interface
  - Modern, space-efficient layout with 1400px width
  - Inline layout for name and priority fields
  - Visual CriteriaBuilder and ActionBuilder components
  - Simplified "Apply Rules" modal that auto-applies all active rules
  - Enhanced checkbox design with card-like styling
  - Better visual hierarchy and improved spacing
- **Currency Symbol Placement**: Correct positioning for suffix currencies ([#34](https://github.com/otherworld-dev/budget/issues/34))
  - Swedish, Norwegian, Danish kronas now display as "500 kr" instead of "kr500"
  - Swiss franc follows ISO 4217 standard positioning
  - Position-aware formatting with CURRENCY_CONFIG metadata
  - Both formatCurrency() and formatCurrencyCompact() updated
- **Dashboard Tiles**: Auto-update when transactions or budgets change
  - Automatic refresh after transaction create/update/delete operations
  - Auto-refresh when budget amounts are modified
  - Fixed race conditions by awaiting loadInitialData before rendering
  - Optimized spending chart layout with detailed breakdown list

### Fixed
- **Timezone Date Calculations**: Resolve month-off-by-one errors ([#27](https://github.com/otherworld-dev/budget/issues/27))
  - Transactions no longer appear in wrong month for users in non-UTC timezones
  - Added timezone-safe date formatting utilities: formatDateForAPI(), getTodayDateString(), getMonthStart(), getMonthEnd()
  - Fixed budget spending queries and dashboard date ranges
  - Ensures all date ranges use user's local timezone consistently
- **Transaction Filters**: Filters now properly apply to transaction table
  - Category, type, amount range, search, and date filters work correctly
  - Filters auto-update on every change for consistent behavior
  - Fixed state management between app and module instances
  - Added missing filter parameters to loadTransactions() API call
- **Account Balance Calculations**: Exclude future-dated transactions
  - Balances reflect actual state as of today
  - Affects dashboard, accounts page, net worth calculations, and forecasts
  - Future bill transactions no longer affect current balance
- **Bill Auto-Pay Validation**: Proper account requirement handling
  - Auto-pay requires account to be set (validated frontend and backend)
  - Auto-pay checkbox disabled without account selection
  - Clear UI feedback for validation requirements
- **Rule Migration System**: Fix v1 to v2 migration issues
  - Properly wrap migrated v1 conditions in groups for CriteriaBuilder
  - Detect and re-migrate broken v2 rules with null criteria
  - Schema version now reliably saved during migration
  - Auto-fix legacy broken structures when rules are opened in UI
  - Detailed console logging for debugging migration decisions
- **Transaction Edit Button**: Fix for old transactions on accounts page
  - Fetch transaction from API when not found in local state
  - Ensures edit functionality works for all historical transactions
- **Routing Issues**: Fix 404/500 errors on specific endpoints
  - Year-over-Year API using correct TransactionMapper method (findAllByUserAndDateRange)
  - Uncategorized transactions endpoint route ordering fixed (specific paths before {id} patterns)
  - Account balance auto-update after adding transactions
- **Category Update Endpoint**: Add missing budgetPeriod parameter support
  - Fixes "No valid fields to update" error when changing budget periods
  - Validation for monthly, weekly, quarterly, and yearly periods
- **Modal Close Behavior**: Rules modals now properly close after save
  - Added rule-modal and apply-rules-modal to hideModals list
  - Prevents modals staying open after successful operations
- **Import Rule Type Casting**: Fix TypeError in category/account ID validation
  - Cast category and account IDs to int in RuleActionApplicator
  - JSON sends IDs as strings but PHP strict typing requires int
  - Resolves 500 error when creating rules with category/account actions
- **Dashboard Data Accuracy**: Improved trend chart and tile calculations
  - Budget Remaining tile property name handling fixed
  - Spending by Category chart handles API array data format correctly
  - Chart layout optimized with detailed breakdown showing percentages
  - Increased chart size from 280px to 320px for better visibility

## [2.0.5] - 2026-02-03

### Added
- **Custom frequency pattern for bills**: Select specific months when irregular bills occur (e.g., bills in January, June, and July only)
  - New "Custom" frequency option in bill creation/editing modal
  - Interactive month selector with modern tile-based UI design
  - Selected months show full primary color background with checkmark indicators
  - Smooth hover animations and responsive grid layout (4/3/2 columns for desktop/tablet/mobile)
  - Automatic next due date calculation based on selected month patterns
  - Handles year wrapping and month-end edge cases (e.g., day 31 in February)
  - Monthly equivalent calculations for budget summaries
  - Pattern stored as JSON: `{"months": [1, 6, 7]}` for flexibility

### Improved
- Enhanced month selector UI with hidden checkboxes and clean tile design
- Better visual feedback for selected months in bill frequency picker

## [2.0.4] - 2026-02-03

### Fixed
- Re-release with corrected build configuration excluding development files from distribution package

## [2.0.3] - 2026-02-03

### Fixed
- Missing `deleteByTag()` method in TransactionTagMapper causing HTTP 500 errors when deleting categories with tag sets
- Categories with subcategories can now be deleted recursively - cascade delete now removes all child categories and their tag sets automatically

## [2.0.2] - 2026-02-03

### Fixed
- Foreign key constraint incorrectly formed error during migration 001000022
- Removed all database foreign key constraints for better cross-database compatibility
- Implemented application-level cascade deletes:
  - Deleting a tag now removes associated transaction_tags
  - Deleting a tag set now removes all tags and their transaction_tags
  - Deleting a category now removes all tag sets, tags, and transaction_tags
  - Deleting a transaction now removes associated transaction_tags
- Matches pattern used throughout rest of application (no other migrations use foreign keys)

## [2.0.1] - 2026-02-03

### Fixed
- Primary key index name too long error during migration 001000022
- Shorten primary key names for tag_sets, tags, and transaction_tags tables to prevent MySQL 64-character limit error
- Critical fix for fresh installations that were failing during database setup

## [2.0.0] - 2026-02-02

### Added
- **Tag Sets feature for multi-dimensional transaction categorization (GitHub issue #13)**
  - Categories can have multiple tag sets (e.g., "Hobbies" → "Activity" tag set + "Equipment" tag set)
  - Each tag set contains multiple tags with customizable colors
  - Tags can be assigned to transactions for detailed organization and filtering
  - Tag management UI in category details page with compact design
  - Color-coded tag chips with visual distinction
  - Modal interface for adding tags with HTML5 color picker
  - Transaction filtering by tags (supports multiple tags with OR logic within tag sets, AND logic across tag sets)
  - Tag filter dropdown on reports page for filtering transactions by tags
  - Database tables: `budget_tag_sets`, `budget_tags`, `budget_transaction_tags`
  - RESTful API endpoints for tag set and tag CRUD operations
  - Auto-select first category when category page loads for better UX
  - Theme-aware color scheme using Nextcloud CSS variables
- **CSV import enhancements for better international bank support (GitHub issue #15)**
  - Auto-detection of CSV delimiters (comma, semicolon, tab)
  - User-selectable delimiter override when auto-detection fails
  - Dual-column amount mapping for files with separate income/expense columns
  - Support for files with "Deposits" and "Withdrawals" columns (common in European banks)
  - Intelligent amount parsing handles European number formats (1.234,56)
  - Auto-detection of income/expense column patterns (deposits, withdrawals, credits, debits)
  - Smart validation ensures either single amount OR dual columns selected, not both
  - CSV options panel (delimiter selector) shown only for CSV files
  - Delimiter flows through entire import pipeline (upload → preview → process)
  - Backend delimiter parameter support in ParserFactory, ImportService, and ImportController
  - TransactionNormalizer handles both single and dual-column amount approaches
  - Frontend validation prevents invalid mapping combinations
- Undo functionality on bills page to revert deletions
- Undo functionality on income page to revert deletions
- DKK (Danish Krone) currency support
- Semi-annually frequency option for recurring bills
- Expanded currency selection to 20 currencies with centralized Currency enum

### Changed
- **Major refactoring: Modularized frontend architecture (63% code reduction in main.js)**
  - Split monolithic main.js (~18,000 lines) into 14 feature-based modules in `src/modules/`
  - Created dedicated modules: AccountsModule, AuthModule, BillsModule, CategoriesModule, DashboardModule, ForecastModule, ImportModule, IncomeModule, PensionsModule, ReportsModule, RulesModule, SavingsModule, SharedExpensesModule, TagSetsModule, TransactionsModule
  - Extracted Router into separate `src/core/Router.js` class
  - Created shared utilities in `src/utils/`: api.js, dom.js, formatters.js, helpers.js, validators.js
  - Centralized dashboard widget configuration in `src/config/dashboardWidgets.js`
  - Improved maintainability, testability, and developer experience
  - No user-facing changes - purely internal architecture improvement

### Fixed
- Duplicate event listeners in forecast module causing memory leaks
- Bill editing persistence issues - edits now save correctly
- Pension modal bugs and added disclaimer notice for retirement projections
- Savings goals creation failing to save properly
- Savings goals completed count displaying incorrectly
- Bills page hero cards showing "no data" despite active bills
- Multiple UI bugs in tag sets modal and transaction modal
- Transaction table rendering issues after modularization
- Inline transaction editing not saving changes correctly
- Various functionality issues restored after modularization refactor

## [1.2.3] - 2026-01-24

### Fixed
- Remove vendor/tecnickcom/tcpdf/tools/.htaccess that was causing integrity check failures
- File was being blocked/removed by server security policies during installation
- Directory security is already handled by Nextcloud's web server configuration

## [1.2.2] - 2026-01-24

### Fixed
- Include hidden files (.htaccess) in package signature
- Fixes FILE_MISSING error for vendor/tecnickcom/tcpdf/tools/.htaccess

## [1.2.1] - 2026-01-24

### Fixed
- App package now includes all required files (lib/ and vendor/ directories) in code signature
- Fixes integrity check errors when installing from app store

## [1.2.0] - 2026-01-23

### Added
- Password protection feature for enhanced app security
  - Optional password required to access the budget app (secondary protection layer)
  - User-configurable password (minimum 6 characters) set via Settings > Security
  - Session management with configurable timeout (15/30/60 minutes of inactivity)
  - Auto-lock after inactivity period with activity monitoring on user interactions
  - Manual lock button in navigation when password protection is enabled
  - Failed attempt tracking: 5 failed attempts triggers 5-minute account lockout
  - Session tokens (64-character random tokens) stored securely in localStorage
  - Password hashing using bcrypt via PHP's `password_hash()` with `PASSWORD_DEFAULT`
  - Change password and disable protection options (requires current password verification)
  - Rate limiting on auth endpoints (5-10 requests per minute depending on endpoint)
  - Modal UI for password entry with error handling and validation
  - New database table `budget_auth` for password and session management
  - RESTful API endpoints: `/api/auth/status`, `/api/auth/setup`, `/api/auth/verify`, `/api/auth/lock`, `/api/auth/extend`, `/api/auth/disable`, `/api/auth/password`
- Factory reset feature to restore app to empty state
  - Deletes ALL user data (accounts, transactions, bills, categories, settings, pension data, shared expenses, etc.)
  - Preserves audit logs for compliance purposes
  - Danger Zone section in settings page with prominent warnings
  - Requires typing "DELETE" (case-sensitive) to confirm
  - Password confirmation required via Nextcloud's built-in security
  - Rate limited to 3 attempts per 5 minutes to prevent abuse
  - Database transaction ensures all-or-nothing deletion (rollback on error)
  - Gracefully handles missing database tables for features not yet used
  - Audit trail logged with counts of deleted items per entity type

### Fixed
- Dashboard crashing with "Cannot read properties of undefined (reading 'filter')" error
- `updateBudgetProgressWidget()` now validates categories parameter is an array before filtering
- Budget API response handling now properly handles null responses with fallback to empty categories array
- Password protection setup failing with "Entity which should be updated has no id" error
- Auth entity `id` property access level changed from protected to public (required by Nextcloud Entity framework)
- Database migration added to recreate `budget_auth` table with auto-increment `id` as primary key
- `user_id` changed from primary key to unique index for proper ORM compatibility
- CSV import failing with "Date is required" error on all rows
- Column mapping dropdowns sending array indices (0, 1, 2) instead of column names ("Date", "Amount", "Description") to backend
- Auto-detection of CSV columns not working after upload
- TransactionNormalizer now skips non-column mapping fields (boolean config flags) to prevent lookup errors
- PDF report exports appearing corrupted (TCPDF library not installed)
- ReportExporter falling back to JSON export when PDF format requested
- Application.php now loads composer autoloader to ensure TCPDF and other dependencies are available

## [1.1.0] - 2026-01-21

### Added
- Configurable dashboard layout with drag-and-drop tile reordering (GitHub issue #9)
- Lock/Unlock Dashboard toggle to enable/disable tile reordering
- Remove tiles by clicking X button (appears on hover when unlocked)
- Add hidden tiles back via "Add Tiles" dropdown menu
- Visual feedback: grab cursor, hover lift effect, drop indicators, and fade-in animations
- Dashboard customization works on desktop; touch devices show lock toggle only
- All dashboard layout changes persist automatically to backend
- Configurable transaction table columns - show/hide Date, Description, Vendor, Category, Amount, and Account columns
- Gear icon in transaction table header to access column visibility settings
- Column visibility preferences persist across sessions via settings API
- Vendor column added to transaction table with inline editing support
- 10 new dashboard tiles (Phase 1 - hidden by default, zero performance impact):
  - **Hero Tiles**: Savings Rate, Cash Flow, Budget Remaining, Budget Health
  - **Widget Tiles**: Top Spending Categories, Account Performance, Budget Breakdown, Savings Goals Summary, Payment Methods, Reconciliation Status
- All new tiles use existing data (no additional API calls required)
- New tiles available via "Add Tiles" dropdown for user opt-in
- 8 additional dashboard tiles with lazy loading (Phase 2 - fully implemented):
  - **Hero Tiles**: Uncategorized Count (shows count of uncategorized transactions), Low Balance Alert (alerts when accounts below threshold)
  - **Widget Tiles**: Monthly Comparison (current vs previous month table), Large Transactions (top 10 by amount), Weekly Spending, Unmatched Transfers, Category Trends, Bills Due Soon
- Lazy loading system: Phase 2+ tiles only fetch data when made visible by user
- Modified applyDashboardVisibility() to support async lazy loading
- All Phase 2 tiles hidden by default, minimal performance impact (load on-demand only)
- 8 advanced dashboard tiles with charts and complex calculations (Phase 3 - fully implemented):
  - **Hero Tiles**: Burn Rate (shows days until balance hits zero at current spend rate), Days Until Debt Free (estimated payoff timeline using avalanche strategy)
  - **Widget Tiles**: Cash Flow Forecast (90-day projected balance chart), Year-over-Year Comparison (annual spending comparison), Income Tracking (expected vs received income with progress bars), Recent Imports (last 3 file imports), Rule Effectiveness (auto-categorization statistics), Spending Velocity (current week vs average)
- Chart.js integration for Cash Flow Forecast and Year-over-Year Comparison widgets
- Chart instance management with proper cleanup when tiles are hidden
- All Phase 3 tiles hidden by default with lazy loading for optimal performance
- Quick Add Transaction widget for fast transaction entry directly from dashboard (Phase 4 - fully implemented):
  - Inline form with essential fields: Date, Account, Type, Amount, Description, and optional Category
  - Real-time validation with helpful error messages displayed inline
  - Automatic dropdown population for accounts and categories
  - Submit button to add transaction via `/api/transactions` POST endpoint
  - Clear button to reset form to default state
  - Success/error messages with auto-hide for success (3 seconds)
  - Auto-refresh of transactions and dashboard after successful add
  - Today's date auto-populated as default
  - Compact single-column layout optimized for dashboard widget display
- All 28 new dashboard tiles (8 hero + 20 widget) now complete and available via "Add Tiles" dropdown
- Completed 4-phase rollout: Phase 1 (10 tiles, existing data), Phase 2 (8 tiles, lazy loaded), Phase 3 (8 tiles, charts), Phase 4 (1 interactive tile)
- "Add Tiles" dropdown now organized by categories to reduce overwhelm:
  - Categories: Insights & Analytics, Budgeting, Forecasting, Transactions, Income, Debts, Goals, Bills, Alerts, Interactive
  - Each category shows as a collapsible section with header
  - Hero tiles display "Hero" badge to distinguish from regular widget tiles
  - Categories only appear if they contain hidden tiles

### Changed
- Removed redundant category dropdown and categorize button from bulk actions panel (use Edit Fields modal instead)
- Improved visibility of column configuration gear icon with grey background and white icon color

### Fixed
- Bulk edit modal appearing in top-left corner instead of centered on screen
- Category dropdown in inline edit was too narrow and cutting off category names
- Dashboard tile order not persisting after page refresh

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

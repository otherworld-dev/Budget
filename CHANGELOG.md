# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Bills Calendar Report**: Annual overview showing which months bills are due ([#32](https://github.com/otherworld-dev/budget/issues/32))
  - Annual overview table with bill amounts by month
  - Monthly totals chart and heatmap view
  - Year selector and filtering options
  - Support for all bill frequencies
- **Recurring Transfers**: Track recurring transfers between accounts ([#36](https://github.com/otherworld-dev/budget/issues/36))
  - Define recurring transfers with auto-pay option
  - Transaction description pattern for import matching
  - Monthly equivalent calculation for different frequencies
  - Integrated with bills system infrastructure
- **Advanced Rules Engine**: Complete redesign of import rules system
  - Visual query builder for complex boolean expressions (AND/OR/NOT operators)
  - Support for nested criteria groups with unlimited depth
  - Multiple action types (category, vendor, notes, tags, account, type, reference)
  - Action priority ordering and behavior settings (always, if_empty, append, merge)
  - Preview matches before saving rules
  - Run rules immediately on existing transactions
  - Comprehensive unit test coverage (50+ tests)
- **Auto-Pay Bills**: Automatic bill payment when due date arrives
  - Auto-pay checkbox in bill form (requires account)
  - Notifications for success and failure
  - Auto-disable on failure to prevent retry loops
  - Status badges on bill cards
- **Future Bill Transactions**: Create future transactions for better cash flow planning
  - Option to create future transaction when adding bills
  - Auto-generate transaction when marking bills as paid
  - Link bills to transactions via bill_id column
- **Transfer Transaction Creation**: Create transfers directly from transaction form
  - Select "Transfer" type to create linked debit/credit transactions
  - Automatic account linking
  - Reuses existing transaction matching infrastructure
- **Dynamic Budget Period Switching**: Change budget period with automatic pro-rating
  - Switch between weekly, monthly, quarterly, and yearly periods
  - Automatic budget amount pro-rating between periods
  - Spending recalculation for selected period
- **Net Worth Tracking UI**: Enhanced net worth history display
  - Show when last automatic snapshot was taken
  - Improved empty state messaging
  - Better status indicators

### Changed
- **Import Rules UI**: Completely redesigned modal interface
  - Modern, space-efficient layout (1400px width)
  - Inline layout for name and priority fields
  - Visual CriteriaBuilder and ActionBuilder components
  - Simplified "Apply Rules" modal (auto-applies all active rules)
  - Enhanced checkbox design with card-like styling
- **Currency Symbol Placement**: Correct positioning for suffix currencies
  - Swedish, Norwegian, Danish kronas now display as "500 kr" instead of "kr500"
  - Swiss franc follows ISO 4217 standard
  - Position-aware formatting for all currencies
- **Dashboard Tiles**: Auto-update when transactions or budgets change
  - Automatic refresh after transaction operations
  - Fix race conditions in data loading
  - Optimized spending chart layout with detailed breakdown

### Fixed
- **Timezone Date Calculations**: Resolve month-off-by-one errors ([#27](https://github.com/otherworld-dev/budget/issues/27))
  - Transactions no longer appear in wrong month for users in non-UTC timezones
  - Added timezone-safe date formatting utilities
  - Fixed budget spending queries and dashboard date ranges
- **Transaction Filters**: Filters now properly apply to transaction table
  - Category, type, amount range, search, and date filters work correctly
  - Filters auto-update on every change
  - Fixed state management between app and module instances
- **Account Balance Calculations**: Exclude future-dated transactions
  - Balances reflect actual state as of today
  - Affects dashboard, accounts page, net worth, and forecasts
- **Bill Auto-Pay Account Validation**: Proper account requirement handling
  - Auto-pay requires account to be set
  - Frontend and backend validation
  - Auto-pay checkbox disabled without account selection
- **Rule Migration System**: Fix v1 to v2 migration issues
  - Properly wrap migrated conditions in groups
  - Detect and re-migrate broken v2 rules
  - Schema version now reliably saved during migration
  - Auto-fix legacy broken structures in UI
- **Transaction Edit Button**: Fix for old transactions on accounts page
  - Fetch transaction from API when not found in local state
- **Routing Issues**: Fix 404/500 errors on specific endpoints
  - Year-over-Year API using correct mapper method
  - Uncategorized transactions endpoint route ordering fixed
- **Category Update Endpoint**: Add missing budgetPeriod parameter support
- **Modal Close Behavior**: Rules modals now properly close after save
- **Import Rule Type Casting**: Fix TypeError in category/account ID validation
- **Dashboard Trend Chart**: Improved data accuracy

### Development
- Remove debug console.log statements from transfers module
- Add sample data files to .gitignore
- Remove development files from repository
- Update dashboard screenshot
- Comprehensive test coverage for advanced rules engine
- Documentation for advanced rules implementation

## [2.0.5] - 2026-02-03

### Previous Releases
For changes prior to 2.0.5, see git commit history.

[Unreleased]: https://github.com/otherworld-dev/budget/compare/v2.0.5...HEAD
[2.0.5]: https://github.com/otherworld-dev/budget/releases/tag/v2.0.5

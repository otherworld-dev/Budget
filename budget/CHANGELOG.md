# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

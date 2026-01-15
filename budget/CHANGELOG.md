# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

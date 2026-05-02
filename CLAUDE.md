# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Nextcloud Budget** is a comprehensive financial management app for Nextcloud. It tracks spending, manages multiple accounts, forecasts balances, and provides budgeting tools. The app follows Nextcloud's standard app architecture with a PHP backend and JavaScript frontend.

**Tech Stack:**
- Backend: PHP 8.1+, Nextcloud App Framework
- Frontend: JavaScript (ES6+), Chart.js for visualizations
- Build: Webpack with Babel, npm scripts
- Database: MySQL/MariaDB, PostgreSQL, or SQLite
- Dependencies: Composer (PHP), npm (JavaScript), TCPDF (PDF generation)

## Common Development Commands

### Frontend Development
```bash
# Development build with source maps
npm run dev

# Watch mode - auto-rebuild on changes
npm run watch

# Production build (minified, optimized)
npm run build

# Lint JavaScript
npm run lint

# Auto-fix linting issues
npm run lint:fix
```

### Backend Development
```bash
# Install PHP dependencies (production)
composer install --no-dev --optimize-autoloader

# Install all dependencies including dev tools
composer install

# Lint PHP files
composer run lint

# Run PHPUnit tests
composer run test:unit

# Run Psalm static analysis
composer run psalm
```

### Makefile Commands
The Makefile provides convenient shortcuts:
```bash
# Development
make dev          # Build for development
make watch        # Watch and rebuild on changes
make composer-dev # Install all composer dependencies

# Testing & Quality
make lint         # Run all linters (PHP + JS)
make lint-fix     # Auto-fix linting issues
make test         # Run PHPUnit tests
make psalm        # Run static analysis

# Building
make build        # Build production package
make appstore     # Create signed app store tarball
make clean        # Remove build artifacts

# Nextcloud Integration (run from budget/ directory in Nextcloud apps/)
make enable       # Enable app: php ../../occ app:enable budget
make disable      # Disable app: php ../../occ app:disable budget
make migrate      # Run database migrations: php ../../occ migrations:execute budget
```

### Running Tests
```bash
# Run all unit tests
make test
# OR
cd budget && ../vendor/bin/phpunit -c tests/phpunit.xml

# Run specific test file
../vendor/bin/phpunit tests/Unit/Service/TagSetServiceTest.php

# Run specific test method
../vendor/bin/phpunit --filter testCreateTagSet tests/Unit/Service/TagSetServiceTest.php
```

### Creating Signed Builds for Nextcloud App Store

The Nextcloud app store requires **two separate signatures**:
1. **Internal signature.json** - Created by `php occ integrity:sign-app`, included in tarball for post-installation integrity verification
2. **Tarball signature** - Created by `openssl dgst`, provided to app store during submission for download validation

**Prerequisites:**
- Official Nextcloud code signing certificate and private key in `~/.nextcloud/certificates/`
- Docker container with Nextcloud instance running (for `occ` command)
- Certificates: `budget.key` (private key) and `budget.crt` (Nextcloud-signed certificate)
- Container ID: `docker ps | grep nextcloud` to find your container

**⚠️ IMPORTANT: Build from Clean Git Tag**
Never build from your working directory. Always clone fresh from the git tag to ensure a clean, reproducible build without modified files or development dependencies.

**Complete Build Process:**

```bash
# 0. Ensure version is committed and tagged
cd budget
# Update version in appinfo/info.xml
# Update CHANGELOG.md
git add appinfo/info.xml CHANGELOG.md
git commit -m "chore: Bump version to X.Y.Z"
git tag vX.Y.Z
git push && git push --tags

# 1. Clone fresh from git tag IN CONTAINER
docker exec <container_id> bash -c 'cd /tmp && rm -rf budget-vX.Y.Z && git clone --depth 1 --branch vX.Y.Z https://github.com/otherworld-dev/budget.git budget-vX.Y.Z'

# 2. Install production PHP dependencies IN CONTAINER
docker exec <container_id> bash -c 'cd /tmp/budget-vX.Y.Z/budget && composer install --no-dev --optimize-autoloader --no-interaction'

# 3. Build JavaScript ON HOST (npm not in container)
cd budget
git checkout vX.Y.Z  # Checkout the tag on host
npm run build        # Builds to js/ and css/ directories

# 4. Create clean build directory with rsync exclusions (matching Makefile)
docker exec <container_id> bash -c 'cd /tmp/budget-vX.Y.Z/budget && rm -rf /tmp/budget-build && rsync -a \
  --exclude=".git" \
  --exclude="build" \
  --exclude="tests" \
  --exclude="node_modules" \
  --exclude="src" \
  --exclude="webpack.config.js" \
  --exclude="package.json" \
  --exclude="package-lock.json" \
  --exclude="composer.json" \
  --exclude="composer.lock" \
  --exclude="Makefile" \
  --exclude=".gitignore" \
  --exclude=".eslintrc.js" \
  --exclude="psalm.xml" \
  --exclude="*.log" \
  ./ /tmp/budget-build/'

# 5. Copy built JS and CSS from host to container
docker cp budget/js <container_id>:/tmp/budget-build/
docker cp budget/css <container_id>:/tmp/budget-build/

# 6. CRITICAL: Remove problematic .htaccess file
# This file causes integrity check failures (removed in v1.2.3)
docker exec <container_id> bash -c 'rm -f /tmp/budget-build/vendor/tecnickcom/tcpdf/tools/.htaccess'

# 7. Verify no development files in build
docker exec <container_id> bash -c 'find /tmp/budget-build -name "package.json" -o -name "composer.json" -o -name "Makefile" -o -name "webpack.config.js" | wc -l'
# Should output: 0

# 8. Copy signing certificates to container (if not already there)
docker cp ~/.nextcloud/certificates/budget.key <container_id>:/tmp/budget.key
docker cp ~/.nextcloud/certificates/budget.crt <container_id>:/tmp/budget.crt

# 9. Sign app to create internal signature.json
docker exec <container_id> bash -c 'chmod -R 777 /tmp/budget-build && php occ integrity:sign-app --privateKey=/tmp/budget.key --certificate=/tmp/budget.crt --path=/tmp/budget-build'

# 10. Create tarball (rename build dir to 'budget' first)
docker exec <container_id> bash -c 'cd /tmp && rm -rf budget && mv budget-build budget && tar -czf budget-vX.Y.Z.tar.gz budget'

# 11. Generate app store signature (CRITICAL - this is what the app store validates)
docker exec <container_id> bash -c 'openssl dgst -sha512 -sign /tmp/budget.key /tmp/budget-vX.Y.Z.tar.gz | openssl base64 -A'
# Save this signature output for app store submission

# 12. Copy tarball to host and rename to budget.tar.gz
docker cp <container_id>:/tmp/budget-vX.Y.Z.tar.gz ./budget.tar.gz

# 13. Create GitHub release and upload tarball
gh release create vX.Y.Z --repo otherworld-dev/budget --title "vX.Y.Z" --notes "Release notes here"
gh release upload vX.Y.Z budget.tar.gz --repo otherworld-dev/budget

# 14. Return to master branch on host
git checkout master
```

**App Store Submission:**
1. Go to https://apps.nextcloud.com/developer/apps/releases/new (or navigate via https://apps.nextcloud.com/developer/apps → Find "Budget" app → Releases → New Release)
2. **Download URL**: `https://github.com/otherworld-dev/budget/releases/download/vX.Y.Z/budget.tar.gz`
3. **Signature**: Paste the base64 string from step 11 (openssl command output)
4. Submit

**Critical Success Factors:**
1. ✅ **Build from git tag, not working directory** - Ensures clean, reproducible builds
2. ✅ **Use rsync exclusions matching Makefile** - Excludes all development files
3. ✅ **Remove .htaccess file** - Prevents integrity check failures
4. ✅ **Sign BEFORE creating tarball** - signature.json must be inside the tarball
5. ✅ **Use openssl signature for app store** - NOT the internal signature.json content

**Common Issues:**
- **"Signature is invalid" error**: Using wrong signature - must use openssl dgst output (step 11), not signature.json
- **"Invalid signature" after reinstall**: App store requires tarball named `budget.tar.gz` (not versioned name)
- **Integrity check failures**: .htaccess file still present, or dev files included (package.json, etc.)
- **Development files in tarball**: Built from working directory instead of clean git tag
- **Certificate errors**: Certificates not from Nextcloud Code Signing Intermediate Authority (serial 4835)

**Verification Commands:**
```bash
# Verify no development files in tarball
tar -tzf budget.tar.gz | grep -E "(package\.json|composer\.json|Makefile|webpack\.config|tests/)" | wc -l
# Should output: 0

# Verify .htaccess is NOT in tarball
tar -tzf budget.tar.gz | grep "\.htaccess" | wc -l
# Should output: 0

# Verify signature.json exists
tar -tzf budget.tar.gz budget/appinfo/signature.json
# Should find the file

# Verify no dev dependencies
tar -tzf budget.tar.gz | grep -E "vendor/(myclabs|phpunit|psalm|nextcloud/ocp)" | wc -l
# Should output: 0

# Check tarball size (should be ~16 MB for v2.0.4)
ls -lh budget.tar.gz
```

## Architecture

### Backend Structure (budget/lib/)

**Three-Layer Architecture:**
1. **Controller Layer** (`lib/Controller/`) - HTTP request handling, API endpoints
2. **Service Layer** (`lib/Service/`) - Business logic, orchestration
3. **Data Layer** (`lib/Db/`) - Database access via Mappers (Repository pattern)

**Key Directories:**
- `lib/Controller/` - API controllers (AccountController, TransactionController, etc.)
- `lib/Service/` - Business logic services
  - `Service/Import/` - Bank statement import subsystem (CSV, OFX, QIF parsers)
  - `Service/Forecast/` - Balance forecasting and trend analysis
  - `Service/Report/` - Report generation and aggregation
  - `Service/Bill/` - Recurring bill detection and tracking
- `lib/Db/` - Entity models and Mappers
  - Entities extend `Entity` from Nextcloud
  - Mappers extend `QBMapper` and handle all database queries
- `lib/Enum/` - Type-safe enumerations
- `lib/Migration/` - Database schema migrations (versioned)
- `lib/AppInfo/Application.php` - Dependency injection container registration
- `lib/BackgroundJob/` - Cron jobs (cleanup, notifications)

**Dependency Injection:**
All services and mappers are registered in `lib/AppInfo/Application.php::register()`. When adding new services:
1. Create the service class
2. Register it in `Application.php` with dependencies
3. Use `$context->registerServiceAlias()` for easier DI references

**Database Access Pattern:**
- Each entity has a corresponding Mapper (e.g., `Account` + `AccountMapper`)
- Mappers use Nextcloud's `QBMapper` with query builders (not raw SQL)
- Use `QueryFilterBuilder` for complex transaction filtering
- All queries are user-scoped (use `userId` in WHERE clauses)

### Frontend Structure (budget/src/)

**Modular Architecture (Refactored in v1.2+):**
The frontend uses a feature-based modular architecture with ES6 imports:

**Entry Point:**
- **main.js** (~3,300 lines) - Main BudgetApp class that:
  - Initializes all feature modules
  - Manages global application state (accounts, categories, transactions, settings)
  - Coordinates Router and module communication
  - Handles session management and authentication state

**Feature Modules** (`src/modules/`):
Each module is self-contained with its own UI, logic, and API interactions:
- **accounts/** - AccountsModule: Account management and reconciliation
- **auth/** - AuthModule: Password protection and session management
- **bills/** - BillsModule: Recurring bill detection and tracking
- **categories/** - CategoriesModule: Category hierarchy and management
- **dashboard/** - DashboardModule: Dashboard widgets and layout
- **forecast/** - ForecastModule: Balance forecasting and trend analysis
- **import/** - ImportModule: CSV/OFX/QIF import system
- **income/** - IncomeModule: Recurring income tracking
- **pensions/** - PensionsModule: Pension account tracking and forecasts
- **reports/** - ReportsModule: Financial reports and visualizations
- **rules/** - RulesModule: Auto-categorization import rules
- **savings/** - SavingsModule: Savings goals management
- **settings/** - SettingsModule: App settings and preferences
- **shared-expenses/** - SharedExpensesModule: Shared expense tracking with contacts
- **tagsets/** - TagSetsModule: Tag sets for categories
- **transactions/** - TransactionsModule: Transaction CRUD and filtering (largest module ~67KB)

**Core Infrastructure** (`src/core/`):
- **Router.js** - Hash-based client-side routing, navigation handling

**Shared Utilities** (`src/utils/`):
- **api.js** - ApiClient wrapper around @nextcloud/axios
- **dom.js** - DOM manipulation helpers
- **formatters.js** - Currency, date, and number formatting
- **helpers.js** - General utility functions
- **validators.js** - Form validation utilities

**Configuration** (`src/config/`):
- **dashboardWidgets.js** - Dashboard widget definitions and settings (28+ widgets)

**Build Output:**
- Webpack bundles to `js/budget-main.js` and `css/budget-main.css`
- Source maps in development mode (`npm run dev`)
- Production builds minified and optimized

**Key Architecture Patterns:**
- **Module Pattern:** Each module is a class instantiated by BudgetApp with a reference to the parent app
- **Event-Driven:** Modules communicate via DOM custom events (e.g., `transaction:created`, `account:updated`)
- **Centralized State:** BudgetApp maintains shared state (accounts, categories, etc.) accessible by all modules
- **Router Integration:** Router triggers module-specific render methods based on URL hash
- **API Integration:** Modules use shared ApiClient for consistent @nextcloud/axios usage
- **Widget Registry:** Dashboard tiles defined in DASHBOARD_WIDGETS config (hero tiles, chart widgets, summary cards)

**Making Frontend Changes:**
1. Identify the relevant module in `src/modules/[feature]/`
2. Edit the module file (most are single-file modules, largest is TransactionsModule.js at ~67KB)
3. For shared functionality, edit utilities in `src/utils/` or add to BudgetApp in `src/main.js`
4. Rebuild using `npm run dev` or `npm run watch` for automatic rebuilds
5. Test changes by refreshing the browser after rebuild
6. For cross-module communication, use custom DOM events (follow existing patterns)

### Database Schema

**Core Tables:**
- `budget_accounts` - Bank accounts, credit cards, cash, investment accounts. Includes `last_reconciled` timestamp.
- `budget_transactions` - Individual transactions with categorization, reconciliation status, and transfer linking (`linked_transaction_id`)
- `budget_tx_splits` - Split transactions across categories
- `budget_categories` - Hierarchical category tree. Supports `excluded_from_reports` flag to hide from budgets/reports.
- `budget_tag_sets` / `budget_tags` / `budget_transaction_tags` - Tag sets for multi-dimensional category tracking
- `budget_import_rules` - Auto-categorization rules for imports
- `budget_bills` - Recurring bill and transfer tracking with auto-pay
- `budget_recurring_income` - Expected income sources
- `budget_savings_goals` - Savings targets
- `budget_settings` - Per-user key-value settings
- `budget_auth` - Password protection (bcrypt hashed, session tokens)
- `budget_audit_log` - Audit trail for financial actions
- `budget_contacts` / `budget_expense_shares` / `budget_settlements` - Shared expense tracking with multi-currency support
- `budget_assets` / `budget_asset_snaps` - Non-liquid asset tracking with value snapshots
- `budget_pensions` / `budget_pen_snaps` / `budget_pen_contribs` - Pension tracking and projections
- `budget_exchange_rates` / `budget_manual_rates` - Currency exchange rates (ECB/CoinGecko + manual overrides)
- `budget_nw_snaps` - Net worth history snapshots
- `budget_shares` / `budget_share_items` - Data sharing between Nextcloud users
- `budget_interest_rates` - Account interest rate history
- `budget_bc` / `budget_bam` - Bank sync connections and account mappings
- `budget_bgt_snapshots` - Per-month budget overrides

**Migration System:**
- Migrations in `lib/Migration/Version*.php`
- Versioned naming: `Version{padded_version}Date{YYYYMMDD}`
- Applied automatically on app enable/upgrade
- Manual execution: `php occ migrations:execute budget`

**CRITICAL: Boolean Column Requirements**
⚠️ **ALWAYS make boolean columns nullable for cross-database compatibility**

Nextcloud's DBAL requires all boolean columns to have `'notnull' => false` for compatibility across MySQL, PostgreSQL, and SQLite. This is a strict requirement that will cause installation failures if violated.

**Correct:**
```php
$table->addColumn('is_active', Types::BOOLEAN, [
    'notnull' => false,  // REQUIRED for boolean columns
    'default' => false,
]);
```

**WRONG (will cause installation errors):**
```php
$table->addColumn('is_active', Types::BOOLEAN, [
    'notnull' => true,   // ❌ NEVER use notnull => true on boolean columns
    'default' => false,
]);
```

**Why this matters:**
- PostgreSQL and some database configurations interpret boolean `NOT NULL` constraints differently
- Nextcloud's DBAL abstraction layer cannot reliably handle non-nullable booleans across all databases
- Users will see errors like: `Column "table"."column" is type Bool and also NotNull, so it can not store "false"`
- This has caused multiple release failures (v2.1.0, v1.0.18-1.0.27)

**If you create a boolean column with notnull => true:**
1. Fix the migration immediately by changing `'notnull' => true` to `'notnull' => false`
2. Create a cleanup migration that drops and recreates the column (see Version001000028 as example)
3. Test on fresh install AND upgrade scenarios
4. Check ALL recent migrations for this issue before releasing

### Transfer Handling

Transfers between accounts create linked transaction pairs (`linked_transaction_id`). Key rules:
- **Credit side gets no category** — only the debit (withdrawal) carries `categoryId` for spending aggregation. The `linkTransactions()` service method enforces this.
- **Dashboard transfer exclusion** — transfers between non-liability accounts (e.g., checking↔savings) are excluded from income/expense totals. Transfers involving liability accounts (debt payments) count as real expenses.
- **Auto-matching after import** — the import flow runs `bulkFindAndMatch()` after import to auto-link transfer pairs across accounts.

### Excluded Categories

Categories can be flagged with `excluded_from_reports = true`. These are filtered out of:
- Budget analysis (`CategoryService::getBudgetAnalysis`)
- Budget alerts (`BudgetAlertService`)
- Spending reports (`ReportAggregator::getBudgetReport`, `generateSummary`)

Useful for investment adjustments, internal bookkeeping, and reimbursement categories.

### Routing

API routes defined in `appinfo/routes.php`:
- RESTful conventions: GET (read), POST (create), PUT (update), DELETE (destroy)
- All API routes prefixed with `/api/`
- Route naming: `{controller}#{action}` maps to `{Controller}Controller::{action}()`

**Example:** `['name' => 'account#show', 'url' => '/api/accounts/{id}', 'verb' => 'GET']`
→ Maps to `AccountController::show($id)`

## Important Patterns & Conventions

### Translations (i18n)

This app is fully translatable. **All user-facing strings must be wrapped in translation functions** — never use raw string literals for UI text.

- **PHP:** `$this->l->t('Your string')` (inject `IL10N` via constructor)
- **JavaScript:** `t('budget', 'Your string')` (import from `@nextcloud/l10n`)
- Use positioned placeholders (`%1$s`, `%2$s`) in PHP, named placeholders (`{name}`) in JS
- Never concatenate translated strings — use placeholders instead
- See the "Translation & i18n" section below for full details

### Error Handling

Use Nextcloud's JSON response helpers in controllers:
```php
use OCP\AppFramework\Http\JSONResponse;

// Success
return new JSONResponse(['data' => $result]);

// Error
return new JSONResponse(['error' => 'Message'], 400);
```

Services should throw exceptions that controllers catch and convert to appropriate HTTP responses.

### Validation

The `ValidationService` provides common validation utilities. For database constraints, use validation before attempting saves to provide better error messages.

### User Context

All operations are user-scoped. Controllers receive `userId` from the framework:
```php
public function index(): JSONResponse {
    $userId = $this->userId;  // Available in controller
    // ...
}
```

Services and Mappers receive `userId` as parameters.

### Tag Sets Feature

**Recently Added (v1.2):** Categories can now have tag sets for additional classification dimensions.
- `TagSet` entity + `TagSetMapper` (registered in Application.php lines 309-312)
- `TagSetService` handles business logic (lines 324-331)
- `TagSetController` provides API endpoints
- `TransactionTagService` for associating tags with transactions (lines 333-341)
- Routes: `/api/tag-sets`, `/api/categories/{id}/tag-sets`
- Database tables: `budget_tag_sets`, `budget_tags`, `budget_transaction_tags`

### Import System

Multi-stage import process:
1. **Upload** - Store file temporarily
2. **Preview** - Parse and show sample data
3. **Execute** - Import with duplicate detection
4. **Rollback** - Undo import if needed

Supports CSV (custom mapping), OFX, and QIF formats. Uses `Service/Import/` subsystem.

**CSV Import Enhancements (Unreleased):**
- Auto-detection of delimiters (comma, semicolon, tab)
- Dual-column amount mapping for separate income/expense columns
- European number format support (1.234,56)
- Smart validation (single amount XOR dual columns)
- `ParserFactory` and `TransactionNormalizer` handle format variations

### Testing

- Unit tests in `tests/Unit/`
- PHPUnit 10 configuration in `tests/phpunit.xml`
- Test structure mirrors lib/ directory
- Mocking with PHPUnit's `createMock()`
- Currently focused on recent features (tag sets have full test coverage)

### Security

- All data user-scoped (multi-tenant isolation)
- Sensitive data (account numbers) encrypted via `EncryptionService`
- **Password Protection** (v1.2.0+): Optional app-level password with session management
  - `AuthService` handles authentication, session tokens (64-char random)
  - `AuthMapper` stores bcrypt-hashed passwords in `budget_auth` table
  - Failed attempts tracked (5 fails = 5-minute lockout)
  - Configurable session timeout (15/30/60 minutes)
  - Rate limiting on auth endpoints
- **Factory Reset**: Complete data deletion via `FactoryResetService` (preserves audit logs)
- Audit logging for all financial actions (`AuditService`)
- CSRF protection handled by Nextcloud framework

## SQLite Compatibility

When writing queries:
- Use `COALESCE()` instead of `IFNULL()` (MySQL-specific)
- Avoid MySQL-specific functions
- Use parameter binding (always) for type safety
- Test on both MySQL and SQLite if modifying queries

## Git Workflow

- Main branch: `master`
- Feature branches: `feature/description` or `refactor/description`
- Commit style: Conventional commits (feat:, fix:, test:, refactor:, etc.)
- Current architecture: Modular frontend with 16 feature modules in `src/modules/`

## Common Gotchas

### Backend
1. **Missing DI Registration:** New services/mappers must be registered in `Application.php` or they'll fail at runtime
2. **User Scoping:** Always filter by `userId` in Mapper queries
3. **Database Types:** Different behavior between MySQL and SQLite (test both)
4. **TCPDF Autoloading:** Composer autoloader loaded in `Application.php` constructor
5. **Tag Sets Registration:** TagSetMapper and related mappers are already registered (lines 309-341 in Application.php)

### Frontend
6. **Rebuild Required:** Changes to `src/` files require `npm run build` before testing (or use `npm run watch`)
7. **Modular Architecture:** Frontend is organized by feature - each module in `src/modules/` handles its own view and logic
8. **Module Communication:** Use custom DOM events for cross-module communication (see existing patterns in modules)
9. **Centralized State:** BudgetApp in main.js maintains shared state - modules access via `this.app.accounts`, `this.app.categories`, etc.
10. **Chart.js Integration:** Chart.js is imported in main.js - available globally in modules
11. **API Errors:** API calls return promises - always use try/catch or .catch() for error handling
12. **Session Tokens:** Password protection stores session tokens in localStorage - cleared on logout/timeout

## Translation & i18n

The app is fully internationalized using Nextcloud's standard translation system.

### For Translators

**Contributing a new translation:**
1. Download the template file from `translationfiles/templates/budget.pot` (generated via `make translations`)
2. Copy it to `translationfiles/[language_code]/budget.po` (e.g., `it/budget.po` for Italian)
3. Translate all strings in the `.po` file using a tool like Poedit or manually
4. Submit a pull request with the new `.po` file
5. Maintainers will compile it using `php translationtool.phar convert-po-files`

**Updating an existing translation:**
1. Edit the appropriate `translationfiles/[language_code]/budget.po` file
2. Submit a pull request with your changes
3. Maintainers will recompile the translations

### For Developers

**Adding new translatable strings:**

Backend (PHP):
```php
// In controllers - inject IL10N via constructor
use OCP\IL10N;
private IL10N $l;
// Then use:
$this->l->t('Your string here')
$this->l->t('String with %1$s placeholder', [$variable])
$this->l->n('%n item', '%n items', $count) // plural
```

Frontend (JavaScript):
```javascript
// Import translation functions
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
// Then use:
t('budget', 'Your string here')
t('budget', 'String with {variable} placeholder', { variable: value })
n('budget', '%n item', '%n items', count) // plural
```

Templates (PHP):
```php
<?php p($l->t('Your string here')); ?>
<?php p($l->t('String with %1$s', [$variable])); ?>
```

**Extracting strings and compiling translations:**
```bash
# Extract all t() and n() calls into .pot template
make translations
# OR manually:
php translationtool.phar create-pot-files

# Compile .po files to .json/.js for production
php translationtool.phar convert-po-files
```

**Important patterns:**
- Use descriptive strings, not codes
- Keep HTML outside translation strings where possible
- Use positioned placeholders (`%1$s`, `%2$s`) for reordering flexibility
- Add `// TRANSLATORS` comments for context
- Never split sentences across multiple `t()` calls

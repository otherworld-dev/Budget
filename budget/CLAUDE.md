# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Quick Start
```bash
make dev          # Install deps and build for development
make watch        # Watch and rebuild on changes
make enable       # Enable app in Nextcloud (must be in apps directory)
```

### Building and Dependencies
```bash
make composer-dev # Install PHP dependencies (dev mode)
make composer     # Install PHP dependencies (production mode)
make build-js     # Build JavaScript assets
make build        # Build complete distribution package
make appstore     # Create signed app store package
```

### Testing and Quality
```bash
make test         # Run PHPUnit tests
make lint         # Run all linters (PHP + JS)
make lint-fix     # Auto-fix linting issues
make psalm        # Run static analysis
```

### Development Workflow
```bash
make install      # Install built app to Nextcloud
make migrate      # Run database migrations manually
make clean        # Clean all build artifacts
```

## Architecture Overview

### Core Application Structure
This is a **Nextcloud App Framework** application following MVC architecture with the following key layers:

**Application Layer (`lib/AppInfo/Application.php`)**
- Bootstrap class implementing `IBootstrap`
- Registers services and navigation entries
- Main app ID constant: `APP_ID = 'budget'`

**Database Layer (`lib/Db/`)**
- **Entities**: Account, Transaction, Category, ImportRule (extend Nextcloud Entity)
- **Mappers**: Corresponding mappers extend QBMapper for database operations
- **Migration**: `Version001000000Date20250831.php` creates 5 core tables

**Service Layer (`lib/Service/`)**
- Business logic separated from controllers
- Key services: TransactionService, AccountService, CategoryService, ImportService, ForecastService, ReportService
- Services handle complex operations like import processing and financial forecasting

**Controller Layer (`lib/Controller/`)**
- RESTful API controllers extending AppFramework Controller
- All controllers require `@NoAdminRequired` annotation
- Main controllers: Account, Transaction, Category, Import, ImportRule, Forecast, Report, Setup

### Key Database Schema
```
budget_accounts (id, user_id, name, type, balance, currency)
budget_transactions (id, account_id, category_id, date, description, amount, type, import_id)
budget_categories (id, user_id, name, type, parent_id, budget_amount)
budget_import_rules (id, user_id, pattern, field, match_type, category_id, priority)
budget_forecasts (id, user_id, account_id, forecast_data)
```

### Frontend Architecture
**Templates (`templates/index.php`)**
- Single-page application with view switching
- Modular sections: Dashboard, Accounts, Transactions, Categories, Import, Forecast, Reports

**JavaScript (`src/main.js` → `js/budget-main.js`)**
- Vanilla JavaScript with Chart.js for visualizations
- Main BudgetApp class handles navigation, API calls, and UI updates
- Webpack builds from src/ to js/ directory

**CSS (`css/style.css`)**
- Responsive design following Nextcloud design patterns
- CSS custom properties for theming
- Mobile-first responsive breakpoints

### Import System Architecture
**Multi-format Support**:
- **CSV Parser**: Handles various CSV formats with configurable column mapping
- **OFX Parser**: Basic OFX (Open Financial Exchange) transaction extraction
- **QIF Parser**: Quicken Interchange Format support

**Rule Engine**:
- Pattern matching system for automatic transaction categorization
- Supports: contains, starts_with, ends_with, equals, regex matching
- Priority-based rule application

**Process Flow**:
1. File upload → temporary storage in app data folder
2. Format detection and parsing
3. Column mapping and validation
4. Rule application and duplicate detection
5. Batch transaction creation

### Forecasting Engine
**Analysis Components**:
- **Trend Analysis**: Linear regression on historical income/expenses
- **Pattern Recognition**: Detects recurring transactions and seasonal patterns
- **Confidence Scoring**: Data quality and prediction reliability metrics
- **Scenario Modeling**: Conservative, optimistic, and custom scenarios

**Algorithm Flow**:
1. Historical data aggregation by month/category
2. Statistical analysis (averages, trends, volatility)
3. Recurring transaction detection
4. Seasonality calculation (if 12+ months data)
5. Multi-month projection with confidence intervals

### API Structure
**REST Endpoints** (see `appinfo/routes.php`):
- 25+ endpoints following RESTful conventions
- Grouped by resource: /api/{accounts|transactions|categories|import|forecast|reports}
- Setup endpoints for app initialization

**Authentication**:
- Uses Nextcloud's built-in authentication
- User isolation via userId service injection
- CSRF protection on all state-changing operations

### Build System
**Webpack Configuration** (`webpack.config.js`):
- Entry: `src/main.js` → Output: `js/budget-main.js`
- Chart.js bundled as dependency
- Production mode optimization

**Composer** (`composer.json`):
- PSR-4 autoloading: `OCA\Budget\ → lib/`
- Dev dependencies: PHPUnit, Psalm, PHP-CS-Fixer
- Scripts for linting, testing, static analysis

## Development Patterns

### Service Dependencies
Controllers inject services via constructor dependency injection. Services are registered in Application.php and auto-resolved by Nextcloud's container.

### Database Patterns
- All mappers follow Nextcloud QBMapper pattern
- Entities use magic methods for getters/setters
- Migrations use Nextcloud's schema builder
- User isolation enforced at mapper level

### Error Handling
Controllers return DataResponse with appropriate HTTP status codes. Services throw exceptions that controllers catch and convert to error responses.

### Frontend-Backend Communication
Frontend makes AJAX calls to `/apps/budget/api/*` endpoints using OC.generateUrl(). All API responses are JSON with consistent error format.
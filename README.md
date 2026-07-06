[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/P5P31KQRF1)

# Nextcloud Budget

> ⚠️ **Beta**: This app is under active development. While stable, please backup your data regularly and [report any issues](https://github.com/otherworld-dev/Budget/issues) you encounter.

A comprehensive financial management app for Nextcloud. Track spending habits, manage multiple accounts, and forecast future balances through intelligent analysis of your financial history.

![Dashboard Screenshot](screenshots/dashboard.png)

## What's New in v2.36

- **Auto-share new entities** - A per-person "Auto-share new" toggle for each entity type (accounts, categories, bills, recurring income, savings goals): switch it on and anything you create from then on is shared automatically, at the permission you choose ([#306](https://github.com/otherworld-dev/Budget/issues/306))
- **Pensions, revamped** - Fund contributions straight from a bank account (kept out of your spending), record withdrawals, schedule recurring contributions, and project growth toward a configurable target — in nominal or inflation-adjusted "today's money"
- **Consistent "Exclude from reports"** - Excluded accounts and categories are now respected across every report and dashboard tile, and selecting a single excluded account in a report shows its data again
- Plus code-integrity, notification and reporting fixes — see the [full changelog](budget/CHANGELOG.md)

Earlier highlights: envelope/rollover budgets, statement reconciliation, budget digest, unusual-spending alerts and scheduled PDF reports (v2.30); the [Nextcloud integration pack](docs/nextcloud-integration.md) — dashboard widgets, unified search, bills calendar feed, receipt attachments (v2.29).

## Documentation

Full user documentation is available in the [docs/](docs/index.md) directory:

- **[Quick Start Guide](docs/getting-started.md)** — Set up in 10 minutes
- **[Full Documentation Index](docs/index.md)** — All features documented

Popular topics:
- [Importing bank statements](docs/import.md)
- [Setting up auto-categorization rules](docs/rules.md)
- [Budget tracking](docs/budget.md)
- [Dashboard customization](docs/dashboard.md)
- [Bills & recurring payments](docs/bills.md)
- [Settings reference](docs/settings.md)

## Support the Project

Budget is free and open source, built and maintained in spare time. If it's useful to you — especially if it replaced a paid finance service — consider supporting development:

- **[GitHub Sponsors](https://github.com/sponsors/otherworld-dev)** — monthly or one-time
- **[PayPal](https://www.paypal.com/donate/?hosted_button_id=MA56N6K8FSTQ2)** — one-time donation

Sponsorship funds the time behind quick fixes, new features, and keeping the app moving.

## Features

### Dashboard & Visualization
- **Customizable Dashboard** - Gridstack.js-powered drag-and-drop with lock/unlock editing, column picker, and per-tile sizing (S/M/L)
- **Duplicate Tiles** - Add multiple instances of chart and transaction tiles, each with independent account/period settings
- **28+ Dashboard Tiles** - Hero metrics, spending insights, forecasting, budget tracking, and more
- **Interactive Charts** - Cash flow forecasts, year-over-year comparisons, net worth history with Chart.js
- **Auto-Updating Tiles** - Dashboard refreshes automatically when transactions or budgets change

### Account & Transaction Management
- **Multi-Account Management** - Track bank accounts, credit cards, cash, and cryptocurrency across 45+ currencies
- **Transaction Tracking** - Add, edit, categorize, and search transactions with advanced filtering
- **Bulk Operations** - Delete, reconcile, or edit multiple transactions at once
- **Configurable Table Columns** - Show/hide columns to customize your transaction view
- **Split Transactions** - Allocate single transactions across multiple categories
- **Transaction Matching** - Automatic transfer detection and linking between accounts
- **Transfer Creation** - Create linked transfer transactions directly from transaction form
- **Pending Transactions** - Future-dated transactions shown with visual indicators and filterable status (All / Cleared / Pending)

### Import & Automation
- **Bank Sync (Beta)** - Connect external bank accounts for automatic transaction imports
  - GoCardless provider for UK/Europe banks with guided setup wizard and bank selection (note: GoCardless no longer accepts new sign-ups — existing accounts keep working; see [docs](docs/bank-sync.md))
  - SimpleFIN provider for US/Canadian banks
  - Optional pending-transaction import with automatic reconciliation when they post
  - Daily background sync with duplicate detection
  - Re-authorization flow when bank consent expires (PSD2 90-day limit)
  - Sync all connections at once with aggregated results
  - Encrypted credential storage
  - Admin-enabled with experimental feature warnings
- **Smart Import** - Import bank statements from CSV, OFX, and QIF formats
  - Auto-detection of CSV delimiters (comma, semicolon, tab)
  - Dual-column amount mapping for separate income/expense columns
  - European number format support (1.234,56)
  - Automatic vendor matching and duplicate detection
- **Advanced Rules Engine** - Powerful auto-categorization with visual query builder
  - Complex boolean expressions with AND/OR/NOT operators
  - Nested criteria groups with unlimited depth
  - Multiple actions: category, vendor, notes, tags, account, type, reference
  - Preview matches before saving, run rules on existing transactions anytime
  - Priority-based execution with behavior settings (always, if_empty, append, merge)
- **Hierarchical Categories** - Organize spending with nested categories and drag-and-drop reordering
- **Tag Sets** - Multi-dimensional transaction categorization with custom tag sets per category

### Budgeting & Planning
- **Envelope (Rollover) Budgets** - Per-category carryover of unspent budget or overspend into the next month, derived live from actual spending
- **Statement Reconciliation Sessions** - Tick transactions against a bank statement with a live difference, resumable sessions, and per-account history
- **Budget Tracking** - Set spending limits by category with alerts when approaching or exceeding budgets
  - Per-month budget adjustments — change budgets from any month forward without affecting historical data
  - Dynamic period switching (weekly, monthly, quarterly, yearly)
  - Automatic pro-rating when changing budget periods
  - Parent category aggregation — parent budgets show combined totals with children
  - Real-time progress tracking with visual indicators
- **Balance Forecasting** - Predict future balances using trend analysis and scenario modeling
- **Recurring Bills** - Detect and track recurring payments
  - Auto-pay option to automatically mark bills as paid on due date
  - Create future transactions for cash flow planning
  - Bill reminders via Nextcloud notifications
  - Custom frequency patterns (select specific months for irregular bills)
- **Bills Calendar** - Annual overview showing which months bills are due
  - Interactive heatmap visualization
  - Monthly totals with bar chart
  - Filter by status and include/exclude transfers
- **Recurring Income** - Track expected income sources (salary, dividends, etc.) with receipt tracking
- **Recurring Transfers** - Track and automate transfers between accounts
  - Monthly equivalent calculations for different frequencies
  - Integration with bills system for unified tracking

### Assets & Goals
- **Asset Tracking** - Track non-liquid assets (real estate, vehicles, jewelry, collectibles)
  - Value history with snapshots over time
  - Appreciation/depreciation projections with interactive charts
  - Integrated into net worth calculations
- **Debt Payoff Planner** - Plan debt repayment using avalanche or snowball strategies
  - Repayment charts with stacked area / individual line toggle
  - Named scenarios with extra payments, lump sums, debt selection, and rate overrides
  - Strategy comparison with side-by-side metrics and recommendation
  - Progress tracking against active scenario (ahead/behind/on track)
  - Dashboard widgets: chart sparkline, progress countdown, days until debt free
- **Savings Goals** - Set financial targets with progress tracking and achievement forecasting
  - Link goals to tags for automatic amount calculation from tagged transactions
- **Pension Tracker** - Track retirement accounts with contributions funded from a bank account, withdrawals, scheduled recurring contributions, and growth projections (nominal or inflation-adjusted "today's money") toward a configurable target, with combined forecasts

### Sharing & Collaboration
- **Budget Sharing** - Share accounts, categories, bills, recurring income and savings goals with other Nextcloud users, at read or read-write permission, configured per person
  - **Auto-share new entities** - Opt in per person and per type so anything you create later is shared automatically, at the permission you set
  - Recipients accept or decline; revoke or leave a share at any time
- **Shared Expenses** - Share expenses with roommates, partners, or friends and track who owes whom
  - Settlement tracking with payment history
  - Contact integration with Nextcloud

### Reporting & Analysis
- **Net Worth History** - Track assets, liabilities, and non-liquid assets over time with interactive charts
  - Daily automatic snapshots with manual recording option
  - Multi-currency conversion to default currency via ECB and CoinGecko rates
  - Status indicators showing last snapshot timing
- **Bills Calendar** - Visualize when bills are due throughout the year
- **Year-over-Year Reports** - Compare spending across multiple years side-by-side
- **Reports & Charts** - Visualize spending patterns, income, and cash flow over time

### Nextcloud Integration
- **Dashboard Widgets** - Upcoming bills and budget overview on the Nextcloud dashboard
- **Unified Search** - Transactions searchable from the Nextcloud top bar (own and shared accounts)
- **Bills Calendar Feed** - Token-authenticated ICS subscription for any calendar client, with reminders as alarms
- **Receipt Attachments** - Receipts linked from your own Files; never copied, never deleted by the app

### Security & Data Management
- **Password Protection** - Optional secondary password layer for enhanced app security
  - Session management with configurable timeout (15/30/60 minutes)
  - Auto-lock on inactivity with manual lock option
  - Failed attempt protection (5 attempts = 5-minute lockout)
- **Data Export/Import** - Full data migration support for moving between Nextcloud instances
- **Factory Reset** - Restore app to empty state with one-click data deletion (preserves audit logs)
- **Audit Logging** - Complete trail of all financial actions

## Requirements

- Nextcloud 30 - 35
- PHP 8.1+
- MySQL/MariaDB, PostgreSQL, or SQLite

## Installation

### From App Store (Recommended)

1. Log in to your Nextcloud instance as admin
2. Go to **Apps** > **Office & text**
3. Search for "Budget"
4. Click **Download and enable**

### Manual Installation

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/otherworld-dev/Budget.git budget
cd budget/budget

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install

# Build frontend
npm run build
```

Enable the app:

```bash
php occ app:enable budget
```

## Development

### Setup Development Environment

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/otherworld-dev/Budget.git budget
cd budget/budget

# Install all dependencies
composer install
npm install

# Build for development
npm run dev

# Watch for changes
npm run watch
```

### Build Commands

| Command | Description |
|---------|-------------|
| `npm run build` | Production build |
| `npm run dev` | Development build with source maps |
| `npm run watch` | Auto-rebuild on file changes |
| `npm run lint` | Run ESLint |
| `npm run lint:fix` | Auto-fix linting issues |

### Project Structure

```
budget/
├── appinfo/           # App metadata and routing
├── lib/
│   ├── Controller/    # API endpoints
│   ├── Service/       # Business logic
│   ├── Db/            # Database models and mappers
│   ├── Enum/          # Type definitions
│   └── Migration/     # Database schema versions
├── src/               # Frontend source (ES6+)
│   ├── modules/       # Feature-based modules (17 modules)
│   ├── core/          # Router and infrastructure
│   ├── utils/         # Shared utilities
│   └── config/        # Dashboard widgets configuration
├── js/                # Compiled JavaScript
├── css/               # Compiled styles
├── templates/         # PHP templates
└── tests/             # PHPUnit test suites
```

## Usage

> See the [full documentation](docs/index.md) for detailed guides on every feature.

### Getting Started

1. **Add Accounts** - Navigate to the Accounts section and add your bank accounts
2. **Import Transactions** - Use the Import feature to upload your bank statements
3. **Set Up Categories** - Create categories that match your spending patterns
4. **Configure Import Rules** - Set up rules to automatically categorize future imports
5. **Track Bills** - Add recurring bills to monitor upcoming payments
6. **Set Goals** - Create savings goals to track progress toward financial targets

### Importing Bank Statements

The app supports the following formats:
- **CSV** - Most banks provide CSV exports (supports European formats)
- **OFX** - Open Financial Exchange format
- **QIF** - Quicken Interchange Format

#### CSV Import Tips

1. The first row should contain column headers
2. Common columns: Date, Description, Amount, Balance
3. Use the column mapping feature to match your bank's format
4. For European banks with separate income/expense columns, use dual-column amount mapping
5. The app auto-detects delimiters (comma, semicolon, tab)

### Setting Up Import Rules

The advanced rules engine uses a visual query builder for complex patterns:

1. Go to **Rules** in the navigation menu
2. Click **Add Rule**
3. Build criteria using the visual query builder:
   - Add conditions (field, match type, pattern)
   - Group conditions with AND/OR operators
   - Use NOT operator for negation
   - Create nested groups for complex logic
4. Configure actions (category, vendor, notes, etc.)
5. Set priority and behavior (always, if_empty, append, merge)
6. **Preview** to test matches before saving
7. **Run Now** to apply to existing transactions

### Bills and Transfers

**Bills:**
- Add recurring bills with custom frequency patterns
- Enable auto-pay to automatically mark bills as paid on due date
- Create future transactions for cash flow planning
- View annual bills calendar to see when bills are due throughout the year

**Transfers:**
- Track recurring transfers between accounts (e.g., monthly savings transfers)
- Enable auto-pay for automatic execution
- Set transaction description patterns for import matching

### Forecasting

The forecast feature analyzes historical spending to predict future balances:

1. Select the account(s) to forecast
2. Choose the historical period to analyze (3, 6, or 12 months)
3. Select the forecast horizon
4. Generate the forecast

The forecast considers:
- Regular income patterns
- Recurring expenses
- Seasonal variations
- Average spending by category

### Data Migration

To move your data between Nextcloud instances:

1. **Export** - Go to Settings > Data Migration > Export to download all your data
2. **Import** - On the new instance, go to Settings > Data Migration > Import and upload the export file

## API

The app provides a REST API for all functionality:

| Endpoint | Description |
|----------|-------------|
| `/api/accounts` | Account management |
| `/api/transactions` | Transaction CRUD and search |
| `/api/categories` | Category hierarchy |
| `/api/budget-snapshots` | Per-month budget adjustments |
| `/api/bank-sync` | External bank sync (Beta) |
| `/api/admin/settings` | Admin settings (bank sync toggle) |
| `/api/tag-sets` | Tag set management |
| `/api/import` | Bank statement import |
| `/api/import-rules` | Advanced auto-categorization rules |
| `/api/forecast` | Balance predictions |
| `/api/bills` | Recurring bill tracking with auto-pay |
| `/api/transfers` | Recurring transfers between accounts |
| `/api/goals` | Savings goal management |
| `/api/assets` | Non-liquid asset tracking |
| `/api/pensions` | Pension account tracking |
| `/api/debts` | Debt payoff planning |
| `/api/debt-scenarios` | Debt scenario management |
| `/api/reports` | Financial reports |
| `/api/migration` | Data export/import |
| `/api/auth` | Password protection |

## Troubleshooting

### Import fails with "Invalid format"

- Ensure your CSV has headers in the first row
- Check that date format matches your locale settings
- Verify the file encoding is UTF-8
- For European formats, the app will auto-detect delimiters and number formats

### Transactions not categorizing automatically

- Check that import rules are active
- Use the **Preview** feature to test rule matching before saving
- Verify rule patterns match transaction descriptions
- Review rule priority order (higher priority rules execute first)

### Forecast seems inaccurate

- Ensure you have at least 3 months of transaction history
- Check for unusual one-time transactions that might skew averages
- Verify all regular transactions are properly categorized

### Categories page is blank

- Verify categories were imported successfully via Settings > Data Migration
- Check browser console for JavaScript errors
- Try refreshing browser cache (Ctrl+F5 / Cmd+Shift+R)

## Help Translate

Nextcloud Budget is fully translatable with 2,177+ strings. We'd love your help making it available in more languages!

**[Translate on Weblate](https://hosted.weblate.org/projects/nextcloud-budget/)** — translate directly in your browser, no coding required. Just sign in, pick your language, and start. Your translations are automatically merged into the app.

[![Translation status](https://hosted.weblate.org/widget/nextcloud-budget/budget/svg-badge.svg)](https://hosted.weblate.org/engage/nextcloud-budget/)

Currently available in: English, German, Spanish, French, Russian, Portuguese (Brazil), and Czech.

Special thanks to our translators: **[@SGiersch](https://github.com/SGiersch)** (German), **[@T0mFi](https://github.com/T0mFi)** and Pavel Borecki (Czech).

> **Note:** Strings containing `{placeholders}` (e.g. `{amount}`, `{bill}`) must keep the placeholder names exactly as-is — only translate the surrounding text.

<details>
<summary>Alternative: translate via pull request</summary>

If you prefer working with `.po` files directly:

1. Copy `budget/translationfiles/templates/budget.pot` to `budget/translationfiles/<lang>/budget.po`
2. Translate the strings using [Poedit](https://poedit.net/) or any `.po` editor
3. Submit a pull request

See the [translation guide](budget/translationfiles/README.md) for detailed instructions.
</details>

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes
4. Run tests (`make test`) and linting (`make lint`)
5. Submit a pull request

## Acknowledgements

This app is shaped by its community. Thanks to everyone who reports bugs, suggests features, and contributes translations:

- **[@SGiersch](https://github.com/SGiersch)** — Top contributor: dozens of bug reports, feature discussions, and German translation
- **[@TerjeTM](https://github.com/TerjeTM)** — Thorough testing and detailed bug reports that led to major improvements in the bills system and data repair tools
- **[@JaviAZ](https://github.com/JaviAZ)** — Code contributions and bug reports around category spending and transfers
- **[@H2Oufoe](https://github.com/H2Oufoe)** — Extensive bug reporting and testing across multiple releases
- **[@st33vil](https://github.com/st33vil)** — Identified the pagination balance calculation bug
- **[@T0mFi](https://github.com/T0mFi)**, **Pavel Borecki** — Czech translation
- **[@raduberbece](https://github.com/raduberbece)**, **[@MrTCAJ](https://github.com/MrTCAJ)**, **[@mschur](https://github.com/mschur)** — Bug reports and feedback

## License

This project is licensed under the **AGPL-3.0-or-later** license.

## Support

- **Issues**: [GitHub Issues](https://github.com/otherworld-dev/Budget/issues)
- **Discussions**: [GitHub Discussions](https://github.com/otherworld-dev/Budget/discussions)
- **Forum**: [Nextcloud Community](https://help.nextcloud.com)

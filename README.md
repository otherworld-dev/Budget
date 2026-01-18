# Nextcloud Budget

> ⚠️ **Beta**: This app is currently in testing. Data loss is possible. Please backup your data regularly and [report any issues](https://github.com/otherworld-dev/Nextcloud-Budget/issues) you encounter.

A comprehensive financial management app for Nextcloud. Track spending habits, manage multiple accounts, and forecast future balances through intelligent analysis of your financial history.

## Features

- **Multi-Account Management** - Track bank accounts, credit cards, and cash across multiple currencies
- **Transaction Tracking** - Add, edit, categorize, and search transactions with advanced filtering
- **Split Transactions** - Allocate single transactions across multiple categories
- **Transaction Matching** - Automatic transfer detection between accounts
- **Smart Import** - Import bank statements from CSV, OFX, and QIF formats with automatic vendor matching
- **Auto-Categorization** - Create custom rules to automatically categorize transactions, apply to existing data anytime
- **Hierarchical Categories** - Organize spending with nested categories and drag-and-drop reordering
- **Budget Tracking** - Set spending limits by category with alerts when approaching or exceeding budgets
- **Balance Forecasting** - Predict future balances using trend analysis and scenario modeling
- **Recurring Bills** - Detect and track recurring payments with due date monitoring and Nextcloud notifications
- **Recurring Income** - Track expected income sources (salary, dividends, etc.) with receipt tracking
- **Split Expenses** - Share expenses with roommates, partners, or friends and track who owes whom
- **Debt Payoff Planner** - Plan debt repayment using avalanche or snowball strategies
- **Savings Goals** - Set financial targets with progress tracking and achievement forecasting
- **Pension Tracker** - Track retirement accounts with growth projections and combined forecasts
- **Net Worth History** - Track assets and liabilities over time with interactive charts
- **Year-over-Year Reports** - Compare spending across multiple years side-by-side
- **Reports & Charts** - Visualize spending patterns, income, and cash flow over time
- **Data Export/Import** - Full data migration support for moving between Nextcloud instances
- **Audit Logging** - Complete trail of all financial actions

## Requirements

- Nextcloud 30 - 32
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
git clone https://github.com/otherworld-dev/budget.git
cd budget

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
git clone https://github.com/otherworld-dev/budget.git
cd budget

# Install all dependencies
composer install
npm install

# Build for development
make dev

# Watch for changes
make watch
```

### Build Commands

| Command | Description |
|---------|-------------|
| `make build` | Production build |
| `make dev` | Development build with source maps |
| `make watch` | Auto-rebuild on file changes |
| `make test` | Run PHPUnit tests |
| `make lint` | Run ESLint and PHP linters |
| `make lint-fix` | Auto-fix linting issues |
| `make psalm` | Run static analysis |
| `make appstore` | Create distribution package |

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
├── js/                # Compiled JavaScript
├── css/               # Compiled styles
├── templates/         # PHP templates
└── tests/             # PHPUnit test suites
```

## Usage

### Getting Started

1. **Add Accounts** - Navigate to the Accounts section and add your bank accounts
2. **Import Transactions** - Use the Import feature to upload your bank statements
3. **Set Up Categories** - Create categories that match your spending patterns
4. **Configure Import Rules** - Set up rules to automatically categorize future imports
5. **Track Bills** - Add recurring bills to monitor upcoming payments
6. **Set Goals** - Create savings goals to track progress toward financial targets

### Importing Bank Statements

The app supports the following formats:
- **CSV** - Most banks provide CSV exports
- **OFX** - Open Financial Exchange format
- **QIF** - Quicken Interchange Format

#### CSV Import Tips

1. The first row should contain column headers
2. Common columns: Date, Description, Amount, Balance
3. Use the column mapping feature to match your bank's format

### Setting Up Import Rules

Import rules automatically categorize transactions based on patterns:

1. Go to Settings > Import Rules
2. Click "Add Rule"
3. Configure:
   - **Pattern** - Text to match (e.g., "GROCERY STORE")
   - **Field** - Which field to match against (description, vendor)
   - **Match Type** - Contains, starts with, ends with, or regex
   - **Category** - Category to assign when matched
   - **Priority** - Higher priority rules are applied first

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
| `/api/import` | Bank statement import |
| `/api/import-rules` | Auto-categorization rules |
| `/api/forecast` | Balance predictions |
| `/api/bills` | Recurring bill tracking |
| `/api/goals` | Savings goal management |
| `/api/reports` | Financial reports |
| `/api/migration` | Data export/import |

## Troubleshooting

### Import fails with "Invalid format"

- Ensure your CSV has headers in the first row
- Check that date format matches your locale settings
- Verify the file encoding is UTF-8

### Transactions not categorizing automatically

- Check that import rules are active
- Verify rule patterns match transaction descriptions
- Review rule priority order

### Forecast seems inaccurate

- Ensure you have at least 3 months of transaction history
- Check for unusual one-time transactions that might skew averages
- Verify all regular transactions are properly categorized

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes
4. Run tests (`make test`) and linting (`make lint`)
5. Submit a pull request

## License

This project is licensed under the **AGPL-3.0-or-later** license.

## Support

- **Issues**: [GitHub Issues](https://github.com/otherworld-dev/budget/issues)
- **Forum**: [Nextcloud Community](https://help.nextcloud.com)

# Nextcloud Budget App

A comprehensive budget tracking and forecasting application for Nextcloud that helps you manage your finances, track spending habits, and predict future account balances.

## Features

- **Multi-Account Management**: Track multiple bank accounts with different currencies
- **Transaction Management**: Add, edit, categorize, and search transactions
- **Smart Import**: Import bank statements in CSV, OFX, and QIF formats
- **Auto-Categorization**: Set up rules to automatically categorize imported transactions
- **Custom Categories**: Create hierarchical category structures for detailed tracking
- **Financial Forecasting**: Predict future balances based on historical spending patterns
- **Reports & Analytics**: Generate detailed reports with charts and visualizations
- **Budget Planning**: Set budget limits for categories and track progress

## Requirements

- Nextcloud 30 or higher
- PHP 8.3 or higher
- MySQL/MariaDB, PostgreSQL, or SQLite

## Installation

### From App Store
1. Navigate to Apps in your Nextcloud instance
2. Search for "Budget"
3. Click Install

### Manual Installation
1. Clone or download this repository into your Nextcloud apps directory:
   ```bash
   cd /path/to/nextcloud/apps
   git clone https://github.com/yourusername/budget.git
   ```

2. Install dependencies:
   ```bash
   cd budget
   make composer
   make build-js
   ```

3. Enable the app:
   ```bash
   php /path/to/nextcloud/occ app:enable budget
   ```

## Development

### Setup Development Environment

1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/budget.git
   cd budget
   ```

2. Install dependencies:
   ```bash
   make composer-dev
   npm install
   ```

3. Build for development:
   ```bash
   make dev
   ```

4. Watch for changes:
   ```bash
   make watch
   ```

### Running Tests

```bash
make test
```

### Linting

```bash
make lint
make lint-fix
```

### Static Analysis

```bash
make psalm
```

## Usage

### Getting Started

1. **Add Accounts**: Navigate to the Accounts section and add your bank accounts
2. **Import Transactions**: Use the Import feature to upload your bank statements
3. **Set Up Categories**: Create categories that match your spending patterns
4. **Configure Import Rules**: Set up rules to automatically categorize future imports
5. **Review Dashboard**: Monitor your financial health from the dashboard

### Importing Bank Statements

The app supports the following formats:
- **CSV**: Most banks provide CSV exports
- **OFX**: Open Financial Exchange format
- **QIF**: Quicken Interchange Format

#### CSV Import Tips:
1. The first row should contain column headers
2. Common columns: Date, Description, Amount, Balance
3. Use the column mapping feature to match your bank's format

### Setting Up Import Rules

Import rules help automatically categorize transactions:

1. Go to Settings → Import Rules
2. Click "Add Rule"
3. Configure:
   - **Pattern**: Text to match (e.g., "GROCERY STORE")
   - **Field**: Which field to match against (description, vendor)
   - **Match Type**: Contains, starts with, ends with, or regex
   - **Category**: Category to assign when matched
   - **Priority**: Higher priority rules are applied first

### Forecasting

The forecast feature analyzes your historical spending to predict future balances:

1. Select the account(s) to forecast
2. Choose the historical period to analyze (3, 6, or 12 months)
3. Select the forecast horizon (how far to predict)
4. Click "Generate Forecast"

The forecast considers:
- Regular income patterns
- Recurring expenses
- Seasonal variations
- Average spending by category

## API Documentation

The app provides a REST API for integration with other services:

### Endpoints

- `GET /api/accounts` - List all accounts
- `POST /api/transactions` - Create a transaction
- `GET /api/categories` - Get category tree
- `POST /api/import/upload` - Upload bank statement
- `GET /api/forecast/generate` - Generate forecast
- `GET /api/reports/summary` - Get financial summary

See the full API documentation in the wiki.

## Troubleshooting

### Common Issues

**Import fails with "Invalid format"**
- Ensure your CSV has headers in the first row
- Check that date format matches your locale settings
- Verify the file encoding is UTF-8

**Transactions not categorizing automatically**
- Check that import rules are active
- Verify rule patterns match transaction descriptions
- Review rule priority order

**Forecast seems inaccurate**
- Ensure you have at least 3 months of transaction history
- Check for unusual one-time transactions that might skew averages
- Verify all regular transactions are properly categorized

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add/update tests as needed
5. Submit a pull request

## License

This app is licensed under the GNU Affero General Public License version 3 or later.

## Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/budget/issues)
- **Forum**: [Nextcloud Community](https://help.nextcloud.com)
- **Documentation**: [Wiki](https://github.com/yourusername/budget/wiki)
# Budget App Installation Guide

## Requirements

- Nextcloud 30 or higher
- PHP 8.3 or higher
- MySQL/MariaDB, PostgreSQL, or SQLite database
- Node.js 16+ (for building frontend assets)

## Installation Methods

### Method 1: App Store (Recommended)
1. Go to your Nextcloud Apps page
2. Search for "Budget"  
3. Click "Install"
4. Enable the app

### Method 2: Manual Installation

#### Step 1: Download and Extract
```bash
cd /path/to/nextcloud/apps
git clone https://github.com/yourusername/budget.git
cd budget
```

#### Step 2: Install Dependencies
```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install JavaScript dependencies
npm install

# Build frontend assets
npm run build
```

#### Step 3: Enable the App
```bash
# From your Nextcloud root directory
php occ app:enable budget
```

#### Step 4: Initialize Default Data
After enabling the app, visit the Budget app in your Nextcloud instance. The app will automatically prompt you to initialize default categories and import rules.

Alternatively, you can initialize via API:
```bash
curl -X POST http://your-nextcloud/apps/budget/api/setup/initialize \
  -H "Authorization: Bearer your-app-password"
```

## Development Setup

### Prerequisites
- Nextcloud development environment
- PHP 8.3+ with extensions: pdo_mysql, gd, zip, curl
- Node.js 16+ with npm
- Composer

### Development Installation
```bash
# Clone the repository
git clone https://github.com/yourusername/budget.git
cd budget

# Install all dependencies (including dev dependencies)
composer install
npm install

# Build for development with watch mode
make watch

# Or build manually
npm run dev
```

### Running Tests
```bash
# PHP unit tests
make test

# JavaScript linting
npm run lint

# Static analysis
make psalm
```

## Configuration

### Database Migration
The app will automatically run database migrations when enabled. If you need to run migrations manually:

```bash
php occ migrations:execute budget
```

### App Settings
Users can configure the following settings:

1. **Default Currency**: Set the default currency for new accounts
2. **Date Format**: Customize how dates are displayed
3. **Import Settings**: Configure default import behavior
4. **Notification Settings**: Choose when to receive budget alerts

### Import Configuration
The app supports importing from various financial institutions:

- **CSV Files**: Most common format, customizable column mapping
- **OFX Files**: Open Financial Exchange format from banks
- **QIF Files**: Quicken Interchange Format

#### Setting up Bank Imports
1. Go to Settings → Import Rules
2. Create rules to automatically categorize transactions
3. Test rules with sample data before importing large files

## Troubleshooting

### Common Issues

**Error: "App can't be installed"**
- Check PHP version (must be 8.3+)
- Ensure all required PHP extensions are installed
- Check Nextcloud version compatibility

**Import fails with "Invalid format"**
- Verify file has proper headers (for CSV)
- Check file encoding (should be UTF-8)
- Ensure date formats match your locale

**Frontend not loading**
- Run `npm run build` to rebuild assets
- Check browser console for JavaScript errors
- Clear Nextcloud cache: `php occ maintenance:repair`

**Database errors**
- Check database permissions
- Run migrations manually: `php occ migrations:execute budget`
- Check Nextcloud logs for detailed error messages

### Debug Mode
Enable debug mode in Nextcloud config:
```php
'debug' => true,
'loglevel' => 0,
```

### Getting Help
- Check the [GitHub Issues](https://github.com/yourusername/budget/issues)
- Visit [Nextcloud Community](https://help.nextcloud.com)
- Read the [User Documentation](README.md)

## Uninstalling

To remove the Budget app:

```bash
# Disable the app
php occ app:disable budget

# Remove app files (optional)
rm -rf /path/to/nextcloud/apps/budget
```

**Note**: Disabling the app will hide it from users but preserve all data. To permanently remove data, you would need to manually delete the database tables starting with `budget_`.

## Security Considerations

- The app follows Nextcloud's security guidelines
- All data is isolated per user
- CSRF protection is enabled for all forms
- Input validation and sanitization is implemented
- Uses prepared statements for database queries

## Performance Tips

- For large transaction datasets (10k+ transactions), consider archiving old data
- Import rules are processed in priority order - organize them efficiently  
- Regular database maintenance will keep queries fast
- Enable Nextcloud's caching for better performance

## Backup and Migration

### Backup
Your budget data is stored in your Nextcloud database. Regular database backups will include all budget information.

### Migration
To migrate between Nextcloud instances:
1. Export your data via Reports → Export
2. Install Budget app on new instance
3. Import your accounts and transactions
4. Recreate categories and rules as needed
# Installing Budget

## Requirements

- Nextcloud 30 to 35
- PHP 8.1 or later, with the BCMath extension (`php-bcmath`)
- MySQL/MariaDB, PostgreSQL or SQLite
- Node.js, npm and Composer (only when building from source)

## Installation

### App Store (recommended)

Log in to Nextcloud as an admin, go to **Apps**, search for "Budget" and click **Download and enable**. Updates then arrive through the Apps page like any other app.

### Release tarball

Each [GitHub release](https://github.com/otherworld-dev/Budget/releases) ships the same signed `budget.tar.gz` that is submitted to the App Store.

```bash
cd /path/to/nextcloud/apps
curl -LO https://github.com/otherworld-dev/Budget/releases/latest/download/budget.tar.gz
tar -xzf budget.tar.gz && rm budget.tar.gz

# From your Nextcloud root directory
php occ app:enable budget
```

### From source

The app lives in the `budget/` subfolder of the repository, so build it there and then copy (or symlink) that folder into `apps/`:

```bash
git clone https://github.com/otherworld-dev/Budget.git
cd Budget/budget

composer install --no-dev --optimize-autoloader
npm install
npm run build

cp -r . /path/to/nextcloud/apps/budget

# From your Nextcloud root directory
php occ app:enable budget
```

The database tables are created when the app is enabled and updated automatically on each upgrade. Until you create a category, the Categories page offers a **Use Default Categories** button that sets up a starter set of categories and import rules, and the [getting started guide](https://budget.otherworld.dev/docs/getting-started.html) takes it from there.

## Development setup

Clone the repository as above and symlink `Budget/budget` into your development instance's `apps/` folder, then install everything including the dev dependencies:

```bash
cd Budget/budget
composer install
npm install
npm run watch    # rebuilds js/budget-app.js as you edit src/
```

The PHP in `lib/` is used directly, so only changes under `src/` need a rebuild. Before opening a pull request, run these from `budget/`:

| Command | What it runs |
|---------|--------------|
| `make test` | PHPUnit (`composer test:unit`) and Vitest (`npm test`) |
| `make lint` | PHP syntax check and ESLint |
| `make psalm` | Psalm static analysis |

## Troubleshooting

**"App can't be installed"**: check the PHP version is 8.1 or later, the BCMath extension is installed, and your Nextcloud version is between 30 and 35.

**The page is blank or the frontend doesn't load**: if you installed from source, make sure `npm run build` completed, then check the browser console for errors and run `php occ maintenance:repair`.

**Database errors after an upgrade**: run `php occ migrations:migrate budget` and check the Nextcloud log for the full error. The [settings guide](https://budget.otherworld.dev/docs/settings.html) also explains what to do if an update did not finish.

If you are still stuck, open an issue on [GitHub](https://github.com/otherworld-dev/Budget/issues) with the error from the Nextcloud log.

## Moving to another server

Budget can export all of your data as a single archive and import it on another Nextcloud instance, see **Data Migration** in the [settings guide](https://budget.otherworld.dev/docs/settings.html). Your normal Nextcloud database backups also include everything Budget stores.

## Uninstalling

```bash
php occ app:disable budget
rm -rf /path/to/nextcloud/apps/budget    # optional
```

Disabling the app hides it from users but keeps all of the data. To remove the data permanently, drop the database tables whose names start with `budget_` (with your table prefix in front, usually `oc_`).

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Structure

This repository contains a **Nextcloud Budget App** - a comprehensive financial tracking and forecasting application for Nextcloud. All development work is done within the `budget/` directory.

```
.
└── budget/          # Main Nextcloud app directory
    ├── lib/          # PHP backend (MVC architecture)
    ├── src/          # JavaScript source files
    ├── js/           # Built JavaScript assets
    ├── templates/    # Nextcloud templates
    ├── appinfo/      # App metadata and routes
    └── CLAUDE.md     # Detailed development guide
```

## Quick Start

All development commands should be run from within the `budget/` directory:

```bash
cd budget

# Development setup
make dev          # Install dependencies and build for development
make watch        # Watch and rebuild on file changes

# For Docker-based Nextcloud development
docker compose exec nextcloud composer install --working-dir=/var/www/html/apps/budget --no-dev
docker compose exec nextcloud php occ app:enable budget
```

## Important Notes

1. **Working Directory**: Always work from `budget/` directory - this is where the Nextcloud app lives
2. **Dependencies**: The app requires PHP 8.3+ and Composer for backend, Node.js for frontend
3. **Docker Development**: If using Docker, commands need to be run inside the Nextcloud container
4. **App Installation**: Must be placed in Nextcloud's `apps/` directory and enabled via `occ` command

## Detailed Development Guide

See `budget/CLAUDE.md` for comprehensive documentation including:
- Complete command reference
- Architecture overview (MVC structure, database schema, API endpoints)
- Import system and forecasting algorithms
- Frontend build system
- Development patterns and best practices

## Key Technologies

- **Backend**: PHP 8.3+, Nextcloud App Framework (MVC)
- **Frontend**: Vanilla JavaScript, Chart.js, Webpack
- **Database**: Multi-format support (MySQL, PostgreSQL, SQLite)
- **Import Formats**: CSV, OFX, QIF financial data
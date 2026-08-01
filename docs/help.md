# Help & Docs

> The in-app home for the guides, the keyboard shortcuts, the Quick Add page URL, and the system information to paste into a bug report.

## Overview

**Help & Docs** is the last entry in the sidebar. It collects the things you go looking for when you're stuck or filing a bug, rather than the preferences you set once and forget — those stay in [Settings](settings.md).

There are two ways into help:

- The **Help & Docs** page (this one), for the full list of guides and the diagnostic tools.
- The floating **?** button in the bottom-right corner, which shows a short summary for whatever page you're currently on plus a link to that page's full guide. Its footer links through to this page.

## Documentation

A card per topic, each opening the full guide on this site. The list is generated from the same topic map the floating help panel uses, so the two never drift apart.

## Keyboard Shortcuts

**Show shortcuts** opens the cheat sheet — the same overlay you get by pressing `?` anywhere in the app.

Briefly: `g` followed by a letter jumps to any page, `/` (or `Ctrl`/`Cmd`+`K`) focuses search, `Esc` closes any dialog, and on the transaction list `j`/`k` move a row cursor with `e` to edit and `x` to select.

## Quick Add Page

The **Quick Add Page** is a standalone, minimal page for entering a single transaction on the go — it shows only the entry form, with no balances, account details, or other sensitive information visible. This makes it safe to use in public (e.g. paying at a till) without revealing your finances.

- This page shows a copyable **Quick Add URL** (`/apps/budget/quick-add`) you can bookmark or add to a device home screen.
- The Dashboard's **Quick Add Transaction** tile also has a **Standalone ↗** link that opens it in a new tab.

> **iPhone/iPad tip:** iOS Safari always points "Add to Home Screen" at the site root, so it can't link directly to the Quick Add page. A community workaround using the Apple Shortcuts app is documented here: [Solution: iPhone and Home Screen Link for "Quick Add"](https://github.com/otherworld-dev/Budget/discussions/261).

## System Info

The System Info panel provides diagnostic information useful when reporting issues:

- **App & Server Details** — Budget version, Nextcloud version, PHP version, database type
- **Data Stats** — Account count, transaction count, category count, active rules, bills, bank sync connections, sharing status
- **Browser & Screen** — Browser version and viewport dimensions
- **Client Diagnostics** — Failed API requests and JavaScript errors captured during the current session
- **Server Logs** (admin only) — Recent budget-related entries from the Nextcloud log

Click **Copy to Clipboard** to copy all diagnostic info as plain text for pasting into bug reports. **Report an Issue** in the page header opens the GitHub issue tracker.

## Related Features

| Feature | Description |
|---------|-------------|
| [Settings](settings.md) | Preferences, notifications, data migration, maintenance and factory reset |
| [Getting Started](getting-started.md) | Set up your first account and start tracking |

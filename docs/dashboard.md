# Dashboard

> The Dashboard is your customizable financial overview, featuring 28+ tiles that display key metrics, charts, and summaries at a glance. Arrange tiles to suit your workflow and see your finances update in real time.

## Overview

The Dashboard is the first screen you see when opening Nextcloud Budget. It provides a consolidated view of your financial health through configurable tiles that display account balances, budget progress, spending trends, upcoming bills, and more. All tiles auto-update when underlying data changes, so your dashboard always reflects the latest state of your finances.

## Dashboard Layout

The dashboard is organized into two sections:

- **Hero tiles** - Large metric cards displayed prominently at the top of the dashboard. These show your most important financial numbers (net worth, monthly income/expenses, savings rate, etc.) with large, easy-to-read values and optional subtext.
- **Widget tiles** - Smaller cards arranged below the hero section. These provide detailed views such as transaction lists, charts, budget breakdowns, and actionable summaries.

Both sections auto-update when you add transactions, update budgets, or make other changes elsewhere in the app.

## Unlocking the Dashboard

To customize your dashboard, click the **Unlock Dashboard** button in the top-right corner. When unlocked:

- A hint appears: "Drag tiles to reorder your dashboard"
- Each visible tile shows an X button for hiding it
- The **Add Tiles** menu becomes available
- Configuration icons (gear icons) appear on tiles that support customization

> **Tip:** Unlock the dashboard whenever you want to rearrange, show, or hide tiles. Lock it again when you are done to prevent accidental changes.

## Rearranging Tiles

While the dashboard is unlocked, drag and drop tiles to reorder them:

1. Click and hold a tile
2. Drag it to the desired position
3. Release to drop it in place

Hero tiles and widget tiles have separate drag areas -- you can reorder tiles within each section, but hero tiles stay in the top section and widget tiles stay in the bottom section. Your tile order is saved automatically.

## Showing and Hiding Tiles

Click the **Add Tiles** dropdown (visible when the dashboard is unlocked) to see all available tiles grouped by category:

- Hero metrics
- Insights
- Budgeting
- Charts
- And more

To show a hidden tile, click its name in the dropdown. To hide a visible tile, click the X button on the tile. All changes are saved automatically.

> **Note:** Hiding a tile only removes it from the dashboard display. No data is deleted, and you can show the tile again at any time.

## Hero Tiles

Hero tiles occupy the top section of the dashboard. Each displays a large metric value with optional subtext or trend indicator:

| Tile | Description |
|------|-------------|
| **Net Worth** | Total assets minus liabilities across all accounts |
| **Income This Month** | Total income received in the current month |
| **Expenses This Month** | Total spending in the current month |
| **Net Savings** | Income minus expenses, with savings rate percentage |
| **Pension Worth** | Combined value of all pension accounts |
| **Assets Worth** | Total value of asset-type accounts |
| **Savings Rate** | Percentage of income saved this month |
| **Cash Flow** | Net money flow across all accounts |
| **Budget Remaining** | Amount left to spend within your budget |
| **Budget Health** | Overall budget adherence indicator |
| **Account Income** | Income for the currently selected account |
| **Account Expenses** | Expenses for the currently selected account |

> **Shared accounts:** If other users have shared their accounts with you, those accounts are included in all dashboard summary calculations — Net Worth, Income/Expenses This Month, currency breakdowns, and account counts. Shared accounts are marked with a sharing indicator.

## Widget Tiles

Widget tiles provide detailed views, lists, charts, and actionable information:

| Tile | Description |
|------|-------------|
| **Accounts** | Account list with balances (supports customization via gear icon) |
| **Budget Alerts** | Notifications for over-budget or near-limit categories |
| **Upcoming Bills** | Bills due soon with amounts and due dates |
| **Budget Progress** | Visual progress bars for budget categories |
| **Savings Goals** | Progress toward savings targets |
| **Spending by Category** | Chart showing spending distribution |
| **Top Categories** | Highest-spending categories this period |
| **Recent Transactions** | Latest transactions across accounts |
| **Account Performance** | Account balance trends over time |
| **Budget Breakdown** | Detailed budget allocation and usage |
| **Goals Summary** | Overview of all financial goals |
| **Payment Breakdown** | Spending by payment method or account |
| **Reconciliation Status** | Accounts needing reconciliation |
| **Quick Add Transaction** | Add a transaction without leaving the dashboard |
| **Monthly Comparison** | Compare spending month-over-month |
| **Large Transactions** | Notable high-value transactions |
| **Weekly Spending** | Spending totals by week |
| **Unmatched Transfers** | Transfers that may need matching |
| **Category Trends** | Category spending over time |
| **Bills Due Soon** | Upcoming bill payments |
| **Income Tracking** | Income progress and projections |
| **Recent Imports** | Latest file imports and their status |
| **Rule Effectiveness** | How well auto-categorization rules are working |
| **Spending Velocity** | Rate of spending compared to budget |
| **Income vs Expenses** | Chart comparing income and expenses over time |
| **Cash Flow Forecast** | Projected future balances |
| **Year-over-Year** | Annual comparison chart |

## Accounts Tile Configuration

The Accounts tile supports additional customization. When the dashboard is unlocked, a gear icon appears on the Accounts tile header. Click it to open a configuration panel where you can:

- **Reorder accounts** - Drag accounts to change their display order on the dashboard
- **Toggle visibility** - Use checkboxes to show or hide individual accounts

Hidden accounts are not deleted -- they still exist on the [Accounts](accounts.md) page and continue tracking transactions. They simply do not appear in the dashboard Accounts tile. Configuration saves automatically.

> **Tip:** Use this to focus your dashboard on the accounts you check most frequently, while keeping less-used accounts accessible from the Accounts page.

## Locking the Dashboard

When you are finished customizing, click **Lock Dashboard** to prevent accidental changes. Locking the dashboard:

- Hides the **Add Tiles** menu
- Removes X buttons from tiles
- Hides gear icons on configurable tiles
- Disables drag-and-drop reordering

Your layout is preserved exactly as you left it.

## Related Features

- [Accounts](accounts.md) - Manage the accounts shown in the dashboard
- [Budget](budget.md) - Set budgets that drive dashboard metrics
- [Bills](bills.md) - Track recurring bills shown in dashboard tiles
- [Savings Goals](savings-goals.md) - Goals displayed in the Savings Goals tile
- [Forecast](forecast.md) - Projections powering the Cash Flow Forecast widget
- [Reports](reports.md) - Detailed analysis beyond dashboard summaries

## Settings

Dashboard state -- including tile visibility, order, and per-tile configuration -- is saved per-user automatically. No manual save action is required. Changes persist across sessions and devices.

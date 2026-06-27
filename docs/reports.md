# Reports

> Reports provide six specialized views of your financial data — from budget tracking and spending breakdowns to year-over-year comparisons and net worth history. Each report can be filtered, customized, and exported.

## Overview

The Reports section offers different perspectives on your finances. Each report type answers a specific question: Are you sticking to your budget? Where is your money going? How does this year compare to last? Use reports for periodic financial reviews or to investigate specific spending concerns.

Navigate to **Reports** and select the report type you want to generate.

### Account Scope

Use the **Account** selector to limit a report to specific accounts. If other people have [shared accounts](sharing.md) with you, an **"Exclude shared accounts"** toggle also appears — turn it on to base the report on **your own accounts only**, leaving out accounts shared to you. It's off by default (shared accounts are included), and only shows up when you actually have shared accounts. The same option is available per-card on the account-based dashboard tiles, via each tile's gear menu.

> **Note:** A transfer between one of your own accounts and a shared account can only be partly seen when shared accounts are excluded, so income/expense totals may be slightly overstated in that case.

## Budget Report

The Budget Report compares your planned budget against actual spending by category over a selected date range.

### What It Shows

| Column | Description |
|--------|-------------|
| **Category** | Each category with a budget set. |
| **Budgeted** | The amount you allocated for the period. |
| **Actual** | The amount actually spent. |
| **Difference** | Over or under budget (positive means under budget, negative means overspent). |
| **Progress Bar** | Visual fill showing percentage of budget consumed. Turns red when exceeding 100%. |

### Filters

- **Date Range** — Select the period to analyze (current month, last month, custom range).
- **Categories** — Include all categories or select specific ones.

> **Tip:** Run this report at the end of each month to see where you overspent and adjust next month's budget accordingly.

## Spending by Category

A visual breakdown of where your money goes, displayed as pie charts and bar charts.

### What It Shows

- **Pie Chart** — Proportional spending by category. Hover over slices to see amounts and percentages.
- **Bar Chart** — Ranked list of categories by total spending, making it easy to identify your largest expenses.
- **Subcategory Drill-Down** — Click a category to see spending broken down by its subcategories.

### Filters

| Filter | Options |
|--------|---------|
| **Date Range** | Any custom date range, or presets (this month, last month, this year, etc.). |
| **Account** | All accounts or a specific account. |
| **Tags** | Filter to transactions with specific tags. |

> **Note:** Only expense transactions are included by default. Income categories are excluded to focus the view on where money is being spent.

## Income vs Expenses

A side-by-side comparison of total income and total expenses over time, with net savings calculated.

### What It Shows

| Element | Description |
|---------|-------------|
| **Monthly Bars** | Paired bars for each month showing total income and total expenses. |
| **Net Savings Line** | The difference between income and expenses, plotted as a line overlaying the bar chart. Positive values mean you saved money; negative means you spent more than you earned. |
| **Summary Row** | Totals for the selected period: total income, total expenses, and net savings (both amount and percentage). |

### Filters

- **Date Range** — Select the period to compare (defaults to the last 12 months).
- **Account** — All accounts or a specific account.

> **Tip:** This report is ideal for spotting months where expenses spiked relative to income. Look for patterns — do you overspend in certain months consistently?

## Year-over-Year Comparison

Compare your spending across two or more years side by side to identify long-term trends.

### What It Shows

- **Monthly Comparison** — Each month's spending shown for each selected year in adjacent columns or overlaid lines.
- **Category Breakdown** — Drill into specific categories to see how spending in that category changed year to year.
- **Annual Totals** — Summary row with total spending per year and the percentage change.

### Filters

| Filter | Options |
|--------|---------|
| **Years** | Select 2 or more years to compare. |
| **Categories** | All categories or specific selections. |
| **Account** | All accounts or a specific account. |

> **Tip:** Year-over-year is particularly useful for catching lifestyle inflation — gradual spending increases that are hard to notice month to month but obvious when comparing annual totals.

## Bills Calendar

An annual overview showing which months your bills are due, helping you visualize your fixed obligations across the year.

### Views

| View | Description |
|------|-------------|
| **Heatmap** | A color-coded grid with months as columns and bills as rows. Darker cells indicate higher amounts. Provides a quick visual sense of which months are most expensive. |
| **Table** | A detailed table listing each bill and the months it is due. Paid bills are shown with ~~strikethrough~~. Includes a monthly totals row at the bottom. |

### Information Displayed

- **Bill amounts** per month based on frequency.
- **Paid status** — Paid months shown with strikethrough text; unpaid months shown normally.
- **Monthly totals** — The sum of all bill amounts due in each month, shown in the footer row.
- **Annual total** — The sum of all bills for the full year.

### Filters

| Filter | Options |
|--------|---------|
| **Status** | Active, Inactive, or All bills. |
| **Account** | Filter to bills linked to a specific account. |
| **Include Transfers** | Toggle whether inter-account transfers appear alongside bills. |

> **Tip:** Use the Bills Calendar at the start of the year to understand your fixed monthly obligations. It helps identify months with concentrated due dates where cash flow might be tight.

## Net Worth History

Track your total net worth over time — assets minus liabilities — with daily automatic snapshots and support for multiple currencies.

### What It Shows

| Element | Description |
|---------|-------------|
| **Net Worth Line** | Your total net worth plotted over time. |
| **Assets Line** | Total value of asset accounts (checking, savings, investments). |
| **Liabilities Line** | Total value of liability accounts (credit cards, loans). |
| **Non-Liquid Assets** | Separately tracked items like property or vehicles that contribute to net worth but are not readily spendable. |

### How Snapshots Work

- **Automatic Daily Snapshots** — A background job records your net worth once per day, capturing balances across all accounts.
- **Manual Recording** — You can manually record a snapshot at any time if you want to capture a specific point (e.g., after a large transaction or revaluation).
- **Historical Continuity** — Even if account balances change retroactively (e.g., imported transactions with past dates), snapshots reflect the balance as it was when recorded.

### Multi-Currency Support

If you have accounts in different currencies, net worth is converted to your primary currency using exchange rates from:

- **ECB (European Central Bank)** — For fiat currency conversions.
- **CoinGecko** — For cryptocurrency conversions.

> **Note:** Exchange rates are fetched automatically. The conversion uses the rate from the date of each snapshot, so historical net worth reflects historical exchange rates.

### Status Indicators

| Indicator | Meaning |
|-----------|---------|
| **Green arrow up** | Net worth increased since the previous snapshot. |
| **Red arrow down** | Net worth decreased since the previous snapshot. |
| **Clock icon** | Snapshot is pending (today's snapshot has not yet been recorded). |

## Exporting Reports

All reports can be exported for external use or record-keeping.

### Export Formats

| Format | Description |
|--------|-------------|
| **CSV** | Comma-separated values. Opens in any spreadsheet application (Excel, Google Sheets, LibreOffice Calc). Suitable for further analysis or custom charts. |
| **PDF** | Formatted document with charts and tables included. Suitable for sharing or archiving. |

### How to Export

1. Generate the report with your desired filters.
2. Click the **Export** button in the report toolbar.
3. Select **CSV** or **PDF**.
4. The file downloads to your device.

> **Tip:** CSV exports include raw data without formatting, making them ideal for importing into other financial tools or creating custom analyses in a spreadsheet.

## Related Features

- [Budget](budget.md) — The Budget Report directly compares your budget allocations against actual spending.
- [Categories](categories.md) — Spending reports are organized by your category hierarchy.
- [Bills](bills.md) — The Bills Calendar visualizes your recurring payment schedule.
- [Accounts](accounts.md) — Reports can be filtered by account and net worth tracks all account balances.
- [Tags](categories.md) — Spending reports can be filtered by tags for more granular analysis.

## Settings

Reports do not have dedicated settings. Export format preferences and default date ranges are remembered from your last report generation.

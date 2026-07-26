# Getting Started

> Add an account, import or enter transactions, set up categories, create a budget, and explore the dashboard. Takes about 10 minutes.

## 1. Add Your First Account

Navigate to the **Accounts** page from the left sidebar and click **Add Account**.

Fill in the details:

- **Name** -- a label you will recognize (e.g. "Main Checking", "Visa Rewards")
- **Type** -- choose from checking, savings, credit card, loan, investment, cash, cryptocurrency, money market, mortgage, or line of credit
- **Currency** -- pick from 45+ supported currencies including major fiat currencies and crypto
- **Opening Balance** -- the account balance at the time you start tracking

Click **Save**. Repeat for any other accounts you want to track.

> **Tip:** For credit cards and loans, enter the balance as a negative number to represent money owed. You can also set a credit limit for credit card accounts.

## 2. Add Transactions

You have two options: import from your bank or add transactions by hand.

### Import from your bank

Go to **Import** in the sidebar and upload a statement file. Budget supports **CSV**, **OFX**, and **QIF** formats. The importer auto-detects delimiters and number formats, and includes duplicate detection so you can safely re-import overlapping date ranges.

See [Import](import.md) for the full walkthrough including column mapping and dual-column amount handling.

### Add manually

Go to **Transactions**, select an account, and click **Add Transaction**. Enter the date, description, amount, and optionally assign a category. You can also split a single transaction across multiple categories.

## 3. Set Up Categories

Go to the **Categories** page. You have two options:

- **Generate defaults** -- click **Create Default Categories** and Budget will generate a full category tree based on the 50/30/20 budgeting rule (50% needs, 30% wants, 20% savings). If you provide your monthly income, budget amounts are pre-calculated for you.
- **Create your own** -- click **Add Category** to build a custom hierarchy from scratch.

Categories are organized in a tree structure, so you can have top-level groups (like "Housing" or "Food") with specific sub-categories underneath.

> **Tip:** You can always reorganize categories later. Transactions keep their assignments even if you move categories around.

## 4. Create a Budget

Go to the **Budget** page and set spending limits for each category. Choose a budget period (monthly is most common) and enter the amount you want to allocate to each category.

As transactions come in, Budget tracks your spending against these limits. You will see alerts on the dashboard when you are approaching or exceeding a budget.

## 5. Explore the Dashboard

The **Dashboard** is your financial command center. It shows 28+ customizable tiles organized into sections:

- **Hero tiles** at the top -- net worth, monthly income, monthly expenses, net savings, and more
- **Charts** -- income vs. expenses trends, spending by category, net worth history
- **Summary cards** -- account balances, budget progress, upcoming bills, savings goals, debt payoff status

Click **Customize Dashboard** to show, hide, or rearrange tiles to match what matters most to you.

> **Note:** Some tiles (like budget alerts and savings goals) only appear once you have set up the corresponding features.

## Keyboard Shortcuts

Budget can be driven from the keyboard. Press **?** at any time to open a cheat sheet of every shortcut.

**Global**

| Key | Action |
| --- | --- |
| `/` or `Ctrl`/`Cmd` + `K` | Focus the search box |
| `?` | Show / hide the keyboard shortcuts cheat sheet |
| `Esc` | Close the open dialog (or the cheat sheet) |
| `g` then a letter | Jump to a page |

**Go to a page** -- press `g`, then the page's letter. Every page in the sidebar has one:

| Key | Page | Key | Page | Key | Page |
| --- | --- | --- | --- | --- | --- |
| `d` | Dashboard | `n` | Transfers | `f` | Forecast |
| `a` | Accounts | `v` | Savings Goals | `r` | Reports |
| `t` | Transactions | `y` | Debt Payoff | `m` | Import |
| `c` | Categories | `p` | Pensions | `u` | Rules |
| `g` | Tags | `e` | Assets | `x` | Exchange Rates |
| `b` | Budget | `h` | Shared Expenses | `w` | Sharing |
| `i` | Income | | | `k` | Bank Sync |
| `l` | Bills | | | `s` | Settings |

**In any dialog** -- `Esc` closes it, `Tab` cycles through its fields, and `Enter` saves (in a text field). When a dialog opens, the first field is focused automatically.

**On the Transactions list** -- `j` / `k` move a row cursor down / up, `e` (or `Enter`) edits the highlighted row, and `x` selects or deselects it. See [Transactions](transactions.md) for more.

Shortcuts are ignored while you are typing in a text field, so they never get in the way of entering data.

## What's Next

Now that the basics are in place, explore these features to get more out of Budget:

- **[Rules](rules.md)** -- Set up auto-categorization rules so imported transactions are categorized automatically
- **[Bills](bills.md)** -- Track recurring bills and get Nextcloud notifications before they are due
- **[Income](income.md)** -- Monitor expected income sources and flag missed payments
- **[Forecast](forecast.md)** -- See predicted future balances based on your spending history
- **[Savings Goals](savings-goals.md)** -- Set financial targets and track your progress
- **[Reports](reports.md)** -- Generate year-over-year comparisons and spending breakdowns
- **[Shared Expenses](shared-expenses.md)** -- Split costs with housemates, partners, or friends
- **[Password Protection](password-protection.md)** -- Add an app-level password for extra security
- **[Settings](settings.md)** -- Customize currency, date formats, and other preferences

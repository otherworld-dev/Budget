# Accounts

> Accounts represent your real-world bank accounts, credit cards, loans, and other financial accounts. Each account holds its own set of transactions and tracks its balance independently, giving you a complete picture of your finances across all institutions.

## Account Types

When creating an account, you select a type that determines how the app interprets balances and which optional fields are shown.

| Type | Description |
|------|-------------|
| **Checking** | Standard current/checking accounts used for everyday spending and income. Balance is expected to be positive. |
| **Savings** | Savings or deposit accounts. Treated the same as checking but visually distinguished on the dashboard. |
| **Credit Card** | Revolving credit accounts. Balances are displayed as amounts owed. Supports credit limit tracking and utilization warnings (alerts at 70% and 90% utilization). |
| **Investment** | Brokerage, retirement, or other investment accounts for tracking portfolio value. |
| **Cash** | Physical cash or petty cash tracking. Useful when you want to record cash spending without tying it to a bank. |
| **Loan** | Personal loans, auto loans, or other debts. Balances represent the amount owed. Supports interest rate and minimum payment fields for debt payoff planning. |
| **Mortgage** | Home loans and property-secured debt. Treated the same as Loan but visually distinguished. Supports interest rate and minimum payment fields. |
| **Line of Credit** | Revolving credit facilities such as HELOCs or overdraft lines. Similar to Credit Card but intended for non-card credit accounts. Supports credit limit, interest rate, and minimum payment fields. |
| **Cryptocurrency** | Wallets or exchange accounts for digital currencies. Supports a wallet address field and works with crypto currencies (BTC, ETH, etc.). |

> **Tip:** The account type mainly affects how balances are displayed and which optional fields appear on the account form. You can always change the type later by editing the account.

## Liability Balance Model

Credit Card, Loan, Mortgage, and Line of Credit are all **liability** account types. They share a common balance model that differs from asset accounts like Checking or Savings.

### How Liability Balances Work

Liability balances are stored as negative numbers internally, but the app displays them as positive amounts with an "Owed" label so they are easy to read at a glance.

- A **payment** (credit transaction) decreases the amount you owe.
- A **charge** or **withdrawal** (debit transaction) increases the amount you owe.

For example, if you create a Loan account with £10,000 owed, the app stores the balance as −10,000 but displays it as **£10,000 Owed**. After making a £500 payment the display updates to **£9,500 Owed**.

### Creating a Liability Account

When you create a liability account, enter the amount you currently owe as a **positive number** in the Opening Balance field. The app converts it to a negative value automatically — you never need to enter negative numbers yourself.

### Net Worth

The dashboard's net worth calculation correctly subtracts liability balances from asset balances. If you have £20,000 across checking and savings accounts and £8,000 owed on a loan, your net worth shows as £12,000.

## Grid and List Views

The accounts page offers two layouts, toggled with the view switcher in the accounts header.

| View | Description |
|------|-------------|
| **Grid** (default) | Tile cards showing account name, balance, sparkline chart, and health status indicators. |
| **List** | Compact table with columns for name, type, institution, and balance. Useful when you have many accounts. |

Your chosen view is saved automatically and restored the next time you visit the accounts page. In either view, click any card or row to open the account detail view.

## Creating an Account

Navigate to **Accounts > Add Account** to create a new account.

### Required Fields

| Field | Description |
|-------|-------------|
| **Name** | A descriptive name for the account (e.g., "Chase Checking", "Amex Gold"). |
| **Type** | One of the account types listed above. |
| **Currency** | The currency this account operates in. The app supports 45+ currencies including major fiat currencies (USD, EUR, GBP, JPY, CAD, AUD, CHF, etc.) and cryptocurrencies (BTC, ETH, XRP, LTC, etc.). |

### Optional Fields

| Field | Description |
|-------|-------------|
| **Opening Balance** | The starting balance when you begin tracking this account. For liability accounts (Credit Card, Loan, Mortgage, Line of Credit), enter the amount owed as a positive number — see [Liability Balance Model](#liability-balance-model). A live preview of the resulting current balance is shown as you edit this field. |
| **Credit Limit** | Available for credit card accounts. Used to calculate credit utilization percentage. |
| **Interest Rate** | Available for liability accounts. Used in debt payoff calculations. |
| **Minimum Payment** | Available for liability accounts. Used in debt payoff planning. |
| **Overdraft Limit** | The overdraft threshold for the account. The app warns you when your balance drops below this limit. |
| **Exclude from reports, dashboard & forecast** | When ticked, the account is kept out of every "all accounts" total — reports, the dashboard, forecast, net worth and category budgets. See [Excluding an Account from Aggregations](#excluding-an-account-from-aggregations). |

> **Note:** The currency is set per account, so you can track accounts in different currencies. Multi-currency totals on the dashboard use exchange rates to convert to your default currency. See [Exchange Rates](exchange-rates.md) for details.

## Excluding an Account from Aggregations

Sometimes you want to track an account in Budget without letting it affect your overall financial picture — for example an account you manage on someone else's behalf, or one that isn't really part of your own net worth. Tick **Exclude from reports, dashboard & forecast** in the account's edit dialog to do exactly that.

When an account is excluded:

- Its transactions and balance are left out of all **reports** (spending, income, cash flow, trends, tags), the **dashboard** (totals, charts and summary tiles), the **forecast**, **net worth**, **total balance**, and **category budgets** — everywhere "All accounts" is aggregated.
- The account still appears in your **accounts list** (marked with an **Excluded** badge) and on its own **detail page**, and its transactions still show in the transactions list, so you can keep using and reconciling it normally.
- If you explicitly select the account in a report or forecast, that view still works on its own — the exclusion only applies to the combined "all accounts" figures.

You can toggle this off again at any time; the account immediately rejoins all totals (everything is derived live, nothing is cached).

## Account Detail View

Click any account card to open the account detail view, which shows:

### Current Balance vs. Projected Balance

- **Current Balance** is the sum of all cleared (non-scheduled) transactions plus the opening balance. This reflects what your actual bank balance should be.
- **Projected Balance** includes scheduled (future-dated) transactions in addition to cleared ones. This tells you what your balance will be once upcoming transactions clear.

The projected balance is shown alongside the current balance when they differ, giving you visibility into expected future cash flow.

### Balance History Chart

The detail view includes a balance history chart that plots your account balance over time. This helps you spot trends, identify spending patterns, and see how your balance has changed historically.

### Recent Transactions

The detail view shows the most recent transactions for the account with quick access to add new transactions or navigate to the full [Transactions](transactions.md) view filtered to this account.

## Account Information

Each account has optional banking detail fields for your reference. These fields are all encrypted at rest and are never shared or transmitted.

| Field | Description |
|-------|-------------|
| **Account Number** | Your bank account number. |
| **Routing Number** | US bank routing/ABA number. Shown for checking and savings accounts. |
| **Sort Code** | UK bank sort code. Shown based on account type and currency context. |
| **IBAN** | International Bank Account Number. Common for European and international accounts. |
| **SWIFT/BIC** | SWIFT or BIC code for international wire transfers. |
| **Wallet Address** | Cryptocurrency wallet address. Shown only for cryptocurrency accounts. |

> **Tip:** These fields are purely for your own reference -- the app does not connect to any bank. They are stored with encryption so you can keep all your account details in one place without security concerns.

The account form dynamically shows and hides these fields based on the account type you select. For example, selecting **Checking** shows routing number and IBAN fields, while selecting **Cryptocurrency** shows the wallet address field instead.

## How Balances Are Calculated

An account's current balance is always derived from its ledger:

```
current balance = opening balance + net of all non-scheduled transactions
```

The **opening balance** represents what the account held before the first transaction you track in the app. It is set when you create the account and can be edited later — useful when you start tracking mid-life of a real account, or to true-up after reconciling.

Because the balance is recomputed from your transactions on every change, it can never drift out of step with the ledger: editing, moving, or deleting a transaction always leaves the balance exactly consistent with what the transaction list shows.

> **Note:** Scheduled (future-dated) transactions don't count toward the current balance — they appear in the projected balance instead. See [Transaction Status](transactions.md#transaction-status).

## Reconciling Accounts

Reconciliation verifies that your tracked balance matches your actual bank statement. This helps catch missing or incorrect transactions.

### How to Reconcile

1. Open the account detail view and click **Reconcile Account**, or navigate to **Transactions** and click the **Reconcile** button.
2. Select the account to reconcile (pre-selected if you started from the account detail view).
3. Enter the **statement balance** from your bank statement and the **statement date**.
4. The reconciliation panel shows the difference between your tracked balance and the statement balance.
5. Review your transactions and mark them as reconciled by checking them off.
6. If there is a remaining difference, the app offers to create an adjustment transaction to bring your balance in line with the statement.

The adjustment transaction is created with the description "Reconciliation Adjustment" and a note recording the target statement balance, so you can identify it later.

> **Tip:** Reconcile regularly -- monthly when you receive your bank statement is a good cadence. This catches data entry errors and missing transactions early.

## Deleting an Account

To delete an account, open the account detail view and choose the delete option.

> **Warning:** Deleting an account permanently removes the account and all of its transactions. This action cannot be undone. If you want to keep historical data, consider archiving the account by renaming it (e.g., "Old Checking - Closed") rather than deleting it.

## Related Features

- [Transactions](transactions.md) -- View, add, and manage transactions within your accounts
- [Transfers](transfers.md) -- Move money between your accounts with linked transfer transactions
- [Importing Bank Statements](import.md) -- Import transactions from CSV, OFX, or QIF files into an account
- [Dashboard](dashboard.md) -- See balances and health indicators for all your accounts at a glance
- [Exchange Rates](exchange-rates.md) -- Configure exchange rates for multi-currency account totals

## Settings

- **Default currency** -- Sets the default currency for new accounts and the base currency for dashboard totals. Change this in **Settings > General**.

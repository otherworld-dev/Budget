# Transactions

> Transactions are the individual financial entries within your accounts -- every purchase, deposit, transfer, and payment. The transactions view is where you record, review, and organize your financial activity.

## Adding a Transaction

Navigate to an account's transaction list and click **Add Transaction**, or use the global add button from any page.

### Transaction Fields

| Field | Required | Description |
|-------|----------|-------------|
| **Date** | Yes | The date the transaction occurred. Future dates create scheduled transactions. |
| **Description** | Yes | A short description of the transaction (e.g., "Grocery store", "Monthly rent"). |
| **Amount** | Yes | The transaction amount. Always entered as a positive number; the type field determines the sign. |
| **Type** | Yes | One of **Credit** (money in), **Debit** (money out), or **Transfer** (between accounts). |
| **Account** | Yes | The account this transaction belongs to. Pre-selected if you navigated from an account view. |
| **Category** | No | The spending or income category. Used for budgeting and reports. See [Categories](categories.md). |
| **Vendor** | No | The merchant, payee, or payer name. |
| **Reference** | No | A check number, confirmation code, or other external reference. |
| **Notes** | No | Free-text notes for any additional context. |
| **Exclude from forecast** | No | Marks the transaction as extraordinary/one-time so it doesn't skew [forecast](forecast.md) projections. It still affects your account balance. Excluded transactions show a **No forecast** badge in the list. |

> **Tip:** If you find yourself entering similar transactions repeatedly, use the [duplicate feature](#duplicating-a-transaction) to copy an existing transaction and modify only what changed.

## Editing Transactions

There are two ways to edit a transaction:

- **Inline editing** -- Click a transaction row in the table to expand it and edit fields directly.
- **Modal editing** -- Click the edit button on a transaction to open the full edit modal with all fields visible.

Both methods save changes immediately when you confirm. The edit modal is useful when you need to see all fields at once, while inline editing is faster for quick changes like updating a category or amount.

> **Note:** Transfer transactions have some editing restrictions. The transfer type cannot be changed after creation, and the linked transaction in the other account is updated automatically to keep the pair in sync.

## Transaction Status

Every transaction has a status that affects how it is counted toward your balance:

### Cleared

Cleared transactions represent confirmed activity -- they have occurred and are reflected in your bank statement. These are included in the **current balance** of the account.

### Scheduled

Transactions with a future date are automatically marked as **scheduled**. They appear in the transaction list in italics with a **SCHEDULED** badge and are included in the **projected balance** but not the current balance or the transaction list total.

This distinction lets you enter upcoming bills or expected deposits ahead of time without inflating your current balance. The projected balance (visible in the account detail view) shows what your balance will be once scheduled transactions clear.

Recurring [bills](bills.md) create scheduled transactions automatically: marking a bill paid pre-creates a placeholder for the next occurrence. This placeholder is not a duplicate payment — it becomes the real transaction when that occurrence is paid.

> **Tip:** Use scheduled transactions to plan ahead. Enter your rent, subscriptions, and other recurring payments with their expected dates so the projected balance reflects your true financial picture.

## Filtering and Searching

The transaction list includes a filter panel for narrowing down what you see. Filters are applied immediately as you change them.

### Available Filters

| Filter | Description |
|--------|-------------|
| **Account** | Show transactions from a specific account. |
| **Category** | Filter by spending or income category. |
| **Type** | Filter by credit, debit, or transfer. |
| **Status** | Show all transactions, cleared only, or pending/scheduled only. |
| **Reconciled** | Show all transactions, only reconciled, or only those not yet reconciled. |
| **Date range** | Filter by transaction date (from/to). |
| **Created date range** | Filter by when the transaction was entered into the app (from/to). |
| **Amount range** | Filter by minimum and/or maximum amount. |
| **Tags** | Filter by tag set tags assigned to the transaction. |
| **Text search** | Free-text search across description, vendor, reference, and notes. |

Multiple filters can be combined. Click **Clear Filters** to reset all filters at once.

> **Tip:** The created date filter is useful for reviewing recently entered transactions regardless of their transaction date, which is helpful after a large import.

## Configurable Table Columns

The transaction table columns can be customized to show only the information you care about. Click the **column configuration** button (gear icon) in the table header to toggle columns on and off.

Available columns include date, description, amount, category, vendor, reference, notes, account, type, tags, and running balance. Your column preferences are saved per user and persist across sessions.

> **Note:** At least one column must remain visible. The app prevents you from hiding all columns.

## Bulk Operations

Bulk mode lets you act on multiple transactions at once. Click **Bulk Actions** to enter bulk mode, which adds checkboxes to each transaction row.

### Selecting Transactions

- Check individual transaction checkboxes to select specific transactions.
- Use the **select all** checkbox in the table header to select all visible transactions on the current page.
- The header checkbox shows an indeterminate state when some (but not all) transactions are selected.

### Available Bulk Actions

| Action | Description |
|--------|-------------|
| **Delete** | Permanently delete all selected transactions. |
| **Reconcile** | Mark all selected transactions as reconciled. |
| **Unreconcile** | Remove the reconciled flag from all selected transactions. |
| **Edit** | Apply a category, vendor, reference, or notes value to all selected transactions at once. |

Click **Cancel** to exit bulk mode and deselect all transactions.

> **Warning:** Bulk delete is permanent and cannot be undone. Double-check your selection before confirming.

## Split Transactions

A split transaction allocates a single transaction's amount across multiple categories. This is useful when one purchase covers multiple budget categories -- for example, a supermarket receipt that includes both groceries and household supplies.

### Creating a Split

1. Open an existing transaction and click **Split Transaction**.
2. The split modal shows the total transaction amount at the top.
3. Add split lines, each with a category and an amount.
4. The app tracks the remaining unallocated amount as you add lines.
5. Save the split when the full amount has been allocated.

Split transactions display a split indicator in the transaction list. You can edit or remove splits at any time by reopening the split modal.

> **Note:** The sum of all split amounts must equal the original transaction amount. The app shows the remaining balance as you add each line to help you allocate the full amount.

## Transfer Transactions

Transfers move money between two of your accounts. When you create a transfer, the app creates a pair of linked transactions -- a debit in the source account and a credit in the destination account.

### Creating a Transfer

1. Click **Add Transaction** and set the type to **Transfer**.
2. Select the destination account in the **To Account** dropdown that appears.
3. Enter the amount and other details as usual.
4. The app creates both sides of the transfer automatically.

Transfer transactions display a transfer badge in the transaction list so you can distinguish them from regular credits and debits.

> **Note:** You cannot convert an existing credit or debit transaction into a transfer, or change a transfer into a regular transaction. If you need to correct this, delete the transfer and create the correct transaction type.

> **Warning:** Deleting one side of a transfer does not automatically delete the other side. If you delete a transfer transaction, check the linked account and remove the counterpart manually if needed.

## Duplicating a Transaction

To quickly create a transaction similar to an existing one, use the **Duplicate** action on any transaction. This opens the new transaction form pre-filled with all values from the original transaction (date, description, amount, category, vendor, etc.). Adjust any fields as needed and save.

This is especially useful for recurring expenses that are not exactly the same each time, like variable utility bills or grocery runs.

## Receipt Attachments

Receipts, invoices, and warranty documents can be attached to any saved transaction from the edit dialog's **Receipts** section — upload a new file or pick one already in your Nextcloud Files. Transactions with receipts show a 📎 badge in the list. Files always stay in your own Files space; removing an attachment (or the transaction) never deletes the file. See [Nextcloud Integration](nextcloud-integration.md#receipt-attachments) for details.

## Related Features

- [Accounts](accounts.md) -- Manage the accounts that hold your transactions
- [Categories](categories.md) -- Organize transactions into spending and income categories
- [Tags](tags.md) -- Add additional classification dimensions to transactions via tag sets
- [Importing Bank Statements](import.md) -- Import transactions from CSV, OFX, or QIF files
- [Import Rules](rules.md) -- Automatically categorize transactions based on description or vendor patterns
- [Nextcloud Integration](nextcloud-integration.md) -- Attach receipts from your Files and find transactions in Nextcloud's unified search

## Settings

- **Date format** -- Controls how dates are displayed throughout the app, including the transaction list. Change this in **Settings > General**.
- **Number format** -- Controls decimal and thousands separators for amounts. Change this in **Settings > General**.

# Bills

> Bills track your recurring payments — rent, utilities, subscriptions, loan repayments, and more. The app reminds you when payments are due, automatically advances due dates after each payment, and can even auto-pay bills by creating transactions on their due date.

## Adding a Bill

Navigate to **Bills > Add Bill** to create a new bill.

| Field | Description |
|-------|-------------|
| **Name** | A descriptive name for the bill (e.g., "Electric Bill", "Netflix", "Mortgage"). |
| **Amount** | The expected payment amount. For variable bills, enter your typical or average payment. |
| **Frequency** | How often the bill recurs. See [Frequency Options](#frequency-options) below. |
| **Due Day** | The day of the month (or week) the payment is due. |
| **Start Date** | Optional. The bill only occurs on or after this date — useful when a recurring cost changes mid-year: end one bill and start its replacement at a specific date. |
| **Category** | The spending category to assign when marking paid. |
| **Account** | The account the payment comes from. Required for auto-pay, and needed for "Mark Paid" to record a transaction. |
| **Auto-Detect Pattern** | A text pattern to match imported transactions against this bill (e.g., "NETFLIX" or "ELECTRIC CO"). When an import or bank sync brings in a matching transaction (amount within 10%, dated near the due date), the bill is automatically marked as paid with that transaction linked — no duplicate money movement, and the Bills Calendar ticks the month off. |
| **Exclude from Forecast** | Marks the bill as extraordinary: transactions it generates are left out of [forecast](forecast.md) projections (they still affect your balance). |
| **Notes** | Optional free-text notes about the bill. |

> **Tip:** Setting an auto-detect pattern helps the app automatically recognize bill payments when you import bank statements, saving you from manually marking each one as paid.

## Frequency Options

| Frequency | Description |
|-----------|-------------|
| **Weekly** | Every 7 days on the same weekday. |
| **Biweekly** | Every 14 days (every other week). |
| **Monthly** | Once per calendar month on the due day. |
| **Quarterly** | Every 3 months. |
| **Semi-Annually** | Every 6 months. |
| **Yearly** | Once per year. |
| **One-Time** | A single payment with no recurrence. See [One-Time Bills](#one-time-bills). |
| **Custom** | Select specific months of the year when the bill is due. Useful for bills like property tax or insurance that occur on an irregular schedule. |

## One-Time Bills

One-time bills represent a single expected payment — for example, an annual registration fee or a final installment. After you mark a one-time bill as paid, it automatically deactivates itself. It remains visible in your bill history but no longer appears in the active bills list or generates reminders.

> **Note:** If you need to reactivate a one-time bill, edit it and change the status back to active.

## Auto-Pay

Auto-pay removes the need to manually mark a bill as paid each period. When enabled, the app automatically creates a cleared transaction in the linked account on the bill's due date via a background job.

### How to Enable

1. Edit the bill and toggle **Auto-Pay** on.
2. Ensure the bill has an **Account** set — auto-pay requires a linked account to create the transaction.

### How It Works

- A background job runs daily and checks for bills with auto-pay enabled that are due today.
- When triggered, it creates a transaction in the linked account for the bill's amount and category.
- The bill's due date advances to the next occurrence automatically.
- Bills with auto-pay show an **Auto-pay** badge in the bills list.

### When Auto-Pay Fails

If the background job encounters an error (e.g., the linked account was deleted), the bill displays a warning badge and auto-pay is disabled. You will need to fix the issue and re-enable auto-pay manually.

> **Tip:** Auto-pay works best for fixed-amount bills like subscriptions or loan payments. For variable bills like electricity, consider marking them paid manually after confirming the amount.

## Marking Bills as Paid

Click **Mark Paid** on any bill to record the payment. This:

1. Creates a cleared transaction in the linked account for the bill amount and category, dated today.
2. Advances the due date to the next occurrence based on the frequency.
3. Pre-creates a **scheduled** transaction for the next occurrence, so the upcoming payment is visible in the transaction list and forecasts.
4. Shows an **Undo** notification for approximately 5 seconds, allowing you to reverse the action if clicked accidentally.

> **Note:** The pre-created transaction for the next occurrence is a placeholder, not a second payment. It carries a **Scheduled** badge, is shown in italics, and is not counted toward your current balance or the transaction list total until it is due. When you mark the next occurrence paid, this placeholder becomes the real payment — it is not duplicated. See [Transaction Status](transactions.md#transaction-status) for details.

Marking a bill paid after its due date works the same way: the payment transaction is dated the day you mark it paid, and the due date still advances from the original schedule (a bill due on the 1st stays due on the 1st of the next month, even if you paid on the 2nd).

> **Important:** If the bill has **no account assigned** (or the transaction cannot be created), the bill is still marked paid but **no money movement is recorded** — your account balance will not reflect the payment. The app shows a clear warning when this happens. Assign an account to the bill, or add the transaction manually, so your tracked balance stays in step with your bank.

### Skipping a Payment

Click **Skip** to advance the due date to the next occurrence without creating a transaction. This is useful when a payment is waived, already handled outside the app, or you simply want to skip a period.

> **Note:** Skipping does not create any transaction — it only moves the due date forward.

## Bill Notifications

Set a reminder to be notified a specified number of days before a bill is due. Reminders use the Nextcloud notification system, so they appear in your Nextcloud notification bell and can also be sent as push notifications if you have the Nextcloud mobile app configured.

To configure a reminder, edit the bill and set the **Remind** field to the number of days before the due date you want to be notified (e.g., 3 days before).

## Bills Calendar

The Bills Calendar provides an annual overview of all your recurring bills. Access it from **Reports > Bills Calendar**.

### Views

- **Heatmap** — A color-coded calendar grid showing bill density by month. Darker cells indicate higher total amounts due.
- **Table** — A detailed table listing each bill and which months it is due, with paid months shown in ~~strikethrough~~. Includes a monthly totals row at the bottom.

### Filters

| Filter | Options |
|--------|---------|
| **Status** | Active, Inactive, or All bills. |
| **Account** | Filter to bills linked to a specific account. |
| **Include Transfers** | Toggle whether inter-account transfers are shown. |

> **Tip:** The Bills Calendar is especially useful at the start of the year for understanding your fixed monthly obligations and planning cash flow.

### Calendar Feed Subscription

Bills can also appear in your real calendar — Nextcloud Calendar, your phone, Thunderbird — via a token-authenticated ICS subscription. Click **Calendar feed** in the Bills view to get your subscription URL. See [Nextcloud Integration](nextcloud-integration.md#bills-calendar-feed) for details and security notes.

## Split Templates

For bills that should be divided across multiple categories (e.g., a utility bill split between electricity and water), you can define a split template. When the bill is marked as paid, the resulting transaction is automatically split according to the template's category allocations.

To set up a split template, edit the bill and configure the split categories and their amounts or percentages.

## End Date and Remaining Payments

You can optionally limit a bill's lifespan:

- **End Date** — The bill automatically deactivates after this date, even if it has remaining occurrences.
- **Remaining Payments** — Set a specific number of payments remaining. The count decreases each time the bill is marked paid, and the bill deactivates when it reaches zero.

These options are useful for installment plans, fixed-term subscriptions, or any payment with a known end point.

## Related Features

- [Transactions](transactions.md) — Each paid bill creates a transaction in the linked account.
- [Forecast](forecast.md) — Active bills are factored into balance forecasting.
- [Dashboard](index.md) — The Upcoming Bills tile shows bills due soon.
- [Reports](reports.md) — The Bills Calendar provides annual bill overviews.
- [Accounts](accounts.md) — Bills are linked to accounts for payment tracking.
- [Nextcloud Integration](nextcloud-integration.md) — Subscribe to bills as a calendar feed; see upcoming bills on the Nextcloud dashboard.
- The Bills view also proactively suggests recurring payments that aren't tracked yet — create them in one click or dismiss permanently.

## Settings

Bills do not have dedicated settings. Reminder notifications are delivered through the Nextcloud notification system and respect your Nextcloud notification preferences.

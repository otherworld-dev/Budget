# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- The **Recent Transactions** dashboard tile now honors its **"Rows to show"** setting above 8 — the display respected the setting but the data fetch was hardcoded to 8 rows, so values of 9–20 silently showed only 8

### Added
- **Per-bill opt-out of pre-created transactions.** The **"Create future transaction for this bill"** checkbox is now a persistent bill setting instead of a one-shot option at creation. Bills with it unticked never get a scheduled placeholder — not at creation, not after marking paid, not after skipping — and toggling it on an existing bill adds or removes the pending placeholder immediately. Previously the checkbox only affected the moment a bill was created, while every payment re-enabled pre-booking unconditionally, so unticking it never actually opted a bill out ([#311](https://github.com/otherworld-dev/Budget/issues/311))

- **Convert a transaction into a transfer.** The **Match transfer** dialog gains a **"No match? Create the other side"** section: pick one of your accounts and the app creates the opposite transaction there and links the pair as a transfer. Until now the dialog could only link two *existing* transactions, which left no way to correctly book payments to accounts that have nothing to import — e.g. a loan tracked as its own account ([#313](https://github.com/otherworld-dev/Budget/issues/313))

### Changed
- Scheduled (future-dated) transactions now show a **Scheduled** badge in the main transaction list, with a tooltip explaining they are not counted until they occur. Previously only the account detail view had the badge and the main list showed scheduled rows in italics alone, which made a bill's pre-created next occurrence easy to mistake for a duplicate booking ([#311](https://github.com/otherworld-dev/Budget/issues/311))
- The transaction list **Total** no longer includes scheduled transactions — they are placeholders for money that has not moved yet ([#311](https://github.com/otherworld-dev/Budget/issues/311))

## [2.37.0] - 2026-06-28

### Added
- **Exclude shared accounts from reports.** When other people have shared accounts with you, the **Reports** page gains an **"Exclude shared accounts"** toggle so you can see just your own accounts' figures. The same option is available per-tile (in the gear menu) on the account-based dashboard cards — **Income vs Expenses**, **Spending by Category**, **Top Spending Categories** and **Recent Transactions**. It's off by default (shared accounts are included, as before) and only appears when you actually have shared accounts. Note: a transfer between one of your accounts and a shared account can only be partly seen when shared accounts are excluded, so income/expense totals may be slightly overstated in that case.

### Fixed
- Saving share settings failed with **"Some entities do not belong to you"** (HTTP 400) when the **"Auto-share new"** toggle was enabled for a type, or after using **Select All** — the auto-share toggle (which shares the same `data-type` as the entity checkboxes) was being collected as a phantom entity and sent as a null id. Entity selection is now scoped precisely, so share settings save correctly with auto-share on ([#306](https://github.com/otherworld-dev/Budget/issues/306))

### Changed
- The **Categories** page now also lists categories that have been shared with you, shown read-only with a **"Shared · &lt;owner&gt;"** badge. Previously it showed only your own categories, so anyone using a budget made up entirely of shared categories saw an empty page even though the categories worked everywhere else ([#306](https://github.com/otherworld-dev/Budget/issues/306))
- Categories shared with you at **Edit** permission are now **editable** — rename them and change their colour straight from the Categories page (they carry a **"Shared (editable) · &lt;owner&gt;"** badge). Read-only shares stay read-only, and a category's type, parent, budget, report visibility and deletion all remain owner-only ([#306](https://github.com/otherworld-dev/Budget/issues/306))

## [2.36.0] - 2026-06-27

### Added
- **Auto-share new entities.** Each person you share with now has an **"Auto-share new"** toggle per entity type (accounts, categories, bills, recurring income, savings goals) in their sharing settings. Turn it on and anything you create from then on is shared with them automatically, at the permission you choose — no more sharing each new bill or category by hand ([#306](https://github.com/otherworld-dev/Budget/issues/306))

### Fixed
- Code-integrity **"Some files have not passed the integrity check"** errors reporting `FILE_MISSING` for `js/.htaccess` and `vendor/tecnickcom/tcpdf/tools/.htaccess`. The app no longer ships any `.htaccess` files — the Nextcloud App Store strips them from the published archive, which left the signature expecting files that were no longer present. Neither file was needed (the JS one only set a redundant cache header) ([#307](https://github.com/otherworld-dev/Budget/issues/307))
- Deprecation warning in the Nextcloud log — the notifier now throws `UnknownNotificationException` instead of `InvalidArgumentException` for unrecognised notifications, as required by Nextcloud 30+ ([#308](https://github.com/otherworld-dev/Budget/issues/308))
- Reports and the dashboard summary were empty when a single account flagged **"Exclude from reports"** was the only account selected. Accounts flagged out of reports are now only dropped from the **all-accounts** views; explicitly selecting one shows its data as normal ([#309](https://github.com/otherworld-dev/Budget/issues/309))

## [2.35.1] - 2026-06-25

### Fixed
- Categories marked **"Exclude from reports"** are now consistently excluded everywhere, including the **Income vs Expenses** trend, **Category Trends**, **Top Spending Categories** and the monthly spending chart — not just the dashboard summary. Exclusion is now applied in the database query itself, so reports and dashboard insight tiles can no longer disagree about which categories count ([#219](https://github.com/otherworld-dev/Budget/issues/219))

## [2.35.0] - 2026-06-25

### Added
- **Pensions revamp.** You can now record a contribution as a transfer **from a bank account** — the matching withdrawal is created automatically and kept out of your spending — and record **withdrawals/drawdown** back into an account ([#304](https://github.com/otherworld-dev/Budget/issues/304)). **Scheduled (recurring) contributions** (monthly/quarterly/yearly) can post automatically or on demand ([#251](https://github.com/otherworld-dev/Budget/issues/251)). DC pensions gained a configurable **projection target** with a progress indicator and a **"today's money"** (inflation-adjusted) view on the growth chart, plus an optional **Pension Projection** dashboard card. Bank legs that fund a pension show a **Pension** badge in the transaction list.

### Fixed
- Background-job "class does not exist" log spam that could appear after updating PHP/Nextcloud/the app: the app's autoloader is no longer "authoritative", so it falls back to PSR-4 and self-heals if PHP's cached class map is stale after an update ([#305](https://github.com/otherworld-dev/Budget/issues/305))
- Newly-added dashboard widgets now load their data immediately instead of staying empty until the page is refreshed.
- The pension detail panel's **balance history** and **activity** are no longer broken — those views called endpoints that didn't exist; they now show a proper balance chart and a merged timeline of contributions, withdrawals and balance updates, each removable ([#251](https://github.com/otherworld-dev/Budget/issues/251))
- Editing a split transaction with three or more parts no longer zeroes or overwrites the other splits. The last split row is now a read-only "remaining" amount (transaction total minus the other parts), and the dialog blocks saving until the parts add up ([#303](https://github.com/otherworld-dev/Budget/issues/303))
- Spending-anomaly notifications no longer crash with "The arguments array must contain 1 items, 0 given" — a stray `%` in the notification text made Nextcloud's translation layer fail, spamming the log every time notifications were rendered ([#305](https://github.com/otherworld-dev/Budget/issues/305))

## [2.34.0] - 2026-06-24

### Added
- The transaction list filters now include a **Reconciled** filter (All / Reconciled / Not reconciled), so you can quickly see which transactions still need reconciling ([#301](https://github.com/otherworld-dev/Budget/discussions/301))
- A **Budget alert threshold** setting controls when a category appears on the Budget Alerts tile (default 80% of budget). Set it to 100% to be alerted only when a category actually goes over budget, rather than when it's merely approaching or fully used ([#293](https://github.com/otherworld-dev/Budget/issues/293))

### Fixed
- Creating a category (or account) no longer fails with "Unknown column 'excluded_from_reports'" on instances where an earlier upgrade was interrupted or restored from a mid-upgrade backup. A self-healing migration re-adds the affected columns automatically on update ([#302](https://github.com/otherworld-dev/Budget/issues/302))

## [2.33.1] - 2026-06-18

### Fixed
- On some installs an earlier update was recorded as applied but didn't fully run (an interrupted upgrade, or a database restored from a backup taken mid-upgrade), leaving the `excluded_from_forecast` column missing — so every new transaction failed with "Unknown column 'excluded_from_forecast'". A migration now re-adds the column automatically on update. (Also surfaced by the new database-error `detail` field added in 2.32.0.) ([#289](https://github.com/otherworld-dev/Budget/issues/289))

## [2.33.0] - 2026-06-18

### Added
- **Run reports on multiple accounts at once**: the report Account filter is now a checklist — pick any set of accounts (e.g. all your credit cards, or all your checking accounts) or "All Accounts". Applies across the summary, spending, income, cash-flow, category-by-month and year-over-year reports and their CSV/PDF exports ([#299](https://github.com/otherworld-dev/Budget/issues/299))
- **Saved reports**: save a report's settings (type, period, selected accounts, tags and options) under a name and re-run it later from the "Saved Reports" picker in the Reports view. The period is stored as the chosen preset (e.g. "Last 3 months") so a saved report always shows up-to-date data ([#299](https://github.com/otherworld-dev/Budget/issues/299))
- Dashboard tiles that can be scoped to a specific account (Spending by Category, Income vs Expenses, Net Worth History, Recent Transactions, and the other account-aware widgets) now show a small label in their header naming the account they're analyzing — the account you picked in the tile's settings, or "All accounts" ([#296](https://github.com/otherworld-dev/Budget/issues/296))

### Fixed
- The Reports period selector didn't drive the report on reopen: the dropdown kept your last choice (e.g. "Last Year") but the report always reverted to "Last 3 Months", and clicking "Generate Report" used the reverted range instead of the shown period — you had to switch the dropdown away and back. The report now always matches the period shown in the selector (custom ranges are preserved) ([#300](https://github.com/otherworld-dev/Budget/issues/300))
- The Quick Add Transaction dashboard widget couldn't be used on mobile — the lower fields and the "Add Transaction" button were clipped off the bottom of the tile, so a transaction could never be submitted. Its form is now condensed (each label sits beside its field with tighter spacing) so the whole form fits the tile, and it falls back to scrolling within the tile if it's resized very small ([#298](https://github.com/otherworld-dev/Budget/issues/298))
- Editing a split transaction's amount (e.g. inline in the transaction list) left its splits at the old amounts, so they no longer summed to the transaction total. The splits are now rescaled proportionally to the new amount (the last split absorbs any rounding remainder)
- In the edit-transaction dialog, changing one split amount now adjusts another split so the parts keep summing to the transaction total, instead of silently drifting out of balance
- Split transactions weren't counted toward category budgets or spending. When a single transaction was split across categories (e.g. one $1,000 payment split into rent, parking and a deposit), each of those categories showed $0 spent. Split allocations now count toward category spending everywhere it's shown — the Budget view, the Spending by Category chart, and reports ([#297](https://github.com/otherworld-dev/Budget/issues/297))
- Large amounts (e.g. values over 100,000) on the dashboard hero tiles (Net Worth, Income/Expenses This Month, Net Savings, Cash Flow) wrapped onto a second line, splitting the number mid-digits. The amount now stays on one line and its font scales down to fit narrow tiles instead of wrapping ([#295](https://github.com/otherworld-dev/Budget/issues/295))
- The "Asset Value History" dashboard tile (and "Net Worth History") showed its empty-state message anchored near the bottom of the tile instead of vertically centered. When there's no data the message now fills and centers within the chart area ([#294](https://github.com/otherworld-dev/Budget/issues/294))

## [2.32.0] - 2026-06-17

### Added
- **Category by Month report**: a new report (Reports → Category by Month) showing income and expenses per category broken down by month, with a column per month and an overall total. Categories are listed alphabetically with sub-categories grouped under their parents, and **parent rows include the totals of their sub-categories**; a toggle switches to sorting by total instead. Defaults to year-to-date, follows the report date-range and account filters, and exports to CSV and PDF ([#288](https://github.com/otherworld-dev/Budget/discussions/288))

### Changed
- API errors caused by the database now include the underlying driver message (e.g. a missing column) in a separate `detail` field of the error response, visible in the browser's network tab. The user-facing message is unchanged; this makes problems diagnosable on managed Nextcloud instances where admins can't read the server log. The executed SQL and any bound values are stripped from the detail ([#289](https://github.com/otherworld-dev/Budget/issues/289))

### Fixed
- The dashboard "Budget Alerts" tile showed a red "exceeded" alert (with "0% over") for a category whose spending exactly equalled its budget. Spending that fully uses the budget without going over is now treated as a "100% used" warning, not an over-budget alert; only spending that actually exceeds the budget shows as exceeded ([#293](https://github.com/otherworld-dev/Budget/issues/293))
- PDF reports rendered non-Latin characters (Polish, Cyrillic, etc.) in account/category names as "?". The PDF exports used a built-in font limited to Western European characters; they now use an embedded Unicode font (DejaVu Sans), so names display correctly. Applies to the budget report, bills-calendar and year-over-year PDFs ([#292](https://github.com/otherworld-dev/Budget/issues/292))
- Marking a recurring transfer as paid showed a success message but created no account entries. The Transfers screen was only updating the transfer's "last paid" date instead of calling the mark-paid action, so no transactions were recorded; it now creates the paired transfer transactions like the Bills screen does ([#291](https://github.com/otherworld-dev/Budget/issues/291))

## [2.31.0] - 2026-06-14

### Added
- **Exclude an account from totals**: a per-account "Exclude from reports, dashboard & forecast" option (in the account's edit dialog) keeps the account fully tracked but leaves its transactions and balance out of every "all accounts" aggregation — reports, the dashboard, forecast, net worth, total balance and category budgets. The account still appears in your accounts list (with an "Excluded" badge) and on its own detail page, and selecting it explicitly in a report still works ([#286](https://github.com/otherworld-dev/Budget/issues/286))

### Fixed
- The account detail page's overview tiles only counted the transactions on the visible page (e.g. "50" for an account with 1,603). "Total Transactions", "This Month Income", "This Month Expenses" and "Avg. Transaction" are now aggregated server-side over the whole account, so they're correct regardless of paging ([#285](https://github.com/otherworld-dev/Budget/issues/285))
- The dashboard spending chart's "Total Spending" figure (and each category's percentage) only summed the top 10 categories shown in the chart. It now reflects spending across all categories
- The CSV import preview's "Auto-categorized" count was estimated from a 50-row sample and could be wrong for larger imports; it's now counted over the full file on the server
- Editing an existing bill failed with "Failed to update bill" — saving the edit dialog (whether or not anything changed) returned a 400 error. The bill's start-date column, added with the limited-term bills feature, was missing from the list of updatable columns, so the always-sent start date was rejected before the save could run. Bills now save correctly, with a test guarding every persisted column ([#284](https://github.com/otherworld-dev/Budget/issues/284))
- Opening an account's detail page returned a 500 error (the reconciliation-history request failed). The reconciliation controller was missing a trait it relied on, so it couldn't be constructed and every reconciliation endpoint failed; the account page surfaced it via the history sidebar. Now fixed and covered by tests ([#283](https://github.com/otherworld-dev/Budget/issues/283))
- Starting an account reconciliation failed with a confusing "The string did not match the expected pattern" message (most visibly on Safari). The root cause was the reconciliation controller failing to construct (the [#283](https://github.com/otherworld-dev/Budget/issues/283) fix above resolves it); on top of that, the reconciliation screens now read error responses defensively, so any failure shows a clear message with the HTTP status instead of an opaque JSON-parsing error ([#287](https://github.com/otherworld-dev/Budget/issues/287))
- The Budget Overview dashboard widget (and the digest, spending-anomaly and bill-reminder notifications) always showed amounts in US dollars regardless of your configured currency. The server-side amount formatter was reading the wrong settings key and falling back to USD; it now uses your default currency
- The Budget Overview dashboard widget's "Total balance" now converts multi-currency accounts to your base currency instead of summing different currencies under one symbol (currencies without an exchange rate are excluded from the total and named)

## [2.30.2] - 2026-06-13

### Fixed
- Dropdowns in the transaction list (the column-configuration gear menu and the in-cell category/tag/account editors) were cut off when the table had only a few rows, because the table area was shorter than the dropdowns. The transaction table now fills the available height, so every dropdown has room to open in full ([#280](https://github.com/otherworld-dev/Budget/issues/280))
- The Tags column couldn't be shown/hidden — the Show/Hide Columns menu was missing a Tags entry, and the column wasn't wired into the visibility logic. Tags can now be toggled like the other columns ([#281](https://github.com/otherworld-dev/Budget/issues/281))

## [2.30.1] - 2026-06-13

### Fixed
- Adding a detected recurring bill (the **Detect Bills** results, and the new suggestions card) failed with a 500 error. `createFromDetected` passed misaligned positional arguments into bill creation — a value landed in the wrong parameter slot and threw a `TypeError` for every frequency. The call now uses named arguments so it can't drift, and numeric fields are coerced defensively ([#278](https://github.com/otherworld-dev/Budget/issues/278))
- Cancelling bulk actions in the transaction list left the table misaligned (header columns shifted relative to rows) until a page reload. The checkbox column header was being hidden while the regenerated rows kept theirs; the column is now consistently always present and cancelling unchecks rows in place instead of reloading ([#279](https://github.com/otherworld-dev/Budget/issues/279))

## [2.30.0] - 2026-06-13

### Added
- **Envelope (rollover) budgets**: per-category toggle on the Budget view — unspent budget carries into the next month's available amount, and overspend carries as a deficit. The carried amount is always derived from your actual budgets and spending (editing a past transaction recomputes every later month automatically), shown as "300 + 45 carried = 345" on the budget row and included consistently in alerts, the dashboard and reports. Monthly expense categories; carryover starts the month you enable it
- **Statement reconciliation sessions**: reconciling an account is now a real statement workflow — enter the statement's ending balance and date, tick transactions off as you match them, and watch the difference fall to zero live (anchored on your previous reconciliation, not the account's current balance). Sessions persist (leave and resume later), completed reconciliations build a per-account history, and editing or deleting a reconciled transaction warns first and leaves an audit trail
- **Proactive recurring-bill suggestions**: the Bills view now surfaces payments that look recurring but aren't tracked yet (confidence-scored, ignoring transactions already linked to bills and patterns matching existing bills) — create the bill in one click or dismiss it permanently
- **Weekly/monthly digest**: opt-in summary of income vs spending, budget status, upcoming bills, goal progress, unusual spending and new bill suggestions — as a Nextcloud notification, optionally also as a themed email. Period-aware scheduling never double-sends and self-heals after cron downtime
- **Unusual spending alerts**: a notification when a category runs well above its 6-month typical level (median-based, pro-rated for the day of month, minimum-amount floor, max one alert per category per month; threshold tunable in Settings)
- **Scheduled monthly PDF reports**: the previous month's summary report delivered automatically — saved into `Budget/Reports/<year>/` in your Files and/or emailed as an attachment, each channel independently toggleable; a notification links to the file when it lands

## [2.29.0] - 2026-06-12

### Added
- **Nextcloud dashboard widgets**: "Upcoming bills" (next five bills due within 60 days, with overdue/due-today wording) and "Overview" (total balance, this month's budget progress, and an attention line for over-budget categories) — enable them via Customize on the Nextcloud dashboard
- **Unified search**: transactions (description, vendor, notes — own and shared accounts) now appear in Nextcloud's global search bar; clicking a result opens the Transactions view with the search pre-filled
- **Bills calendar feed**: subscribe to your bills from Nextcloud Calendar, phones, or Thunderbird via a token-authenticated ICS feed (Bills > Calendar feed). Occurrences are materialized 12 months ahead — including custom recurrence patterns, semi-monthly schedules, end dates and remaining-payment limits — and bill reminders become calendar alarms. The token can be regenerated at any time; failed token guesses are rate-limited and brute-force protected
- **Receipt attachments**: attach receipts/invoices (JPEG, PNG, WebP, HEIC, PDF — up to 25 MB) to transactions from the edit dialog — upload into `Budget/Receipts/<year>/` or pick an existing file from your Files. Files stay in your own Files space (quota, backups, ownership); the app stores only a reference and never deletes files. Transactions with receipts show a paperclip badge; image attachments get thumbnails; renames/moves in Files are followed automatically and deleted files are shown as missing instead of breaking
- Deep links into the app (`#/transactions`, `#/bills`, …) are now honored on page load, including a pre-filled transaction search (`#/transactions?search=…`)

### Fixed
- **Bill auto-detect patterns now actually work** ([#274](https://github.com/otherworld-dev/Budget/issues/274)): the bill form has always offered a "pattern to match in transaction descriptions", but no import path ever ran the matcher. Imports and bank syncs now mark a bill as paid when a transaction matches its pattern (amount within 10%, account agreement, dated within the bill's due window) — the transaction is linked, never duplicated, the due date advances, and the Bills Calendar ticks the month off. Same-period duplicates and historical re-imports cannot double-advance a bill; the import summary reports how many bills were marked paid
- Transaction search is now case-insensitive on PostgreSQL and SQLite (plain `LIKE` is case-sensitive there — searching "rent" missed "Rent"; MySQL/MariaDB installs were unaffected)
- Bank sync connections no longer get permanently stuck after a failed sync: one transient provider failure (bridge outage, lapsed SimpleFIN subscription, network error) set the connection to an error state that every later sync — manual and the daily background job — refused to touch ("Connection is not active"), with no path back. Failed connections are now retried, so they self-heal once the provider recovers, and the real provider error is surfaced instead of the status gate ([#277](https://github.com/otherworld-dev/Budget/issues/277))

## [2.28.3] - 2026-06-12

### Security
- The recurring-income update endpoint no longer passes the raw request body through to the entity layer — a crafted payload could previously set internal fields such as the owning user (mass assignment, integrity only — no data disclosure). Update keys are now allowlisted like the other endpoints

### Fixed
- Auto-derived recurring budgets (#269) are now consistent across **all** surfaces: the dashboard "Budget Remaining" hero, Budget Progress/Breakdown widgets, Budget Health score, and **budget alerts** now apply the same recurring fallback as the Budget view — a category funded only by recurring bills can now trigger alerts and is counted in headline totals
- Auto-derived budgets now respect bill start/end dates: a bill that hasn't started (or has ended) no longer counts toward today's budget, avoiding double-counting during a planned cost change ([#268](https://github.com/otherworld-dev/Budget/issues/268))
- Recurring income summaries (Income view header, dashboard Income Tracking widget): semi-monthly income was counted at half its value, and a one-time income at full value every month until received
- Transfers page monthly total: semi-monthly and semi-annual transfers were converted incorrectly
- Live forecast cache: cache invalidation never matched the stored key (changes such as toggling "exclude from forecast" appeared to do nothing for up to 5 minutes), and the cache key ignored shared-account visibility, letting a share-restricted viewer briefly see the owner's totals
- Editing a bill's amount without updating its split template is now rejected when the stored splits no longer match (the payment would have silently imported unsplit and uncategorized); setting a split template via the API now clears the bill-level category, matching creation
- Multi-account import partial failures (e.g. a deleted destination account) are now reported in the UI instead of masquerading as a full success
- Import preview now also flags repeated bank transaction IDs for accounts that will be created by the import
- Long OFX FITID hashing threshold raised so every previously-imported FITID keeps its exact ID (re-import dedup continuity)
- Auto-derived category budgets ([#269](https://github.com/otherworld-dev/Budget/issues/269)) now handle every supported frequency correctly: semi-annual bills were over-counted 6×, semi-monthly halved, custom-schedule bills counted every month, and one-time bills inflated the budget monthly until marked paid (now excluded). Frequency math is delegated to the shared calculator, which also gained the missing semi-monthly conversion
- Auto-derived budgets no longer apply to past months — they reflect today's recurring bills/income and were rewriting history (including overriding explicitly-zeroed snapshot budgets)
- Multi-account import: an invalid destination account mid-import no longer aborts the batch, and deferred balance recomputes now always run for already-imported rows
- Bank sync: the post-import balance recompute now keys on transaction creation, not the import counter, so a row persisted just before a non-fatal error still gets its balance update
- The scheduled-transactions background job now recomputes balances from the ledger (it was the last remaining hand-computed balance delta, and could race concurrent imports)
- Import preview now flags a repeated bank transaction ID (OFX FITID) within one file as the duplicate it is, matching what import actually does; per-account preview counts include rows that will import with skip-duplicates off
- Marking a bill paid by linking an existing transaction now warns if the link silently failed (no money movement recorded)
- Extremely long OFX FITIDs are hashed to stay within the import-ID column

## [2.28.2] - 2026-06-11

### Fixed
- Dashboard could render half-empty (hero tiles only, widget grid invisible): a Chart.js resize on a hidden tile's detached canvas threw during initialization, and the widget grid was never revealed. Chart resizing now skips detached canvases and the grid is revealed before the resize pass. Likely also behind "nothing happens" reports after certain actions ([#273](https://github.com/otherworld-dev/Budget/issues/273))
- Dashboard tiles with a saved account selection pointing at a deleted account (e.g. after factory reset + import re-numbered accounts) no longer error with 404s and stay empty — stale selections fall back to all accounts
- **Account balances are now derived from the ledger instead of running deltas** — the root cause behind every historical balance-discrepancy report (#3, #89, #124, #163, #187, #194, #274). The stored balance was a running total that each transaction code path adjusted by hand; any missed or mis-signed delta corrupted it permanently. Every transaction create/update/delete (and retroactive rule run, repair action, import and bank sync) now recomputes `balance = opening balance + net of non-scheduled transactions`, making drift impossible and self-healing past inconsistencies on the next write. A one-time migration backfills the opening balance so **displayed balances do not change on upgrade** ([#274](https://github.com/otherworld-dev/Budget/issues/274))
- Marking a bill as paid now warns loudly when **no transaction was recorded** (bill without an account, or transaction creation failed) — previously the bill was advanced silently and the account balance never reflected the payment, drifting from the real bank balance every cycle ([#89](https://github.com/otherworld-dev/Budget/issues/89), [#274](https://github.com/otherworld-dev/Budget/issues/274))
- The app's own export→import now preserves transaction **status** (scheduled transactions no longer silently become balance-affecting after a migration) and restores the opening-balance invariant for imported accounts
- "Skip Duplicate Transactions" off is now honored: previously rows whose import ID already existed were still rejected deeper in the stack (duplicate-ID guard + unique index), so parts of the batch silently failed to import. Intentional duplicates now get a uniquified import ID and the entire batch imports ([#275](https://github.com/otherworld-dev/Budget/issues/275))
- Import duplicate detection no longer gives false positives for legitimately identical rows — e.g. two same-priced purchases on the same day with the same description. Identical rows within one file now import as distinct transactions (occurrence-aware import IDs), while re-importing the same statement still skips everything it already imported. Re-importing an older statement also recovers transactions the previous logic wrongly skipped ([#276](https://github.com/otherworld-dev/Budget/issues/276))

## [2.28.1] - 2026-06-07

### Fixed
- Install/upgrade aborted on **Nextcloud 32 and earlier** with *"Primary index name on `oc_budget_import_templates` is too long"*. The `budget_import_templates` migration created its primary key without an explicit name, so Nextcloud derived a default name and hit the Oracle-compatibility identifier limit (the unprefixed table name is exactly 23 characters). The primary key now has an explicit short name. Thanks to @TheUnderdev for the report and fix ([#272](https://github.com/otherworld-dev/Budget/pull/272))

## [2.28.0] - 2026-06-07

### Added
- **Shared savings goals** — share a savings goal with another Nextcloud user. Recipients see a "Shared" badge and can view progress; with write permission they can edit the goal and add money, while only the owner can delete it ([#255](https://github.com/otherworld-dev/Budget/issues/255))
- **Auto-derived budgets from recurring items** — a (sub-)category with no manually-set budget now shows its committed recurring total (active recurring bills + recurring income, normalized to the budget period) as an automatic budget limit, marked "auto". Typing a value always overrides it ([#269](https://github.com/otherworld-dev/Budget/issues/269))
- **Exclude extraordinary/one-time items from the forecast** — a per-transaction "exclude from forecast" flag (with a "No forecast" list badge), the same flag on recurring bills/income (propagated to generated transactions), and a matching import-rule action. Flagged amounts still affect your balance but are kept out of the projection averages ([#270](https://github.com/otherworld-dev/Budget/issues/270))
- **Reusable CSV import templates** — save column mappings and import options and reapply them to future imports ([#247](https://github.com/otherworld-dev/Budget/issues/247))
- **OFX/QIF import account routing** — route multi-account OFX/QIF files to the right Budget accounts, remembered across subsequent imports
- **Bank sync: pending transactions** — option to import not-yet-posted (pending) transactions, automatically reconciled when they clear ([#257](https://github.com/otherworld-dev/Budget/issues/257))
- **Bill start dates** — a bill only occurs on or after its start date, making it easy to change a recurring cost mid-year by ending one bill and starting another ([#268](https://github.com/otherworld-dev/Budget/issues/268))
- **"Shared with me" expenses view** — recipients can now see expenses that have been shared with them ([#248](https://github.com/otherworld-dev/Budget/issues/248))
- **Dashboard period indicator** — chart tiles now show a read-only indicator of the period they cover ([#250](https://github.com/otherworld-dev/Budget/issues/250))

### Fixed
- Dashboard now collapses to a single full-width column on phones, independent of your configured desktop column count ([#249](https://github.com/otherworld-dev/Budget/issues/249))
- "Excluded from reports" categories no longer appear in the Budget view ([#266](https://github.com/otherworld-dev/Budget/issues/266), [#267](https://github.com/otherworld-dev/Budget/issues/267))
- Category spending chart shows income as positive with the correct label ([#265](https://github.com/otherworld-dev/Budget/issues/265))
- Tag-linked savings goals now sum net realized contributions (credits minus debits, excluding scheduled) ([#264](https://github.com/otherworld-dev/Budget/issues/264))
- Recurring income description is now persisted on create ([#263](https://github.com/otherworld-dev/Budget/issues/263))
- Transfers are now excluded from dashboard income/expense totals ([#262](https://github.com/otherworld-dev/Budget/issues/262))
- Account Breakdown report now uses each account's own currency instead of a single assumed one ([#256](https://github.com/otherworld-dev/Budget/issues/256))
- Fixed a PageController crash (TypeError) on unauthenticated requests ([#259](https://github.com/otherworld-dev/Budget/issues/259))
- Quick Add date field normalization on iOS ([#235](https://github.com/otherworld-dev/Budget/issues/235))

### Docs
- Documented the standalone Quick Add page and the iOS home-screen workaround
- Noted that GoCardless no longer accepts new bank-sync sign-ups; EU/UK self-hosters without an existing account are pointed to manual import ([#271](https://github.com/otherworld-dev/Budget/issues/271))

## [2.27.8] - 2026-06-02

### Added
- **Cross-currency transfer support** — Transfers between accounts with different currencies now show a destination amount field, auto-filled from exchange rate data and user-editable. New `/api/exchange-rates/convert` endpoint for rate lookups ([#254](https://github.com/otherworld-dev/Budget/issues/254))

### Fixed
- Factory reset fails on PostgreSQL — DELETE with JOIN not supported, replaced with select-then-delete pattern ([#253](https://github.com/otherworld-dev/Budget/issues/253))
- CSV import `str_getcsv` deprecation warning on PHP 8.4 — added explicit escape parameter ([#252](https://github.com/otherworld-dev/Budget/issues/252))
- Deleting a linked transfer now unlinks the counterpart first, preventing dangling references that break the dashboard ([#254](https://github.com/otherworld-dev/Budget/issues/254))
- Pension detail panel crash — missing DOM elements for type/provider/value fields
- Pension detail panel not showing — `display:none` not being overridden
- Compiled German translations from Weblate

## [2.27.7] - 2026-05-31

### Fixed
- Shared account users can now delete, update, and split transactions on shared accounts ([#245](https://github.com/otherworld-dev/Budget/issues/245))
- Categorized transfers involving credit cards now correctly handled in hero tile totals — liability transfers are no longer skipped by the excluded-category deduction ([#243](https://github.com/otherworld-dev/Budget/issues/243))
- Quick Add page: improved link contrast, consistent date field sizing on iOS, added web app meta tags ([#235](https://github.com/otherworld-dev/Budget/issues/235))

## [2.27.6] - 2026-05-30

### Fixed
- Hero tiles double-subtracting categorized transfers — transfers with an "exclude from reports" category had their amount deducted twice ([#243](https://github.com/otherworld-dev/Budget/issues/243))
- "Mark bill as paid" crashes with TypeError when matching transactions are found — missing settings parameter in currency formatter ([#222](https://github.com/otherworld-dev/Budget/issues/222))
- Quick Add standalone page not scrollable on mobile — Nextcloud's `#content` overflow:clip now overridden ([#235](https://github.com/otherworld-dev/Budget/issues/235))

## [2.27.5] - 2026-05-30

### Fixed
- Migration crash on update: `renameTable()` does not exist in Nextcloud's SchemaWrapper. Replaced with create + copy + drop pattern ([#242](https://github.com/otherworld-dev/Budget/issues/242))

## [2.27.4] - 2026-05-30

### Added
- **Semi-Monthly frequency** — Twice-per-month option (e.g., 1st and 16th) for bills, income, and transfers. Distinct from bi-weekly (every 14 days) ([Discussion #239](https://github.com/otherworld-dev/Budget/discussions/239))
- **Quick Add standalone page** — Minimal transaction entry page at `/apps/budget/quick-add` for mobile use. Discoverable via Settings (copyable URL) and dashboard tile link ([#235](https://github.com/otherworld-dev/Budget/issues/235))

### Fixed
- Fatal error when applying import rules with account filter — wrong `IQueryBuilder` namespace ([#240](https://github.com/otherworld-dev/Budget/issues/240))
- Month labels showing wrong month on the 30th/31st of the month (DateTime overflow from Feb/Apr/Jun etc.)
- Database migration error on MariaDB — `budget_dismissed_imports` table name too long with `oc_` prefix ([#241](https://github.com/otherworld-dev/Budget/issues/241))
- CI workflow now validates migration table/index name lengths automatically

## [2.27.3] - 2026-05-29

### Fixed
- **Shared accounts now included across all features** — Reports (spending, income, cashflow, tags), forecasts, net worth, and category transaction counts now include data from shared accounts ([#215](https://github.com/otherworld-dev/Budget/issues/215))
- Quick-add page dropdowns now show shared accounts and categories
- 18 TransactionMapper query methods updated to support shared account filtering via `visibleAccountIds`

## [2.27.2] - 2026-05-29

### Added
- **Dismissed Imports** — Deleted bank sync transactions are remembered and won't be re-imported on the next sync ([#230](https://github.com/otherworld-dev/Budget/issues/230))
- **Force Re-sync** — New "Force" button on bank sync connections to re-import previously dismissed transactions ([#230](https://github.com/otherworld-dev/Budget/issues/230))
- Changing or clearing an account mapping automatically clears the dismissed list so re-sync works cleanly

### Fixed
- Categories marked "Exclude from reports" now actually excluded from spending and income reports — filter was checking wrong key ([#219](https://github.com/otherworld-dev/Budget/issues/219))
- System Info diagnostics no longer shows expected 403 errors for non-admin users ([#236](https://github.com/otherworld-dev/Budget/issues/236))

### Changed
- Updated documentation for v2.27 features (rules, settings, dashboard, sharing, bank sync)
- Removed outdated password protection documentation

## [2.27.1] - 2026-05-29

### Fixed
- Dashboard summary now includes shared accounts in balance totals, currency breakdown, and account count ([#215](https://github.com/otherworld-dev/Budget/issues/215))
- SimpleFIN sync now passes `start-date` parameter — fixes credit card accounts returning zero transactions ([#230](https://github.com/otherworld-dev/Budget/issues/230))
- System Info panel no longer fails with DbalException due to wrong column names in sharing query ([#236](https://github.com/otherworld-dev/Budget/issues/236))
- Recompiled all l10n files — Russian translation now fully available, Polish language added ([#237](https://github.com/otherworld-dev/Budget/issues/237))

## [2.27.0] - 2026-05-29

### Added
- **Transaction Type Rule Criteria** — Rules can now filter by Income or Expense transaction type ([#229](https://github.com/otherworld-dev/Budget/issues/229))
- **Auto-Link as Transfer Action** — New rule action that automatically finds a matching opposite transaction and links them as a transfer. Works in manual rule application, CSV import, and bank sync ([#229](https://github.com/otherworld-dev/Budget/issues/229))
- **System Info Diagnostics** — Settings panel showing data stats, server info, and recent logs for troubleshooting ([#228](https://github.com/otherworld-dev/Budget/issues/228))
- **Inline Split Transactions** — View and edit transaction splits directly in the transaction modal
- **GitHub Actions CI** — Automated linting and test pipeline on push
- **149 New Tests** — Comprehensive unit tests for previously untested services and controllers
- Compiled translations for Polish, Russian, and German

### Fixed
- Dashboard rendering polish and cross-browser consistency
- Bank sync diagnostic logging and SimpleFIN pending transaction date fix (posted=0 → correct date)
- Undefined `transferCatCount` variable in repair findings
- JS lint errors and warnings cleaned up across entire codebase

### Changed
- Replaced 830-line manual dependency injection with Nextcloud autowiring
- Extended `AbstractCrudService` in 3 more services (Goals, Pensions, Assets)
- Removed password protection backend entirely (non-functional legacy feature)
- Improved security: user scoping on `findByAccount`, input validation, deduplicated router switches

## [2.26.3] - 2026-05-27

### Changed
- Removed non-functional password protection UI and related auth module
- Removed unused `RequiresAuthTrait` and custom API wrapper (`api.js`)
- Cleaned up security and code quality issues from codebase review

## [2.26.2] - 2026-05-27

### Fixed
- Transfer counterpart transaction now inherits the selected category instead of being set to Uncategorized (#220)
- Categories marked "Exclude from reports" now properly excluded from spending and income reports (#219)
- Dashboard tile settings (account, date range) now persist and load correctly on page reload (#218)
- Duplicate tile instances now restored from saved config on reload (#218)
- One-time bills no longer incorrectly shown as "paid" after changing frequency from recurring (#222)
- Rule group names now included in save request (was silently dropped)

### Added
- Compiled translations for French, Czech, and Russian (#221, #223)

## [2.26.1] - 2026-05-25

### Fixed
- Shared R/W users can now edit and delete shared accounts (#216)
- Dashboard summary now includes shared accounts in totals (#215)
- Hero tiles no longer truncate large values — text wraps instead (#214)
- IBAN, routing number, and sort code fields now shown for all checking/savings accounts regardless of currency (#217)
- Navigation search field no longer autofilled by Chrome (#213)
- Help & Docs link now opens in a new tab instead of showing a blank page (#212)
- Rule group names now saved correctly when creating/editing rules

## [2.26.0] - 2026-05-23

### Added
- **Gridstack.js Dashboard** — Replaced custom drag-and-drop with Gridstack.js for reliable tile positioning, drag, and reflow.
- **Hero Tile Reordering** — Hero tiles can now be reordered via drag-and-drop when the dashboard is unlocked.
- **Duplicate Dashboard Tiles** — Add multiple instances of chart and transaction tiles (up to 5 each), each with independent account/period settings. Available for: Income vs Expenses, Spending by Category, Net Worth History, Asset Value History, and Recent Transactions.
- **Category Details Chart** — Date range picker (3m / 6m / 12m / 2y) and account filter on the category spending chart. Budget vs actuals line overlay when a budget is set.
- **Account Filtering** — Added account selector to Budget Progress and Top Spending Categories dashboard tiles. Backend support for account filtering on budget report and category details APIs.
- **Dashboard Tile Sizing** — All chart and list tiles now properly flex-fill their Gridstack cells, with responsive overflow and scroll behavior.

### Fixed
- Dashboard tiles not dropping where expected — resolved by switching from CSS Grid auto-placement to Gridstack.js absolute positioning.
- Chart tiles rendering as flat lines or empty — fixed canvas sizing within flex containers and deferred chart rendering until after layout.
- Shared account transactions failing to load (#207).
- Top-level category toggle now functional on all applicable tiles (#208).

## [2.25.0] - 2026-05-21

### Added
- **Debt Payoff Charts** — Stacked area chart showing each debt declining over time, with toggle to individual line view. Hover tooltips show per-debt balances.
- **Debt Scenarios** — Create, save, and compare named payoff scenarios with different strategies (avalanche/snowball), extra monthly payments, one-time lump sums, debt selection, and interest rate overrides.
- **Scenario Comparison** — Side-by-side comparison of avalanche vs snowball strategies showing months to payoff, total interest, and a recommendation.
- **Debt Progress Dashboard Widget** — Countdown to debt-free date, progress bar showing percentage paid off, next payoff milestone, and ahead/behind schedule status.
- **Debt Chart Dashboard Widget** — Mini sparkline chart showing debt decline trajectory on the dashboard.
- **Days Until Debt Free Hero Widget** — Hero tile showing countdown to projected debt-free date.

### Fixed
- **Running Balance Across Pages** ([#194](https://github.com/otherworld-dev/Budget/issues/194)) — Rewrote running balance to compute server-side across all account transactions, eliminating page boundary calculation errors.
- **Rules Not Applying Tags** ([#204](https://github.com/otherworld-dev/Budget/issues/204)) — Fixed deferred tag actions being silently dropped during rule execution.
- **Dashboard Lock/Unlock Icons** — Replaced non-rendering CSS icon classes with inline SVGs for lock/unlock and settings gear buttons.
- **Category Dropdown Clipped** — Fixed category autocomplete dropdown being cut off at the bottom of the transactions table.
- **Accounts Tile Settings Icon** — Settings gear icon now visible on dark themes (uses currentColor).

### Removed
- **Dead Category Settings Button** — Removed non-functional gear button from the categories page header.

## [2.24.0] - 2026-05-20

### Added
- **Import Rules for Bank Sync** — Bank sync transactions now run through import rules when the "Apply rules" toggle is enabled per connection. Rules with "Apply during import" will auto-categorize, tag, set vendors, etc.
- **Import Source Rule Criteria** — New "Import Source" field in rule criteria. Match on "Bank Sync", "CSV Import", or "Toshl" to target specific import sources.
- **Savings Goal Color & Account** ([#203](https://github.com/otherworld-dev/budget/issues/203)) — Color picker and account selector on savings goals now save correctly. Progress bars on the dashboard use the saved color.

### Fixed
- **Account Tab Date Format** ([#200](https://github.com/otherworld-dev/budget/issues/200)) — Date column now uses the user's configured format instead of ISO.
- **Account Tab Vendor Column** ([#200](https://github.com/otherworld-dev/budget/issues/200)) — Vendor is now a separate column instead of being merged into Description.
- **CSV Import TypeError** ([#201](https://github.com/otherworld-dev/budget/issues/201)) — Fixed crash when Account column contains numeric values.
- **Account Detail Page Jump** ([#194](https://github.com/otherworld-dev/budget/issues/194)) — Pagination buttons no longer skip multiple pages due to stacked event listeners.
- **Rules Table Borders** — Fixed misaligned row borders caused by `display:flex` on the status column.
- **Rules Action Badges** — V2 rule actions (category, vendor, tags, type, etc.) now display correctly in the rules list.
- **Remove Group Button** — The ✕ button to remove a criteria group in the rule editor now works (was missing data-path attribute).
- **Transaction Table Column Widths** — Set explicit column widths on account transaction table for proper alignment.

## [2.23.0] - 2026-05-20

### Added
- **Auto-Create Transactions for Recurring Income** — Same as bills' auto-pay. When enabled, the background job creates scheduled transactions automatically when the expected date arrives. Includes Nextcloud notifications on success/failure.
- **Category Column in CSV Import** ([#193](https://github.com/otherworld-dev/budget/issues/193)) — Map a CSV column to "Category" for auto-creation during import, same as the Toshl preset.
- **Budget Month Navigation Arrows** — Left/right arrows flanking the month selector for quick month switching.
- **Account List View Click Navigation** — Clicking an account row in list view now opens the account details page.
- **Balance Colour in Account List View** — Account balances in list view now show green for positive, red for negative.

### Fixed
- **Bill Description Not Saved** ([#196](https://github.com/otherworld-dev/budget/issues/196)) — Bill descriptions now properly saved on create and update, and carried to auto-generated transactions.
- **Budget Month Off-By-One** ([#199](https://github.com/otherworld-dev/budget/issues/199)) — Fixed UTC timezone conversion causing wrong month selection in certain timezones.
- **Liability Opening Balance Edit** ([#195](https://github.com/otherworld-dev/budget/issues/195)) — Editing a liability account no longer auto-negates positive balances, allowing credit/overpayment values.
- **Running Balance with Scheduled Transactions** — Scheduled transactions are now excluded from running balance calculation in both account detail and main transaction views.
- **Scheduled Transaction Styling** — Future/scheduled transactions now show with reduced opacity in the main transactions table, matching the account detail view.
- **Transaction Table Column Alignment** — Added column classes to account transaction table headers for proper alignment with data rows.
- **Categories Search Padding** — Fixed search icon overlapping text in the categories search bar.
- **Bill Amount Spacing** — Added proper top padding to bill amount display.

## [2.22.0] - 2026-05-18

### Added
- **Accounts Grid/List Toggle** — New toggle in the accounts header to switch between tile grid and compact list view. Preference saved to localStorage.
- **Live Balance Preview** — When editing an account's opening balance, the current balance updates in real-time.
- **Dashboard Tile Settings Modal** — Tile configuration (reorder, show/hide) now opens in a centered modal instead of inline at the bottom of the tile.

### Fixed
- **Dashboard tile reorder** — Reordering accounts in the tile settings now applies immediately without page refresh.
- **Toshl import UX** — Correct preview counts, category creation counting, and loading indicator between steps.
- **Institution autocomplete error** — Fixed TypeError when typing in the institution field on account forms.
- **Asset modal spacing** — Added proper margin between form groups.
- **Account list view** — Balance text no longer overlaps action buttons.
- **CSS isolation** — Added stacking context isolation to prevent external widget bleed-through in the navigation.

## [2.21.1] - 2026-05-17

### Fixed
- **Toshl import: language-independent headers** — Now uses positional column mapping instead of matching header names. Works regardless of Toshl's export language (German, French, etc.).
- **Toshl import: multi-currency conversion** — When a transaction is in a foreign currency (e.g., HUF), the import now uses the "In Main Currency" value (e.g., 85 EUR) instead of the original amount (e.g., 26,000 HUF).
- **Toshl import: category caching with pre-existing data** — Fixed duplicate category creation and tag lookup performance when importing into an account that already has categories from a previous import.
- **Toshl import UX** — Column mapping dropdowns hidden when preset is selected; preview count shows actual total instead of "50 of 50"; category creation toast after import.

## [2.21.0] - 2026-05-17

### Added
- **Find Transfers** — New "Find Transfers" button on the Transfers page that detects recurring transfer patterns from transaction history. Shows source account, lets you pick the destination, and creates them as proper linked transfers.
- **Account Column in CSV Import** — Manual CSV import now supports mapping a column to "Account" and "Currency". Accounts are auto-created with inferred types, same as the Toshl preset.

### Fixed
- **Rules UI Dark Mode** — The criteria builder and action builder in the Rules page now render correctly in dark mode. Replaced all hardcoded light-mode colors with Nextcloud CSS variables.

## [2.20.1] - 2026-05-17

### Fixed
- **MariaDB compatibility**: `CAST(... AS VARCHAR)` is not valid on MariaDB. Changed to `CAST(... AS CHAR(n))` which works on all three databases (MySQL/MariaDB, PostgreSQL, SQLite).

## [2.20.0] - 2026-05-17

### Added
- **Liability Account Model** ([#187](https://github.com/otherworld-dev/budget/issues/187)): Loan, credit card, mortgage, and line of credit accounts now store balances as negative numbers internally. Payments (credits) correctly decrease the balance; charges (debits) increase it. The display layer shows absolute values with "owed" context.
- **Mortgage and Line of Credit** account types added to the account type dropdown.
- **Import from App** — App-specific import presets with Toshl Finance as the first supported app. Select "Toshl Finance" from the import format dropdown and the app handles column mapping, date/amount formats, and auto-creation automatically.
- **Toshl Import: Category Auto-Creation** — Categories from Toshl's Category column are created automatically if they don't exist.
- **Toshl Import: Tag Set Integration** — Toshl tags map to Budget's tag set system (not subcategories). A "Tags" tag set is created per category.
- **Toshl Import: Multi-Account Auto-Creation** — Accounts from Toshl's Account column are created automatically with inferred types (e.g., "Cash" → cash, "Investment" → investment) and currencies from the CSV.
- **Toshl Import: Full Preview** — Preview shows accounts to create, categories to create, tags to create, and transfer rows to skip before executing.

### Fixed
- **PostgreSQL compatibility** ([#185](https://github.com/otherworld-dev/budget/issues/185), [#192](https://github.com/otherworld-dev/budget/issues/192)): Fixed `SUBSTR()` on date columns by casting to VARCHAR. Affects all report/trend queries.
- **CSV encoding detection** ([#189](https://github.com/otherworld-dev/budget/issues/189)): Import now auto-detects ISO-8859-1, Windows-1252, and ISO-8859-15 encodings and converts to UTF-8.
- **Horizontal scrolling** ([#188](https://github.com/otherworld-dev/budget/issues/188)): Transaction table no longer overflows viewport with long descriptions.
- **Scheduled transaction auto-clear**: Editing a scheduled transaction's date to today or past automatically changes status to cleared and updates the account balance.
- **Liability balance display**: Account detail view now shows absolute value for liability accounts instead of raw negative number.
- **Net worth formula**: Corrected for negative liability balance model.
- **Category delete error message**: Now explains why deletion failed and what to do.
- **Database migration**: Existing liability account balances automatically negated on upgrade.
- **Backward-compatible import**: Legacy data exports with positive liability balances are converted during import.

## [2.19.1] - 2026-05-15

### Fixed
- **DI registration mismatches**: Fixed missing `IL10N` in `BankSyncService`, missing `LoggerInterface` in `BillService` and `RecurringIncomeService` DI registrations causing 500 errors on income and bills pages.
- **API credentials exposure**: Institutions endpoint changed from GET to POST so GoCardless secrets are no longer in URL query parameters.
- **Premature active status**: New GoCardless connections and re-authorizations now use `pending_auth` status until bank authorization is completed, preventing failed background sync attempts.
- **False-positive expiration**: Transient API errors during reauth checks no longer incorrectly mark connections as expired; only definitive statuses (EX/RJ/SA) trigger expiration.
- **Double-click protection**: All wizard buttons now have busy guards preventing duplicate submissions.
- **Auth check conflict**: Fixed onclick/addEventListener collision in the authorization check step.
- **Budget account unmapping**: Users can now clear a mapped budget account back to unmapped.
- **Transaction ID collisions**: Fallback hash for transactions without IDs now includes account ID and index to prevent silent deduplication of identical purchases.
- **Provider revocation**: Disconnecting a GoCardless connection now revokes the requisition at the provider.
- **API efficiency**: `refreshAccounts` no longer fetches transaction data unnecessarily, saving GoCardless API quota.
- **Background job memory**: Credentials are no longer bulk-decrypted; connections processed one at a time.
- **Null safety**: Fixed potential TypeError when disconnecting with mappings section not rendered.

### Changed
- Country names in bank selection now use `Intl.DisplayNames` for automatic locale-appropriate translation.
- Connection name validated (1-255 chars), country code validated (2-letter ISO), redirect URLs validated against Nextcloud base URL.
- SimpleFIN claim POST now has a 30-second timeout.
- Default currency fallback changed from GBP to EUR for GoCardless.
- Service-layer messages wrapped in IL10N for translation support.
- Background job converted from service locator to constructor injection.

## [2.19.0] - 2026-05-15

### Added
- **GoCardless Bank Sync Wizard** ([#184](https://github.com/otherworld-dev/budget/issues/184)): Complete multi-step connection flow — enter API credentials, select your country and bank from a searchable grid with logos, then authorize at your bank. Previously the institution selection step was missing, making GoCardless connections non-functional.
- **Re-authorization Flow**: Expired GoCardless connections (90-day PSD2 limit) now show a "Re-authorize" button instead of requiring disconnect/reconnect.
- **Sync All Connections**: New button to sync all active bank connections sequentially with progress feedback and aggregated results.
- **Refresh Accounts**: Button in account mappings to re-fetch the account list from the bank provider.

### Fixed
- **GoCardless sync always failing** ([#184](https://github.com/otherworld-dev/budget/issues/184)): Fixed "Bank authorization has expired" error that occurred on every sync because the connect flow never sent the institution ID, so no bank requisition was created.
- **Exception messages leaking to clients**: Controllers now return generic error messages instead of exposing internal exception details.
- **SQLite compatibility**: Replaced MySQL-specific `CAST(AS CHAR)` with `SUBSTR` for cross-database compatibility.
- **Budget summary double-counting**: Children's spending no longer counted twice in parent category totals.

### Changed
- Controllers now use `request->getParams()` instead of reading `php://input` directly, following Nextcloud framework conventions.
- Replaced debug `error_log()` calls with proper PSR logger usage.

## [2.18.1] - 2026-05-11

### Fixed
- **Duplicate detection false positives** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Payment + next occurrence pairs (same `created_at`, ~30 days apart) were incorrectly flagged as duplicates. Now only flags entries within 14 days of each other.
- **Bill date edit not updating nextDueDate** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Editing a bill on an older version left a stale nextDueDate. Now verifies consistency on every save and recalculates if needed.

## [2.18.0] - 2026-05-11

### Added
- **Description field for bills, income, and transfers** ([#181](https://github.com/otherworld-dev/budget/issues/181)): Optional description that is used when transactions are auto-generated.
- **Semi-annually frequency support**: Bills can now use semi-annual frequency (every 6 months), previously missing from the frequency calculator.

### Fixed
- **FrequencyCalculator rewrite**: Fixed 6 bugs affecting bill date calculations:
  - Daily bills double-advancing (skipping 2 days instead of 1) with forceAdvance
  - Biweekly bills only advancing 1 week instead of 2
  - Quarterly bills getting stuck when dueMonth was >3 months behind
  - Days 29-31 truncated to 28 for quarterly/yearly/one-time bills
  - One-time bills incorrectly advancing +1 year on create
  - forceAdvance using a 2099 date hack that broke custom frequency bills
- **Bills list shows only active bills** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Inactive paid one-time bills no longer appear as "Upcoming".
- **Calendar paid status** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Now uses lastPaidDate instead of nextDueDate to determine paid months.
- **Bill date editing** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Changing dueDay/dueMonth now recalculates nextDueDate from today.
- **Running balance pagination** ([#182](https://github.com/otherworld-dev/budget/issues/182)): Balance calculations now correct when same-date transactions span multiple pages.
- **Timezone issue in bill paid status**: `isBillPaidThisMonth` now parses dates as strings to avoid timezone drift.
- Removed debug `error_log` calls from BillService.
- Repair tool no longer flags daily/weekly/biweekly bills as stuck.

## [2.17.3] - 2026-05-03

### Fixed
- **Reconciliation marks 0 transactions** ([#175](https://github.com/otherworld-dev/budget/issues/175)): The finish reconciliation flow was querying for non-existent checkbox elements. Now correctly uses the selected transactions set.
- **Reconciled transaction indicator** ([#175](https://github.com/otherworld-dev/budget/issues/175)): Reconciled transactions now show a green left border in both transaction views.
- **Paid one-time bills showing as "Upcoming"** ([#163](https://github.com/otherworld-dev/budget/issues/163)): One-time bills that were paid but remained active are now detected and deactivated by the Data Repair tool. The stuck bills repair also no longer touches one-time bills.

## [2.17.2] - 2026-05-02

### Fixed
- **Finish Reconciliation error** ([#175](https://github.com/otherworld-dev/budget/issues/175)): "this.loadTransactions is not a function" error when completing reconciliation
- **Dashboard totals include excluded categories**: Transactions in categories marked "Exclude from reports" were still counted in the dashboard income/expense totals

## [2.17.1] - 2026-05-02

### Fixed
- **Reconciliation ignores statement date** ([#175](https://github.com/otherworld-dev/budget/issues/175)): The statement date field was collected but never sent to the backend. Reconciliation now calculates the account balance as of the statement date, excluding transactions after it.

## [2.17.0] - 2026-05-02

### Added
- **Exclude from reports** flag for categories: Categories can be marked as excluded from budget calculations, spending reports, and dashboard totals. Useful for investment adjustments, internal bookkeeping, or reimbursement categories.
- **Auto-match transfers after import** ([#178](https://github.com/otherworld-dev/budget/issues/178)): After importing bank statements, the app automatically scans for and links matching transfer pairs across accounts.
- **Pension DOB from Nextcloud profile** ([#173](https://github.com/otherworld-dev/budget/issues/173)): Pension projections now read your date of birth from your Nextcloud profile for accurate retirement age calculations. No separate setting needed.
- **Reconciliation completion** ([#175](https://github.com/otherworld-dev/budget/issues/175)): Finishing a reconciliation now persists the "Last Reconciled" date and marks checked transactions as reconciled.

### Fixed
- **Category totals now net credits against debits** ([#172](https://github.com/otherworld-dev/budget/issues/172)): Refunds/credits in a category now reduce the total instead of inflating it.
- **Transfer credits no longer carry category** ([#172](https://github.com/otherworld-dev/budget/issues/172)): The credit side of transfers no longer gets a category, preventing double-counting in category totals. Data Repair tool can clean existing data.
- **Debt payments count as expenses** ([#172](https://github.com/otherworld-dev/budget/issues/172)): Transfers to liability accounts (credit cards, loans, mortgages) now correctly count as expenses in dashboard totals instead of being excluded as internal transfers.
- **Stale bill duplicate detection** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Data Repair tool now detects duplicate auto-generated transactions from previous billing cycles with different created_at timestamps.
- **Split badge missing in account detail** ([#176](https://github.com/otherworld-dev/budget/issues/176)): Split transaction indicator now shows in the account detail transaction list.
- **Transaction table header alignment** ([#177](https://github.com/otherworld-dev/budget/issues/177)): Table headers now match column alignment with data rows.
- **XSS in account detail view**: Transaction description, vendor, and category name now properly escaped in account detail rendering.
- **XSS in import filename**: Uploaded filename now escaped in import file details.

### Security
- Added array size limit (500) on reconciliation transaction IDs to prevent oversized queries
- Fixed output encoding in account detail transaction list and import file display

## [2.16.1] - 2026-04-29

### Fixed
- **Transactions with future date not marked as scheduled** ([#170](https://github.com/otherworld-dev/budget/issues/170)): Manually creating a transaction with a future date now auto-sets status to "scheduled". Previously only bill-generated transactions had this logic.
- **Factory reset fails** with JSON parse error — `BudgetSnapshotMapper.deleteAll()` returned void instead of int, causing a TypeError in the factory reset flow
- **Data Repair tool**: Added detection and repair of existing future-dated transactions incorrectly marked as cleared

## [2.16.0] - 2026-04-28

### Added
- **Multi-currency shared expenses** ([#168](https://github.com/otherworld-dev/budget/issues/168)): Shared expenses now track the transaction's account currency. Balances display per-currency lines (e.g., "Owes you ¥5,000" and "Owes you $50" separately). Settlements are created per-currency.
- **Data Repair tool**: New "Scan for Issues" button in Settings → Maintenance detects and fixes duplicate auto-generated transactions, stuck bill due dates, and balance inconsistencies
- **Bill payment duplicate detection**: When marking a bill as paid, the app checks for existing transactions that may already represent the payment and lets you link them instead of creating duplicates
- **In-app documentation**: Help panel and sidebar with searchable user documentation for all features

### Fixed
- **Bills not advancing due date when paid early** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Paying a bill before its due date now correctly advances to the next cycle. Previously this caused balance corruption through duplicate auto-generated transactions.
- **Bank sync shows 0 imports** ([#166](https://github.com/otherworld-dev/budget/issues/166)): Fixed bank sync import count and PHP 8.4 nullable parameter deprecations
- **Mobile responsive layout**: Improved view header layout on mobile devices
- **Help panel navigation**: Help panel now updates content when navigating between pages

## [2.15.1] - 2026-04-27

### Fixed
- **Upgrade fails from pre-2.14 versions** ([#165](https://github.com/otherworld-dev/budget/issues/165)): Migrations 050, 051, and 053 used table/index names that exceeded database limits. Previously, fix migrations (054-056) would correct these, but users upgrading multiple versions at once would hit the broken migration before the fix could run. Now the original migrations create short table names directly, while fix migrations still handle renaming for users who already ran the old versions.

## [2.15.0] - 2026-04-27

### Added
- **Accounts tile customisation**: Unlock dashboard to access a gear icon on the Accounts tile — reorder accounts with drag-and-drop and toggle visibility per account
- **Projected Balance**: Account detail now shows both "Current Balance" and "Projected Balance" when scheduled transactions exist, with dimmed/italic styling on projected running balances

### Fixed
- **Migration fails on MariaDB** ([#164](https://github.com/otherworld-dev/budget/issues/164)): Renamed `budget_bank_connections` table to `budget_bc` — auto-generated PK name exceeded MariaDB's index name limit
- **Bill amount edit causes date jump** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Editing a bill's amount no longer recalculates the due date; only actual changes to frequency/dueDay/dueMonth trigger recalculation
- **Paid bills disappear from calendar** ([#163](https://github.com/otherworld-dev/budget/issues/163)): Past months now remain visible in the bills calendar with strikethrough styling to indicate they've been paid

## [2.14.1] - 2026-04-26

### Fixed
- **Installation fails: table name too long** ([#162](https://github.com/otherworld-dev/budget/issues/162)): Renamed `budget_bank_account_mappings` to `budget_bam` to stay within Nextcloud's name limit
- **Recent Imports dashboard tile stuck on Loading** ([#157](https://github.com/otherworld-dev/budget/issues/157)): Implemented actual import history query and fixed container ID mismatch

## [2.14.0] - 2026-04-26

### Added
- **External Bank Sync (Beta)** ([#61](https://github.com/otherworld-dev/budget/issues/61)): Connect external bank accounts for automatic transaction imports
  - GoCardless provider for UK/Europe banks
  - SimpleFIN provider for US banks
  - Admin toggle (disabled by default) with experimental feature warning
  - Encrypted credential storage, daily background sync, duplicate detection
  - Account mapping between external and local accounts
- **Kazakhstani Tenge (KZT)** ([#154](https://github.com/otherworld-dev/budget/issues/154)): Added KZT currency with ₸ symbol
- **One-time transfers** ([#160](https://github.com/otherworld-dev/budget/issues/160)): Transfer form now supports one-time frequency

### Fixed
- **Dashboard tiles stuck on Loading** ([#157](https://github.com/otherworld-dev/budget/issues/157)): 11 Phase 2/3 dashboard tiles had no render methods — now display data correctly
- **Asset/liability colors inverted** ([#153](https://github.com/otherworld-dev/budget/issues/153)): Colors now match actual values; income budget progress uses correct semantics
- **Income amount not saving on update** ([#159](https://github.com/otherworld-dev/budget/issues/159)): Fixed `php://input` consumption issue in RecurringIncomeController

### Changed
- Updated German and Portuguese (Brazil) translations from Weblate

## [2.13.1] - 2026-04-21

### Fixed
- **Migration fails: "Primary index name too long"** ([#152](https://github.com/otherworld-dev/budget/issues/152)): Renamed `budget_budget_snapshots` table to `budget_bgt_snapshots` to stay within Nextcloud's 30-character index name limit
- **Budget tab does not show income transactions** ([#149](https://github.com/otherworld-dev/budget/issues/149)): Spending queries were hardcoded to debit transactions; income categories now correctly query credit transactions
- **Mark bill as paid fails** ([#151](https://github.com/otherworld-dev/budget/issues/151)): Regular bills and recurring income were still passing null description to transaction creation
- **German translation not loading** ([#150](https://github.com/otherworld-dev/budget/issues/150)): Compiled translation files were missing from the release; added compiled German and Portuguese translations

### Changed
- Updated German translations from Weblate

## [2.13.0] - 2026-04-21

### Added
- **Per-month budget adjustments**: Budget values can now differ between months. Click "Adjust budgets from this month" to create a new baseline that applies from that month onwards while preserving previous months' values
- **Budget snapshot system**: New `budget_budget_snapshots` table stores per-month budget overrides with automatic resolution chain
- **Parent category budget aggregation**: Parent categories now show the sum of their own budget plus all children's budgets, with a "Total" hint below the input

### Fixed
- **Parent category spending not showing** ([#144](https://github.com/otherworld-dev/budget/issues/144)): Parent categories now aggregate children's spending and budgets correctly
- **Spending by Category shows wrong data on first dashboard load** ([#147](https://github.com/otherworld-dev/budget/issues/147))
- **Dashboard tile header links not navigating** ([#146](https://github.com/otherworld-dev/budget/issues/146))
- **Budget page month selector not filtering spending** ([#148](https://github.com/otherworld-dev/budget/issues/148))
- **Bill transaction creation fails with null description** ([#145](https://github.com/otherworld-dev/budget/issues/145))

### Changed
- Reports, alerts, and dashboard now use snapshot-resolved budgets for accurate per-month reporting
- Budget aggregate hint updates immediately when editing budget inputs (no reload needed)
- Improved translation documentation in README

## [2.12.1] - 2026-04-20

### Added
- **Nextcloud user integration for shared expenses**: Add contacts by selecting Nextcloud users from a dropdown instead of manually entering names ([#143](https://github.com/otherworld-dev/budget/issues/143))
- **User dropdown for budget sharing**: Share your budget by selecting users from a dropdown instead of typing exact usernames
- **User search API**: New endpoint for searching Nextcloud users by name or username

### Changed
- Contacts can now be linked to Nextcloud user accounts (optional `nextcloudUserId` field)
- German translation updates via Weblate

## [2.12.0] - 2026-04-17

### Added
- **Unrealised P&L for investment/crypto accounts**: Display unrealised profit and loss based on current market value vs cost basis ([#59](https://github.com/otherworld-dev/budget/issues/59))
- **Interest accrual for loans and credit cards**: Automatic interest calculation and accrual for loan and credit card accounts ([#73](https://github.com/otherworld-dev/budget/issues/73))
- **New translation**: German via Weblate

### Fixed
- **Security and precision fixes for interest accrual**: Improved input validation and decimal precision
- **Interest tracking checkbox sizing**: Checkbox now matches form UI styling

## [2.11.2] - 2026-04-16

### Fixed
- **Improved color contrast for amounts and progress bars**: Better visibility across all pages
- **Restore Unlock Dashboard button text on mobile**: Button text was missing on small screens
- **Mobile navigation toggle not working and mispositioned** ([#130](https://github.com/otherworld-dev/budget/issues/130))

## [2.11.1] - 2026-04-16

### Fixed
- **Translator placeholder protection**: Added translator comments to notification strings warning not to translate `{placeholder}` names, and configured Weblate placeholder check ([#140](https://github.com/otherworld-dev/budget/issues/140))

## [2.11.0] - 2026-04-16

### Added
- **Granular budget sharing between Nextcloud users**: Share accounts, categories, budgets, and savings goals with other Nextcloud users with read/write permissions ([#23](https://github.com/otherworld-dev/budget/issues/23))
- **Duplicate transaction option**: Quickly duplicate an existing transaction ([#138](https://github.com/otherworld-dev/budget/issues/138))
- **Skip payment option for recurring bills**: Skip a bill payment and advance to the next due date ([#132](https://github.com/otherworld-dev/budget/issues/132))
- **New translations**: Spanish, French, Russian, Portuguese (Brazil) via Weblate

### Fixed
- **Category drag-and-drop throws DOM insertBefore error**: Fixed drag-and-drop reordering of categories
- **Cannot change subcategory to top-level category** ([#141](https://github.com/otherworld-dev/budget/issues/141))
- **Recent Transactions dashboard tile shows empty** ([#139](https://github.com/otherworld-dev/budget/issues/139))
- **Auto-generated bill/income transactions use null description** ([#137](https://github.com/otherworld-dev/budget/issues/137))
- **Undo mark-as-paid now properly deletes created transactions** ([#136](https://github.com/otherworld-dev/budget/issues/136))
- **Shared expenses overhaul**: Fixed settlements, badges, and UI layout ([#134](https://github.com/otherworld-dev/budget/issues/134))
- **Standardize positive/negative amount colors** with CSS custom properties ([#133](https://github.com/otherworld-dev/budget/issues/133))
- **Security audit fixes**: Added missing write-access checks and input validation
- **Database review fixes**: Cascade delete and parameter type corrections
- **Frontend review fixes**: Error handling, partial saves, and performance improvements

## [2.10.2] - 2026-04-13

### Fixed
- **Tag filter checkmark visibility improved**: Checkmarks in tag filter dropdowns are now visible, and "include untagged" is unchecked by default ([#128](https://github.com/otherworld-dev/budget/issues/128))
- **bcmath dependency removed from migration**: Removed bcmath requirement from migration code and declared it in info.xml ([#131](https://github.com/otherworld-dev/budget/issues/131))
- **Mobile navigation toggle for collapsed sidebar**: Sidebar navigation toggle now works correctly on mobile when sidebar is collapsed

## [2.10.1] - 2026-04-12

### Fixed
- **Tag/category dropdown selection broken on Safari/WebKit**: Moved selection logic from click to mousedown event to fix dropdowns not responding to clicks in Safari, DuckDuckGo, and other WebKit browsers ([#128](https://github.com/otherworld-dev/budget/issues/128))

## [2.10.0] - 2026-04-11

### Added
- **Full internationalization (i18n) support**: All user-facing strings are now translatable, enabling multilingual translations via Weblate
- **Multi-currency bill display and split templates**: Bills now show amounts in their native currency with base-currency equivalents, and split templates support multi-currency accounts ([#126](https://github.com/otherworld-dev/budget/issues/126), [#121](https://github.com/otherworld-dev/budget/issues/121))

### Fixed
- **Stale event listeners breaking tag filter in reports**: Prevented duplicate event listeners from accumulating and causing tag filters to malfunction ([#128](https://github.com/otherworld-dev/budget/issues/128))
- **Long tag names cut off in transaction list**: Tag names are no longer truncated in the transaction list display

## [2.9.0] - 2026-04-10

### Added
- **Global tags support**: Tags can now be created globally and shared across all categories ([#109](https://github.com/otherworld-dev/budget/issues/109))
- **Creation date filter for transactions**: Filter transactions by when they were created in the app, separate from the transaction date ([#127](https://github.com/otherworld-dev/budget/issues/127))
- **Account filter on bills calendar report**: Filter the bills calendar by specific accounts ([#105](https://github.com/otherworld-dev/budget/issues/105))
- **Fiat equivalent display on non-base-currency accounts**: Accounts in foreign currencies now show their equivalent value in your base currency ([#58](https://github.com/otherworld-dev/budget/issues/58))
- **Deletion warnings and duplicate transaction detection**: Warns before deleting transactions and detects potential duplicates during entry ([#115](https://github.com/otherworld-dev/budget/issues/115))
- **Info notice on Tags page**: Explains the difference between global and category-specific tags

### Fixed
- **Pre-existing test failures**: Fixed test failures across 6 test files including AccountServiceTest dependency issues

## [2.8.8] - 2026-04-08

### Added
- **LATAM currencies and zero-decimal fiat display**: Added support for Latin American currencies (ARS, BRL, CLP, COP, PEN, UYU) and fixed display for zero-decimal fiat currencies ([#123](https://github.com/otherworld-dev/budget/issues/123))
- **Transfer target account name in transaction table**: Transaction table now shows the target account name for transfer transactions ([#116](https://github.com/otherworld-dev/budget/issues/116))

### Fixed
- **Scheduled transactions incorrectly affect account balance**: Scheduled (future) transactions were included in the stored account balance; a migration recalculates all balances to exclude them ([#115](https://github.com/otherworld-dev/budget/issues/115))
- **Tags not saved when creating a new transaction**: Tags were silently dropped when creating transactions ([#125](https://github.com/otherworld-dev/budget/issues/125))
- **Account and category filters reset when toggling filter panel**: Opening or closing the filter panel cleared the selected account and category filters ([#117](https://github.com/otherworld-dev/budget/issues/117))
- **Bill/income name populates vendor field instead of description**: Creating a transaction from a bill or income item put the name in the vendor field rather than the description ([#120](https://github.com/otherworld-dev/budget/issues/120))
- **Entity updates silently fail due to method_exists on magic methods**: Updates to entities using magic setter methods were silently ignored ([#118](https://github.com/otherworld-dev/budget/issues/118))
- **Debt payoff recommendation uses hardcoded GBP symbol**: Debt payoff planner now uses the user's configured currency symbol instead of £ ([#122](https://github.com/otherworld-dev/budget/issues/122))
- **Balance recalculation fails on PostgreSQL with invalid date**: Fixed a PostgreSQL-specific error when recalculating balances with null or invalid dates ([#124](https://github.com/otherworld-dev/budget/issues/124))
- **Bill payment and scheduling bugs causing balance discrepancies**: Fixed multiple issues with bill payments and scheduling that led to incorrect account balances ([#115](https://github.com/otherworld-dev/budget/issues/115))
- **Import rules losing criteria and actions during data re-import**: Re-importing rules from backup data could overwrite existing criteria and actions ([#119](https://github.com/otherworld-dev/budget/issues/119))

## [2.8.7] - 2026-03-29

### Fixed
- **Parent category dropdown not working when editing subcategories**: The parent dropdown failed to show or pre-select the current parent when editing subcategories ([#111](https://github.com/otherworld-dev/budget/issues/111))
- **Map income/expense to credit/debit in rule type actions**: Import rules with "set type" action stored income/expense instead of the internal credit/debit values, causing the type to appear empty on affected transactions ([#110](https://github.com/otherworld-dev/budget/issues/110))

## [2.8.6] - 2026-03-29

### Fixed
- **Hide Transfer option when editing transactions**: Transfer type is no longer shown in the type dropdown when editing existing transactions ([#108](https://github.com/otherworld-dev/budget/issues/108))
- **Use server-side data for categories view**: Categories view now fetches fresh data from the server instead of using stale client-side transactions ([#106](https://github.com/otherworld-dev/budget/issues/106))
- **Include pensions and assets in Net Worth calculation**: Net Worth dashboard widget now correctly includes pension and asset values ([#107](https://github.com/otherworld-dev/budget/issues/107))

## [2.8.5] - 2026-03-27

### Added
- **Asset value history dashboard widget**: Track asset values over time with a change indicator showing growth/decline ([#92](https://github.com/otherworld-dev/budget/issues/92))
- **Account filtering on dashboard widgets**: Filter dashboard widgets by specific accounts ([#71](https://github.com/otherworld-dev/budget/issues/71))
- **Minimum payment field on account edit form**: Track minimum payments for credit card and loan accounts ([#102](https://github.com/otherworld-dev/budget/issues/102))

### Fixed
- **Hierarchical nested category dropdowns everywhere**: All category dropdowns now show the full parent/child hierarchy consistently ([#104](https://github.com/otherworld-dev/budget/issues/104))
- **Quick Add Transaction widget not populating accounts and categories**: Widget now correctly loads account and category options
- **Central currency list for asset and pension dropdowns**: Asset and pension forms now use the same currency list as the rest of the app ([#103](https://github.com/otherworld-dev/budget/issues/103))
- **Duplicate category creation and renaming**: Prevent creating or renaming categories to names that already exist
- **Show actual error message when category create/update fails**: Error responses now display the server's message instead of a generic error
- **Allow clearing category, vendor, notes, and reference on transactions**: These fields can now be set back to empty ([#98](https://github.com/otherworld-dev/budget/issues/98))
- **Handle BOM and metadata preamble in DKB CSV imports**: DKB bank exports with byte-order marks and header metadata are now parsed correctly ([#100](https://github.com/otherworld-dev/budget/issues/100))

## [2.8.4] - 2026-03-26

### Added
- **Running balance column on transactions table**: Bank-statement-style cumulative balance column that shows the account balance at each transaction. Auto-hides when viewing multiple accounts or using non-date sorting/filters ([#79](https://github.com/otherworld-dev/budget/issues/79))
- **Bi-weekly frequency for bills, income, and transfers**: New bi-weekly option across all frequency dropdowns ([#88](https://github.com/otherworld-dev/budget/issues/88))
- **One-time income support**: Non-recurring income entries that auto-deactivate after being marked as received; also adds semi-annually frequency to income ([#91](https://github.com/otherworld-dev/budget/issues/91))
- **Redesigned Match All with scan/review flow**: Split bulk-match into separate scan and link steps with a pre-scan config dialog for date window and auto/review mode. Adds currency check to prevent cross-currency matching and fixes N+1 account queries ([#82](https://github.com/otherworld-dev/budget/issues/82))

### Fixed
- **Recurring bill payment not creating transaction for current period**: Marking a recurring bill as paid only created a scheduled transaction for the next period — the current payment was never recorded. Now finds and clears any pre-existing scheduled transaction, or creates a new cleared one ([#99](https://github.com/otherworld-dev/budget/issues/99))
- **Pension return rate displayed as decimal in edit form**: The edit form showed the raw decimal (e.g. 0.05) instead of the percentage (5%). Now correctly converts between display and storage formats ([#101](https://github.com/otherworld-dev/budget/issues/101))
- **Reconciliation using wrong balance**: Reconciliation used the raw stored balance (including future scheduled deductions) instead of the adjusted current balance shown on the account card

## [2.8.3] - 2026-03-25

### Added
- **Create transaction when marking recurring income as received**: Marking income as received now automatically creates a corresponding transaction in the linked account, so income appears in transaction history and reports ([#99](https://github.com/otherworld-dev/budget/issues/99))

### Fixed
- **2-digit year dates in CSV import**: CSV files using 2-digit year date formats (e.g. `25.03.26`) are now parsed correctly ([#100](https://github.com/otherworld-dev/budget/issues/100))

## [2.8.2] - 2026-03-23

### Added
- **Tag filter on transaction list**: Filter transactions by tag directly from the transaction page ([#96](https://github.com/otherworld-dev/budget/issues/96))
- **Split type filter and account filter matching**: Filter transactions by split type (unsplit, split parent, split child); account filter options now match the accounts dropdown ([#97](https://github.com/otherworld-dev/budget/issues/97))

### Fixed
- European-format zeros (e.g. `0,00`) in dual-column CSV import no longer treated as valid amounts ([#95](https://github.com/otherworld-dev/budget/issues/95))
- Bill name field clarified as "Vendor / Payee" to match its actual purpose ([#94](https://github.com/otherworld-dev/budget/issues/94))
- Removed undefined `distinct()` call in QueryFilterBuilder that could cause errors on some databases
- Category dropdown clipped by `overflow:hidden` on table cells
- Search filter inputs now span full width of the filter grid

## [2.8.1] - 2026-03-23

### Added
- **Import and Export buttons on account details page**: Quick access to import statements and export transactions directly from account view
- **Improved account reconciliation**: Adjustment transactions created automatically when reconciliation reveals a balance discrepancy; UX improvements to reconciliation flow

### Fixed
- Filter panel layout tidied up; removed unused more-actions button
- Empty-state message now spans full table width in account transactions view
- Account header condensed into a single row for cleaner layout
- Dark green text colors brightened for dark mode readability across account pages and pagination
- Account pagination centered properly
- Correct error message shown when attempting to delete an account that still has transactions
- Institution field not persisting on account edit due to duplicate element ID
- Account form group spacing and credit limit field margin

## [2.8.0] - 2026-03-22

### Added
- **Redesigned category details panel**: Spending breakdown chart with enhanced analytics showing monthly trends, top vendors, and budget progress ([#88](https://github.com/otherworld-dev/budget/issues/88))
- **Redesigned assets page**: Styled asset cards with full-page detail view replacing the previous list layout
- **Redesigned transaction actions**: More-actions dropdown replaced with a horizontal icon toolbar for faster access
- **Consistent date formatting**: Replace native date inputs with flatpickr across the app so the user's chosen date format is used everywhere — transaction forms, filters, inline editing ([#72](https://github.com/otherworld-dev/budget/issues/72))
- **Tag set editing**: Edit existing tag sets and tags with duplicate name validation ([#90](https://github.com/otherworld-dev/budget/issues/90))
- **Inline amount editor**: Simplified to text-only input with automatic type detection based on sign (positive = income, negative = expense)

### Fixed
- **Category dropdown not working**: Categories stopped appearing in transaction dropdown after a recent UI update ([#87](https://github.com/otherworld-dev/budget/issues/87))
- **NC33 background job error**: Log error when starting Nextcloud 33 with Budget enabled ([#86](https://github.com/otherworld-dev/budget/issues/86))
- **Interest rate limit**: Error saving account when interest rate exceeds 9.99%; widened column precision from DECIMAL(5,4) to DECIMAL(7,4) ([#74](https://github.com/otherworld-dev/budget/issues/74))
- **Liability sign/color inversion**: Liability account cards showed a forced negative sign on positive balances; Total Assets summary used static green color even when negative ([#85](https://github.com/otherworld-dev/budget/issues/85))
- **Liability totals incorrect**: Totals now net credits against debt instead of using absolute values; cards distinguish owed vs credit balance
- **One-time bill transactions not created**: Marking a one-time bill as paid did not create a transaction or deduct from account balance because the bill was deactivated before the transaction creation check ([#89](https://github.com/otherworld-dev/budget/issues/89))
- **One-time bill payment date**: Payment transactions now use today's date and are marked as cleared instead of scheduled
- **Dashboard tile reordering errors**: "Failed to save widget order" and "Failed to save dashboard lock state" errors caused by race conditions during rapid drag-and-drop; settings saves are now debounced ([#77](https://github.com/otherworld-dev/budget/issues/77))
- **Add Tiles menu closing**: Menu no longer closes when selecting tiles
- **Asset detail edit button broken**: Edit button in asset detail panel did nothing due to a button ID mismatch between template and JavaScript ([#76](https://github.com/otherworld-dev/budget/issues/76))
- **Asset edit modal crash**: Annual rate field ID mismatch caused the modal to fail on open
- **Split transaction cannot be undone**: The "Unsplit Transaction" button existed but was never shown or connected to its handler; now visible when editing a split transaction ([#75](https://github.com/otherworld-dev/budget/issues/75))
- **Split indicator badge missing**: Split transactions now show the indicator badge; split modal auto-fills remaining balance
- **Inline account change**: Support changing a transaction's account via inline edit ([#70](https://github.com/otherworld-dev/budget/issues/70))
- **Transaction action buttons overflowing**: Prevent action buttons from being pushed into adjacent columns by long text; editable cell overlay no longer blocks the actions column
- **Tag set edit button not wired up**: Edit button now works; fixed related categories navigation issue
- **"Use Default Categories" failing**: JSON parse error when applying default categories
- **Sidebar search overlap**: Search text no longer overlaps the search icon
- **Theme consistency**: Category settings button, configure columns button, and reconciliation panel now use Nextcloud theme variables

## [2.7.2] - 2026-03-09

### Fixed
- **Multi-currency account totals**: Account list totals now correctly convert balances to the user's default currency; asset value editing saves properly ([#68](https://github.com/otherworld-dev/budget/issues/68))
- **Action buttons disappearing**: Prevent action buttons from being pushed off-screen by long text in transaction and account views ([#69](https://github.com/otherworld-dev/budget/issues/69))
- **Opening balance not persisting on edit**: Editing an account now correctly saves the opening balance and auto-recalculates the stored balance ([#64](https://github.com/otherworld-dev/budget/issues/64))
- **Missing NetWorthSnapshotJob registration**: Background job was not registered in `info.xml`, preventing automatic net worth snapshots ([#67](https://github.com/otherworld-dev/budget/issues/67))

### Improved
- Manual exchange rate modal visual refinements for better readability and spacing

## [2.7.1] - 2026-03-07

### Fixed
- **Migration failure on upgrade from 2.6.x to 2.7.0**: `Undefined constant OCP\IDBConnection::PARAM_INT` in opening balance migration — used wrong constant class ([#66](https://github.com/otherworld-dev/budget/issues/66))
- **Log error spam from missing notification icon**: Notifier referenced non-existent `app-dark.svg`, causing RuntimeException on every notification poll ([#65](https://github.com/otherworld-dev/budget/issues/65))

## [2.7.0] - 2026-03-07

### Added
- **Opening balance tracking**: New `opening_balance` column on accounts separates the initial balance from transaction-derived running totals. Existing accounts are back-calculated automatically during migration ([#64](https://github.com/otherworld-dev/budget/issues/64))
- **Recalculate Account Balances**: New maintenance tool in Settings recalculates all account balances from opening balance + transaction history ([#64](https://github.com/otherworld-dev/budget/issues/64))
- **Opening balance field on account edit**: Edit an account's opening balance directly; contextual labels show "Starting Balance" on create and "Current Balance" on edit
- **Comprehensive unit test suite**: 1,045 tests covering all services, mappers, controllers, and edge cases

### Fixed
- Account balance corruption when editing account details — the display balance (excluding future transactions) was incorrectly written back as the stored balance ([#64](https://github.com/otherworld-dev/budget/issues/64))
- Import preview showing "Uncategorized" for all transactions despite matching import rules ([#64](https://github.com/otherworld-dev/budget/issues/64))
- Money market accounts invisible on Accounts page due to missing account type in filter ([#64](https://github.com/otherworld-dev/budget/issues/64))
- Account balances not refreshing after importing transactions ([#64](https://github.com/otherworld-dev/budget/issues/64))
- Year-over-Year report and Bills Calendar export functionality ([#62](https://github.com/otherworld-dev/budget/issues/62))
- Dashboard charts auto-regenerate when switching accounts ([#62](https://github.com/otherworld-dev/budget/issues/62))

## [2.6.1] - 2026-03-05

### Fixed
- Database migration failure: table name `budget_manual_exchange_rates` exceeded Nextcloud's 27-character limit ([#62](https://github.com/otherworld-dev/budget/issues/62))

## [2.6.0] - 2026-03-05

### Added
- **Configurable budget cycle start day**: Set a custom start day for budget periods instead of always using the 1st of the month ([#54](https://github.com/otherworld-dev/budget/issues/54))
- **Exchange rate support for non-ECB currencies**: Currencies not covered by the European Central Bank now fetch rates from fallback providers ([#55](https://github.com/otherworld-dev/budget/issues/55))
- **Transaction status column**: Scheduled future transactions can be excluded from reports and balance calculations ([#56](https://github.com/otherworld-dev/budget/issues/56))

### Fixed
- Reports account filtering, year-over-year filtering, and export errors ([#62](https://github.com/otherworld-dev/budget/issues/62))
- Category text unreadable in light theme ([#17](https://github.com/otherworld-dev/budget/issues/17))
- Import rule matching now uses v2 schema during file import ([#57](https://github.com/otherworld-dev/budget/issues/57))
- Bills page title changed from "Recurring Bills" to "Bills"

## [2.5.0] - 2026-03-02

### Added
- **Assets section**: Track non-cash assets (real estate, vehicles, jewelry, collectibles) with value snapshots, appreciation/depreciation projections, and net worth integration ([#52](https://github.com/otherworld-dev/budget/issues/52))
  - CRUD management with 11 REST endpoints
  - Value history charts and projection charts
  - Dashboard hero tile for total asset worth
  - Net worth and factory reset integration

### Fixed
- Parent category dropdown showing wrong type when creating Income categories ([#53](https://github.com/otherworld-dev/budget/issues/53))

## [2.4.0] - 2026-03-02

### Added
- **Cryptocurrency account type**: Static cryptocurrency tracking with 25 supported currencies (BTC, ETH, XRP, SOL, DOGE, etc.), correct decimal precision, and encrypted wallet address field ([#47](https://github.com/otherworld-dev/budget/issues/47))
- **Multi-currency dashboard aggregations**: Hero tiles, net worth, trend data, and cash flow reports convert all account values to the user's default currency before summing. Exchange rates fetched from ECB (fiat) and CoinGecko (crypto) with daily background updates ([#52](https://github.com/otherworld-dev/budget/issues/52))
- **Recurring bill end dates**: Optional end date or remaining payment count on bills; bills auto-deactivate when conditions are met and annual overview respects constraints ([#46](https://github.com/otherworld-dev/budget/issues/46))
- **Unit tests**: 133 new tests across AccountService, AuthService, CategoryService, TagSetService, and TransactionService

### Fixed
- Bill mark-as-paid now uses the bill's due date instead of today's date, preventing wrong billing period from being marked paid ([#51](https://github.com/otherworld-dev/budget/issues/51))
- Bill status badge colors use explicit values instead of Nextcloud CSS variables for reliable contrast ([#51](https://github.com/otherworld-dev/budget/issues/51))
- Blank pagination pages after bulk actions caused by `?int` category parameter discarding 'uncategorized' string value; reset page to 1 after bulk operations ([#50](https://github.com/otherworld-dev/budget/issues/50))
- CSV date parsing for DD/MM/YYYY format ([#48](https://github.com/otherworld-dev/budget/issues/48))
- Bill date timezone bug and added one-time bill frequency ([#39](https://github.com/otherworld-dev/budget/issues/39))
- Pension edit modal redesigned with form-section layout; fixed missing field persistence for expectedReturnRate, retirementAge, and transferValue
- Pension summary and projections now convert to base currency before aggregating
- Dashboard pension worth tile uses base currency instead of first account's currency
- `getPrimaryCurrency()` replaced with user's `default_currency` setting instead of balance-weighted heuristic
- Income summary API returns correct keys for page tiles (expectedThisMonth, monthlyTotal, receivedThisMonth, activeCount)

### Changed
- Added `ext-bcmath` PHP extension dependency

## [2.3.1] - 2026-02-22

### Fixed
- CSV import crash (`array_combine()` error) when bank exports include metadata preamble rows before column headers (e.g. Swiss bank CSVs) ([#11](https://github.com/otherworld-dev/budget/issues/11))
- UTF-8 BOM in CSV files polluting the first column header name

## [2.3.0] - 2026-02-19

### Added
- **Pending transaction indicator**: Future-dated transactions display with muted opacity, italic text, and an orange "Pending" badge ([#39](https://github.com/otherworld-dev/budget/issues/39))
  - Status filter (All / Cleared / Pending) in main transactions and account detail views
- **Expanded currency support**: Added 25+ new currencies covering Americas, Europe, Asia-Pacific, Middle East, and Africa (45 total)
- **Custom toast notifications**: Built-in toast notification system replacing deprecated `OC.Notification` calls

### Fixed
- Account detail filters (category, type, status, date range, amount range, search) not passed to transactions API ([#43](https://github.com/otherworld-dev/budget/issues/43))
- PostgreSQL compatibility: cast date column to CHAR before SUBSTR for month extraction ([#41](https://github.com/otherworld-dev/budget/issues/41))
- Reports: exclude transfers from aggregate income/expense totals in all-accounts view to prevent double-counting

### Removed
- Non-functional in-app theme toggle (light/dark/system) — the app correctly inherits Nextcloud's global theme via CSS variables ([#44](https://github.com/otherworld-dev/budget/issues/44))
- Hardcoded dark mode CSS overrides from rules builder components

## [2.2.1] - 2026-02-09

### Fixed
- Cannot update account after creation when IBAN or other banking details are provided ([#38](https://github.com/otherworld-dev/budget/issues/38))
  - Encrypted banking fields (IBAN, account number, routing number, sort code, SWIFT/BIC) exceeded column length limits
  - Widened all encrypted columns from 10-100 chars to 512 chars to accommodate AES-CBC encrypted output (~232 chars)

## [2.2.0] - 2026-02-08

### Added
- **Tag-linked savings goals**: Link savings goals to tags so current amount is automatically calculated from the sum of tagged transactions
  - Tag dropdown in goal modal with options grouped by tag set
  - Auto-tracked badge and disabled manual amount entry for linked goals
  - Goals without a linked tag continue to use manual tracking
- **Tag selection in bills**: Assign tags from category tag sets when creating or editing bills
  - Dynamic tag dropdowns load based on selected category
  - Tags stored on bill entity and applied to generated transactions
- **Tag selection in recurring transfers**: Assign category and tags to recurring transfers
  - Category dropdown and dynamic tag selectors in transfer modal
  - "Create transactions now" checkbox to immediately generate tagged transactions
  - Tags automatically applied to transactions created via auto-pay

### Fixed
- Budget period conversion rounding errors and inconsistent summary cards ([#35](https://github.com/otherworld-dev/budget/issues/35))
  - Increased `budget_amount` column precision from DECIMAL(15,2) to DECIMAL(15,6) for accurate intermediate conversions
  - Removed premature 2-decimal rounding in budget proration; round only for display
  - Normalized all category budgets to monthly in summary cards for consistent totals
- Goal modal form groups missing top margin spacing

## [2.1.2] - 2026-02-07

### Fixed
- App store screenshot display - corrected repository name in screenshot URL from Nextcloud-Budget to Budget

## [2.1.1] - 2026-02-07

### Fixed
- Critical database migration error preventing fresh installations: "Column is type Bool and also NotNull, so it can not store false"
- Fixed 4 boolean columns incorrectly created with NOT NULL constraint in migrations 001000024, 001000026, and 001000027:
  - `budget_import_rules.stop_processing`
  - `budget_bills.auto_pay_enabled`
  - `budget_bills.auto_pay_failed`
  - `budget_bills.is_transfer`
- Added cleanup migration (Version001000028) to fix existing installations that already ran broken migrations
- All boolean columns now use `'notnull' => false` as required by Nextcloud's DBAL for cross-database compatibility
- Updated CLAUDE.md with critical boolean column requirements to prevent future occurrences

## [2.1.0] - 2026-02-07

### Added
- **Bills Calendar Report**: Annual overview showing which months bills are due ([#32](https://github.com/otherworld-dev/budget/issues/32))
  - Annual overview table with bill amounts by month
  - Monthly totals bar chart and color-coded heatmap view
  - Year selector (current year ± 2 years)
  - Filter by bill status (active/inactive/all)
  - Option to include/exclude recurring transfers
  - Toggle between table and heatmap visualization
  - Supports all bill frequencies (daily, weekly, monthly, quarterly, yearly, custom)
- **Recurring Transfers**: Track recurring transfers between accounts ([#36](https://github.com/otherworld-dev/budget/issues/36))
  - Define recurring transfers with auto-pay option
  - Transaction description pattern for import matching
  - Monthly equivalent calculation for different frequencies
  - Integrated with bills system infrastructure
  - Summary cards showing active, due, and completed transfers
  - Filtering tabs (All, Due Soon, Overdue, Completed)
- **Advanced Rules Engine**: Complete redesign of import rules system
  - Visual query builder for complex boolean expressions (AND/OR/NOT operators)
  - Support for nested criteria groups with unlimited depth
  - Multiple action types: category, vendor, notes, tags, account, type, reference
  - Action priority ordering and behavior settings (always, if_empty, append, merge, replace)
  - Preview matches before saving rules to test criteria
  - Run rules immediately on existing transactions
  - Comprehensive unit test coverage (50+ tests for CriteriaEvaluator and RuleActionApplicator)
  - Auto-migration of v1 rules to v2 format when edited
- **Auto-Pay Bills**: Automatic bill payment when due date arrives
  - Auto-pay checkbox in bill form (requires account to be set)
  - Notifications for successful payments and failures
  - Auto-disable on failure to prevent retry loops
  - Status badges on bill cards showing auto-pay state
  - Manual payment resets failed state
- **Future Bill Transactions**: Create future transactions for better cash flow planning
  - Option to create future transaction when adding bills
  - Auto-generate transaction when marking bills as paid
  - Link bills to transactions via bill_id column
  - Enhanced TransactionService with createFromBill() method
- **Transfer Transaction Creation**: Create transfers directly from transaction form
  - Select "Transfer" type to create linked debit/credit transactions
  - Automatic account linking between source and destination
  - Reuses existing transaction matching infrastructure
- **Dynamic Budget Period Switching**: Change budget period with automatic pro-rating
  - Switch between weekly, monthly, quarterly, and yearly periods
  - Automatic budget amount pro-rating between periods (e.g., £12,000 yearly = £1,000 monthly)
  - Spending recalculation for selected period's date range
- **Net Worth Tracking Enhancements**: Improved net worth history display
  - Show when last automatic snapshot was taken (hours/days ago)
  - Status indicator displaying last snapshot information
  - Improved empty state messaging with better user guidance

### Improved
- **Import Rules UI**: Completely redesigned modal interface
  - Modern, space-efficient layout with 1400px width
  - Inline layout for name and priority fields
  - Visual CriteriaBuilder and ActionBuilder components
  - Simplified "Apply Rules" modal that auto-applies all active rules
  - Enhanced checkbox design with card-like styling
  - Better visual hierarchy and improved spacing
- **Currency Symbol Placement**: Correct positioning for suffix currencies ([#34](https://github.com/otherworld-dev/budget/issues/34))
  - Swedish, Norwegian, Danish kronas now display as "500 kr" instead of "kr500"
  - Swiss franc follows ISO 4217 standard positioning
  - Position-aware formatting with CURRENCY_CONFIG metadata
  - Both formatCurrency() and formatCurrencyCompact() updated
- **Dashboard Tiles**: Auto-update when transactions or budgets change
  - Automatic refresh after transaction create/update/delete operations
  - Auto-refresh when budget amounts are modified
  - Fixed race conditions by awaiting loadInitialData before rendering
  - Optimized spending chart layout with detailed breakdown list

### Fixed
- **Timezone Date Calculations**: Resolve month-off-by-one errors ([#27](https://github.com/otherworld-dev/budget/issues/27))
  - Transactions no longer appear in wrong month for users in non-UTC timezones
  - Added timezone-safe date formatting utilities: formatDateForAPI(), getTodayDateString(), getMonthStart(), getMonthEnd()
  - Fixed budget spending queries and dashboard date ranges
  - Ensures all date ranges use user's local timezone consistently
- **Transaction Filters**: Filters now properly apply to transaction table
  - Category, type, amount range, search, and date filters work correctly
  - Filters auto-update on every change for consistent behavior
  - Fixed state management between app and module instances
  - Added missing filter parameters to loadTransactions() API call
- **Account Balance Calculations**: Exclude future-dated transactions
  - Balances reflect actual state as of today
  - Affects dashboard, accounts page, net worth calculations, and forecasts
  - Future bill transactions no longer affect current balance
- **Bill Auto-Pay Validation**: Proper account requirement handling
  - Auto-pay requires account to be set (validated frontend and backend)
  - Auto-pay checkbox disabled without account selection
  - Clear UI feedback for validation requirements
- **Rule Migration System**: Fix v1 to v2 migration issues
  - Properly wrap migrated v1 conditions in groups for CriteriaBuilder
  - Detect and re-migrate broken v2 rules with null criteria
  - Schema version now reliably saved during migration
  - Auto-fix legacy broken structures when rules are opened in UI
  - Detailed console logging for debugging migration decisions
- **Transaction Edit Button**: Fix for old transactions on accounts page
  - Fetch transaction from API when not found in local state
  - Ensures edit functionality works for all historical transactions
- **Routing Issues**: Fix 404/500 errors on specific endpoints
  - Year-over-Year API using correct TransactionMapper method (findAllByUserAndDateRange)
  - Uncategorized transactions endpoint route ordering fixed (specific paths before {id} patterns)
  - Account balance auto-update after adding transactions
- **Category Update Endpoint**: Add missing budgetPeriod parameter support
  - Fixes "No valid fields to update" error when changing budget periods
  - Validation for monthly, weekly, quarterly, and yearly periods
- **Modal Close Behavior**: Rules modals now properly close after save
  - Added rule-modal and apply-rules-modal to hideModals list
  - Prevents modals staying open after successful operations
- **Import Rule Type Casting**: Fix TypeError in category/account ID validation
  - Cast category and account IDs to int in RuleActionApplicator
  - JSON sends IDs as strings but PHP strict typing requires int
  - Resolves 500 error when creating rules with category/account actions
- **Dashboard Data Accuracy**: Improved trend chart and tile calculations
  - Budget Remaining tile property name handling fixed
  - Spending by Category chart handles API array data format correctly
  - Chart layout optimized with detailed breakdown showing percentages
  - Increased chart size from 280px to 320px for better visibility

## [2.0.5] - 2026-02-03

### Added
- **Custom frequency pattern for bills**: Select specific months when irregular bills occur (e.g., bills in January, June, and July only)
  - New "Custom" frequency option in bill creation/editing modal
  - Interactive month selector with modern tile-based UI design
  - Selected months show full primary color background with checkmark indicators
  - Smooth hover animations and responsive grid layout (4/3/2 columns for desktop/tablet/mobile)
  - Automatic next due date calculation based on selected month patterns
  - Handles year wrapping and month-end edge cases (e.g., day 31 in February)
  - Monthly equivalent calculations for budget summaries
  - Pattern stored as JSON: `{"months": [1, 6, 7]}` for flexibility

### Improved
- Enhanced month selector UI with hidden checkboxes and clean tile design
- Better visual feedback for selected months in bill frequency picker

## [2.0.4] - 2026-02-03

### Fixed
- Re-release with corrected build configuration excluding development files from distribution package

## [2.0.3] - 2026-02-03

### Fixed
- Missing `deleteByTag()` method in TransactionTagMapper causing HTTP 500 errors when deleting categories with tag sets
- Categories with subcategories can now be deleted recursively - cascade delete now removes all child categories and their tag sets automatically

## [2.0.2] - 2026-02-03

### Fixed
- Foreign key constraint incorrectly formed error during migration 001000022
- Removed all database foreign key constraints for better cross-database compatibility
- Implemented application-level cascade deletes:
  - Deleting a tag now removes associated transaction_tags
  - Deleting a tag set now removes all tags and their transaction_tags
  - Deleting a category now removes all tag sets, tags, and transaction_tags
  - Deleting a transaction now removes associated transaction_tags
- Matches pattern used throughout rest of application (no other migrations use foreign keys)

## [2.0.1] - 2026-02-03

### Fixed
- Primary key index name too long error during migration 001000022
- Shorten primary key names for tag_sets, tags, and transaction_tags tables to prevent MySQL 64-character limit error
- Critical fix for fresh installations that were failing during database setup

## [2.0.0] - 2026-02-02

### Added
- **Tag Sets feature for multi-dimensional transaction categorization (GitHub issue #13)**
  - Categories can have multiple tag sets (e.g., "Hobbies" → "Activity" tag set + "Equipment" tag set)
  - Each tag set contains multiple tags with customizable colors
  - Tags can be assigned to transactions for detailed organization and filtering
  - Tag management UI in category details page with compact design
  - Color-coded tag chips with visual distinction
  - Modal interface for adding tags with HTML5 color picker
  - Transaction filtering by tags (supports multiple tags with OR logic within tag sets, AND logic across tag sets)
  - Tag filter dropdown on reports page for filtering transactions by tags
  - Database tables: `budget_tag_sets`, `budget_tags`, `budget_transaction_tags`
  - RESTful API endpoints for tag set and tag CRUD operations
  - Auto-select first category when category page loads for better UX
  - Theme-aware color scheme using Nextcloud CSS variables
- **CSV import enhancements for better international bank support (GitHub issue #15)**
  - Auto-detection of CSV delimiters (comma, semicolon, tab)
  - User-selectable delimiter override when auto-detection fails
  - Dual-column amount mapping for files with separate income/expense columns
  - Support for files with "Deposits" and "Withdrawals" columns (common in European banks)
  - Intelligent amount parsing handles European number formats (1.234,56)
  - Auto-detection of income/expense column patterns (deposits, withdrawals, credits, debits)
  - Smart validation ensures either single amount OR dual columns selected, not both
  - CSV options panel (delimiter selector) shown only for CSV files
  - Delimiter flows through entire import pipeline (upload → preview → process)
  - Backend delimiter parameter support in ParserFactory, ImportService, and ImportController
  - TransactionNormalizer handles both single and dual-column amount approaches
  - Frontend validation prevents invalid mapping combinations
- Undo functionality on bills page to revert deletions
- Undo functionality on income page to revert deletions
- DKK (Danish Krone) currency support
- Semi-annually frequency option for recurring bills
- Expanded currency selection to 20 currencies with centralized Currency enum

### Changed
- **Major refactoring: Modularized frontend architecture (63% code reduction in main.js)**
  - Split monolithic main.js (~18,000 lines) into 14 feature-based modules in `src/modules/`
  - Created dedicated modules: AccountsModule, AuthModule, BillsModule, CategoriesModule, DashboardModule, ForecastModule, ImportModule, IncomeModule, PensionsModule, ReportsModule, RulesModule, SavingsModule, SharedExpensesModule, TagSetsModule, TransactionsModule
  - Extracted Router into separate `src/core/Router.js` class
  - Created shared utilities in `src/utils/`: api.js, dom.js, formatters.js, helpers.js, validators.js
  - Centralized dashboard widget configuration in `src/config/dashboardWidgets.js`
  - Improved maintainability, testability, and developer experience
  - No user-facing changes - purely internal architecture improvement

### Fixed
- Duplicate event listeners in forecast module causing memory leaks
- Bill editing persistence issues - edits now save correctly
- Pension modal bugs and added disclaimer notice for retirement projections
- Savings goals creation failing to save properly
- Savings goals completed count displaying incorrectly
- Bills page hero cards showing "no data" despite active bills
- Multiple UI bugs in tag sets modal and transaction modal
- Transaction table rendering issues after modularization
- Inline transaction editing not saving changes correctly
- Various functionality issues restored after modularization refactor

## [1.2.3] - 2026-01-24

### Fixed
- Remove vendor/tecnickcom/tcpdf/tools/.htaccess that was causing integrity check failures
- File was being blocked/removed by server security policies during installation
- Directory security is already handled by Nextcloud's web server configuration

## [1.2.2] - 2026-01-24

### Fixed
- Include hidden files (.htaccess) in package signature
- Fixes FILE_MISSING error for vendor/tecnickcom/tcpdf/tools/.htaccess

## [1.2.1] - 2026-01-24

### Fixed
- App package now includes all required files (lib/ and vendor/ directories) in code signature
- Fixes integrity check errors when installing from app store

## [1.2.0] - 2026-01-23

### Added
- Password protection feature for enhanced app security
  - Optional password required to access the budget app (secondary protection layer)
  - User-configurable password (minimum 6 characters) set via Settings > Security
  - Session management with configurable timeout (15/30/60 minutes of inactivity)
  - Auto-lock after inactivity period with activity monitoring on user interactions
  - Manual lock button in navigation when password protection is enabled
  - Failed attempt tracking: 5 failed attempts triggers 5-minute account lockout
  - Session tokens (64-character random tokens) stored securely in localStorage
  - Password hashing using bcrypt via PHP's `password_hash()` with `PASSWORD_DEFAULT`
  - Change password and disable protection options (requires current password verification)
  - Rate limiting on auth endpoints (5-10 requests per minute depending on endpoint)
  - Modal UI for password entry with error handling and validation
  - New database table `budget_auth` for password and session management
  - RESTful API endpoints: `/api/auth/status`, `/api/auth/setup`, `/api/auth/verify`, `/api/auth/lock`, `/api/auth/extend`, `/api/auth/disable`, `/api/auth/password`
- Factory reset feature to restore app to empty state
  - Deletes ALL user data (accounts, transactions, bills, categories, settings, pension data, shared expenses, etc.)
  - Preserves audit logs for compliance purposes
  - Danger Zone section in settings page with prominent warnings
  - Requires typing "DELETE" (case-sensitive) to confirm
  - Password confirmation required via Nextcloud's built-in security
  - Rate limited to 3 attempts per 5 minutes to prevent abuse
  - Database transaction ensures all-or-nothing deletion (rollback on error)
  - Gracefully handles missing database tables for features not yet used
  - Audit trail logged with counts of deleted items per entity type

### Fixed
- Dashboard crashing with "Cannot read properties of undefined (reading 'filter')" error
- `updateBudgetProgressWidget()` now validates categories parameter is an array before filtering
- Budget API response handling now properly handles null responses with fallback to empty categories array
- Password protection setup failing with "Entity which should be updated has no id" error
- Auth entity `id` property access level changed from protected to public (required by Nextcloud Entity framework)
- Database migration added to recreate `budget_auth` table with auto-increment `id` as primary key
- `user_id` changed from primary key to unique index for proper ORM compatibility
- CSV import failing with "Date is required" error on all rows
- Column mapping dropdowns sending array indices (0, 1, 2) instead of column names ("Date", "Amount", "Description") to backend
- Auto-detection of CSV columns not working after upload
- TransactionNormalizer now skips non-column mapping fields (boolean config flags) to prevent lookup errors
- PDF report exports appearing corrupted (TCPDF library not installed)
- ReportExporter falling back to JSON export when PDF format requested
- Application.php now loads composer autoloader to ensure TCPDF and other dependencies are available

## [1.1.0] - 2026-01-21

### Added
- Configurable dashboard layout with drag-and-drop tile reordering (GitHub issue #9)
- Lock/Unlock Dashboard toggle to enable/disable tile reordering
- Remove tiles by clicking X button (appears on hover when unlocked)
- Add hidden tiles back via "Add Tiles" dropdown menu
- Visual feedback: grab cursor, hover lift effect, drop indicators, and fade-in animations
- Dashboard customization works on desktop; touch devices show lock toggle only
- All dashboard layout changes persist automatically to backend
- Configurable transaction table columns - show/hide Date, Description, Vendor, Category, Amount, and Account columns
- Gear icon in transaction table header to access column visibility settings
- Column visibility preferences persist across sessions via settings API
- Vendor column added to transaction table with inline editing support
- 10 new dashboard tiles (Phase 1 - hidden by default, zero performance impact):
  - **Hero Tiles**: Savings Rate, Cash Flow, Budget Remaining, Budget Health
  - **Widget Tiles**: Top Spending Categories, Account Performance, Budget Breakdown, Savings Goals Summary, Payment Methods, Reconciliation Status
- All new tiles use existing data (no additional API calls required)
- New tiles available via "Add Tiles" dropdown for user opt-in
- 8 additional dashboard tiles with lazy loading (Phase 2 - fully implemented):
  - **Hero Tiles**: Uncategorized Count (shows count of uncategorized transactions), Low Balance Alert (alerts when accounts below threshold)
  - **Widget Tiles**: Monthly Comparison (current vs previous month table), Large Transactions (top 10 by amount), Weekly Spending, Unmatched Transfers, Category Trends, Bills Due Soon
- Lazy loading system: Phase 2+ tiles only fetch data when made visible by user
- Modified applyDashboardVisibility() to support async lazy loading
- All Phase 2 tiles hidden by default, minimal performance impact (load on-demand only)
- 8 advanced dashboard tiles with charts and complex calculations (Phase 3 - fully implemented):
  - **Hero Tiles**: Burn Rate (shows days until balance hits zero at current spend rate), Days Until Debt Free (estimated payoff timeline using avalanche strategy)
  - **Widget Tiles**: Cash Flow Forecast (90-day projected balance chart), Year-over-Year Comparison (annual spending comparison), Income Tracking (expected vs received income with progress bars), Recent Imports (last 3 file imports), Rule Effectiveness (auto-categorization statistics), Spending Velocity (current week vs average)
- Chart.js integration for Cash Flow Forecast and Year-over-Year Comparison widgets
- Chart instance management with proper cleanup when tiles are hidden
- All Phase 3 tiles hidden by default with lazy loading for optimal performance
- Quick Add Transaction widget for fast transaction entry directly from dashboard (Phase 4 - fully implemented):
  - Inline form with essential fields: Date, Account, Type, Amount, Description, and optional Category
  - Real-time validation with helpful error messages displayed inline
  - Automatic dropdown population for accounts and categories
  - Submit button to add transaction via `/api/transactions` POST endpoint
  - Clear button to reset form to default state
  - Success/error messages with auto-hide for success (3 seconds)
  - Auto-refresh of transactions and dashboard after successful add
  - Today's date auto-populated as default
  - Compact single-column layout optimized for dashboard widget display
- All 28 new dashboard tiles (8 hero + 20 widget) now complete and available via "Add Tiles" dropdown
- Completed 4-phase rollout: Phase 1 (10 tiles, existing data), Phase 2 (8 tiles, lazy loaded), Phase 3 (8 tiles, charts), Phase 4 (1 interactive tile)
- "Add Tiles" dropdown now organized by categories to reduce overwhelm:
  - Categories: Insights & Analytics, Budgeting, Forecasting, Transactions, Income, Debts, Goals, Bills, Alerts, Interactive
  - Each category shows as a collapsible section with header
  - Hero tiles display "Hero" badge to distinguish from regular widget tiles
  - Categories only appear if they contain hidden tiles

### Changed
- Removed redundant category dropdown and categorize button from bulk actions panel (use Edit Fields modal instead)
- Improved visibility of column configuration gear icon with grey background and white icon color

### Fixed
- Bulk edit modal appearing in top-left corner instead of centered on screen
- Category dropdown in inline edit was too narrow and cutting off category names
- Dashboard tile order not persisting after page refresh

## [1.0.34] - 2026-01-21

### Added
- Bulk actions for transactions page (GitHub issue #10)
- Bulk delete: Delete multiple transactions in a single API call
- Bulk reconcile: Mark multiple transactions as reconciled/unreconciled
- Bulk edit: Update category, vendor, reference, and notes for multiple transactions at once
- Three new API endpoints: `/api/transactions/bulk-delete`, `/api/transactions/bulk-reconcile`, `/api/transactions/bulk-edit`
- Bulk edit modal with form validation and theme-consistent styling
- "Mark Reconciled", "Mark Unreconciled", and "Edit Fields..." buttons to bulk actions toolbar
- Input validation and sanitization for all bulk operations
- Rate limiting on bulk endpoints (10 requests/minute)
- Success/failure counts in API responses with detailed error tracking

### Changed
- Bulk delete and bulk categorize now use dedicated bulk API endpoints instead of individual API calls for improved performance
- Bulk actions panel now uses theme-aware CSS variables (`var(--color-background-dark)`) instead of hardcoded light blue colors
- Bulk actions panel adapts to both light and dark themes automatically

## [1.0.33] - 2026-01-20

### Fixed
- Duplicate transaction detection completely broken during statement imports (GitHub issue #6)
- OFX FITID (bank transaction ID) was lost during transaction mapping, preventing bank-provided duplicate detection
- Import IDs used random file identifiers instead of content hashing, causing same transaction to generate different IDs
- Preview methods didn't generate import IDs before duplicate checking, so duplicates were never detected in preview
- Import preview showed no indication of which transactions were duplicates
- "Show duplicates" and "Show uncategorized" checkboxes had no effect on preview display
- Wrong checkbox ID used in JavaScript ('skip-duplicates' vs 'show-duplicates')
- Error status badges and balance amounts too dark to read (GitHub issue #8)
- Installation failure on PostgreSQL: "Column is type Bool and also NotNull, so it can not store false" (GitHub issue #5)
- Migration Version001000017 used `notnull => true` for boolean columns, violating Nextcloud's cross-database compatibility requirements
- Boolean columns `is_settled` and `apply_on_import` now correctly defined as nullable per Nextcloud standards

### Changed
- TransactionNormalizer now preserves OFX transaction 'id' field for duplicate detection
- Import ID generation changed from `fileId_index_hash` to content-based: `ofx_fitid_{id}` for OFX or `hash_{md5(date+amount+description+reference)}` for CSV/QIF
- Same transaction imported multiple times now generates same import ID, enabling proper duplicate detection
- Import preview now includes 'isDuplicate' flag on each transaction
- Duplicate transactions displayed with red "Duplicate" badge, new transactions with green "New" badge
- Duplicate transactions unchecked by default in preview to prevent accidental import
- "Show duplicates" and "Show uncategorized" checkboxes now filter preview table in real-time
- Preview counter updates to reflect filtered results
- Error status badges and balance amounts now use brighter colors for improved readability

## [1.0.32] - 2026-01-19

### Fixed
- Background job ArgumentCountError flooding logs: "Too few arguments to function BillReminderJob::__construct()"
- All background jobs (BillReminderJob, CleanupImportFilesJob, NetWorthSnapshotJob, CleanupAuditLogsJob) now use lazy dependency injection via Server::get()
- Removed manual background job service registrations that weren't used by Nextcloud's cron system

### Added
- SettingService to properly wrap SettingMapper following architectural patterns
- Convenient methods for user settings: get(), set(), getAll(), delete(), exists()

## [1.0.31] - 2026-01-19

### Fixed
- Account balances showing scientific notation (e.g., `9.9920072216264e-15`) due to floating-point precision errors
- Balance calculations now use BCMath for precise decimal arithmetic via MoneyCalculator
- TransactionService, NetWorthService, and DebtPayoffService now prevent precision loss during calculations
- Migration added to automatically clean up existing balances with precision errors

### Changed
- AccountMapper.updateBalance() now accepts both float and string parameters for better precision handling
- All balance arithmetic operations now use string-based BCMath calculations internally

## [1.0.30] - 2026-01-19

### Fixed
- Account numbers displaying as extremely long strings of asterisks when decryption fails
- Added error handling for encryption/decryption failures with proper logging
- Masking functions now detect failed decryption and display "[DECRYPTION FAILED]" message
- Backend now rejects masked values (containing asterisks) when updating accounts
- Prevents re-encryption of masked account numbers sent from frontend during balance updates
- Fixed reflection property sync issue where decrypted values weren't updating the raw property
- Account updates (e.g., balance changes) no longer corrupt encrypted account numbers

## [1.0.29] - 2026-01-18

### Fixed
- Transaction category changes no longer affect account balance (GitHub issue #3)
- Inline category editor now works properly on transactions page
- Fixed double debit bug when updating transaction categories

## [1.0.28] - 2026-01-18

### Fixed
- Fthaixed Version001000018 cleanup migration: getPrefix() error and NOT NULL boolean columns
- All migrations now use system config to get table prefix
- All boolean columns now nullable across all migrations

## [1.0.27] - 2026-01-18

### Fixed
- Database migration error: Boolean columns must be nullable to avoid DBAL compatibility issues
- Changed is_settled and apply_on_import columns from NOT NULL to nullable
- Fixes "cannot store false" error during migrations

## [1.0.26] - 2026-01-18

### Fixed
- Migration error "Call to undefined method OC\DB\ConnectionAdapter::getPrefix()"
- Now uses system config to retrieve table prefix instead of connection method

## [1.0.25] - 2026-01-18

### Fixed
- **FINAL FIX**: Migrations now drop entire tables before recreating them
- Prevents schema reconciliation errors by ensuring clean slate
- Works automatically through Nextcloud Apps UI
- Note: Shared expenses and recurring income data will be lost (features were non-functional anyway due to migration errors)

## [1.0.24] - 2026-01-18

### Fixed
- Cleanup migration that drops and recreates broken tables automatically
- Works through Nextcloud Apps UI - no manual database access required
- Migration 001000018 runs after problematic migrations to fix failed installations
- Users can now update through the UI and the app will self-heal

## [1.0.23] - 2026-01-18

### Fixed
- Database migration errors: Use raw SQL to drop broken columns from actual database
- PreSchemaChange now executes ALTER TABLE DROP COLUMN directly on database
- Ensures broken columns are removed before schema reconciliation begins

## [1.0.22] - 2026-01-18

### Fixed
- Database migration errors: Use preSchemaChange to drop broken columns before schema reconciliation
- Prevents "can not store false" errors by removing broken columns before Nextcloud compares schemas
- Final fix for users stuck on migration 001000015 errors

## [1.0.21] - 2026-01-18

### Fixed
- Database migration robustness: Migrations now detect and repair existing broken boolean columns
- Handles both fresh installs and repairing existing installations in same migration
- Critical fix for users stuck on migration errors from v1.0.18

## [1.0.20] - 2026-01-18

### Fixed
- Database migration error for existing installations: Recreate boolean columns with correct defaults
- Fixes columns is_settled, is_active, is_split, and apply_on_import that were created with incorrect defaults

## [1.0.19] - 2026-01-18

### Fixed
- Database migration error: Boolean column defaults must be integers (0/1) not boolean literals (false/true)
- Fixed migrations 001000011, 001000012, 001000015, and 001000016

## [1.0.18] - 2026-01-18

### Fixed
- Category spending API returning 412 error (missing route and CSRF token header)

## [1.0.17] - 2026-01-18

### Performance
- Categories page loads ~10x faster (fixed O(n²) tree building algorithm)
- Budget analysis uses single batch query instead of N+1 queries per category
- Category rendering pre-computes transaction counts (O(n) instead of O(n*m))
- Initial app load ~2-3x faster (parallel API requests for settings, accounts, categories)

## [1.0.16] - 2026-01-18

### Added
- Standalone Rules feature (decoupled from Import)
- Split and Share buttons on transaction list for quick access to category splitting and expense sharing
  - Rules now accessible from top-level navigation
  - Apply rules to existing transactions at any time
  - Preview rule matches before applying changes
  - Filter by account, date range, or uncategorized transactions only
  - Rules can set multiple fields: category, vendor, notes
  - Option to control whether rules apply during import
  - Compact table-based rules list matching transactions page style
  - Toggle switch to enable/disable rules directly from the table

### Changed
- Reorganized navigation menu into logical groups (Core Data, Budgeting, Goals, Analysis)
- Moved Import, Rules, and Settings to collapsible bottom section (Nextcloud style)
- Removed Import Rules tab from Import page (rules now managed from dedicated Rules page)
- Import wizard includes checkbox to optionally apply rules during import
- Renamed "Split Expenses" to "Shared Expenses" for clarity

### Fixed
- Budget alerts API returning 500 error (incorrect constant reference in TransactionSplitMapper)
- Add Rule button not working (duplicate HTML element IDs)
- Rules API endpoint URL mismatch causing HTTP 500 errors
- Checkbox styling in rule modal (oversized and misaligned)
- Edit/delete buttons invisible in rules table actions column
- Transaction edit/delete/split buttons not responding when clicking on icon
- Transaction updates not saving (magic method setters not being called)
- Category details panel not updating after renaming a category
- Budget page categories not loading on first visit (missing API token)
- Remaining amount text hard to read in dark mode (improved contrast)
- Progress column header misaligned with values on budget page
- Missing formatAccountType and closeModal methods causing JavaScript errors on shared expenses page
- Settlement form not submitting (event handler not attached)
- Share expense modal not loading contacts when accessed from transactions page
- Split modal buttons (Save Splits, Unsplit, Add Split) not responding to clicks
- Split transactions not displaying split indicator in transaction list (isSplit field missing from API)
- TransactionSplit entity causing PHP 8 typed property initialization error

## [1.0.15] - 2026-01-17

### Added
- Split expenses / shared budgeting feature
  - Add contacts to share expenses with (roommates, partners, friends)
  - Track who owes whom with real-time balance updates
  - Split transactions 50/50 or with custom amounts
  - Record settlement payments when debts are paid
  - View detailed history of shared expenses per contact
  - See total owed and owing summary cards
  - Navigate to dedicated Split Expenses section

### Fixed
- Database migration error "Primary index name too long" on recurring_income table
- Account form defaulting to USD instead of user's configured default currency
- Data export downloading with `.zip_` extension instead of `.zip`

## [1.0.14] - 2026-01-17

### Added
- Year-over-Year comparison reports
  - Compare spending across multiple years side-by-side
  - Full year comparison with income, expenses, and savings
  - Same month comparison to see how this month stacks up historically
  - Category spending comparison showing trends by category
  - Visual charts for monthly trends across years
  - Percentage change indicators for quick analysis

## [1.0.13] - 2026-01-17

### Added
- Debt payoff planner with avalanche and snowball strategies
  - View all debt accounts (credit cards, loans, mortgages, lines of credit)
  - Calculate payoff timeline based on strategy and extra payments
  - Compare avalanche (highest interest first) vs snowball (smallest balance first)
  - See total interest paid and debt-free date
  - Set minimum payments on liability accounts
  - Dashboard card showing debt summary when debts exist
  - Navigate to dedicated Debt Payoff section

## [1.0.12] - 2026-01-17

### Added
- Bill reminder notifications
  - Set reminders for recurring bills (on due date, 1-14 days before)
  - Receive Nextcloud notifications when bills are due soon
  - Background job checks every 6 hours for upcoming bills
  - One reminder per billing period (avoids duplicate notifications)
  - Overdue bill notifications for missed due dates

## [1.0.11] - 2026-01-17

### Added
- Budget alerts dashboard widget
  - Automatically shows when categories are approaching (80%) or exceeding (100%) their budgets
  - Visual progress bars with warning (yellow) and danger (red) states
  - Shows spent amount vs budget amount for each category
  - Supports all budget periods: weekly, monthly, quarterly, yearly
  - Includes split transaction amounts in budget calculations
  - Card only appears when there are active alerts

## [1.0.10] - 2026-01-17

### Added
- Split transaction feature for allocating transactions across multiple categories
  - Split a single transaction into multiple category allocations
  - Each split can have its own amount and optional description
  - Real-time validation ensures splits sum to transaction total
  - Unsplit transactions to revert to single-category assignment
  - Split indicator badge shown in transaction table for split transactions
  - Minimum 2 splits required for a valid split transaction

## [1.0.9] - 2026-01-17

### Added
- Recurring income tracking feature
  - Track expected income sources (salary, dividends, rental income, etc.)
  - Set frequency (weekly, monthly, quarterly, yearly) and expected day
  - Source field to track who pays the income
  - Link income to categories and accounts
  - Auto-detect pattern for matching transactions
  - Mark income as received to advance to next expected date
  - Summary cards showing expected/received this month and monthly total
  - Filter tabs for All/Expected Soon/Received
  - New "Income" section in navigation

## [1.0.8] - 2026-01-17

### Added
- Net worth history tracking with dashboard chart
  - Daily automatic snapshots via background job
  - Manual snapshot recording option
  - Track total assets, liabilities, and net worth over time
  - Interactive chart with 30-day, 90-day, and 1-year views
  - Shows net worth trend with assets/liabilities reference lines

## [1.0.7] - 2026-01-16

### Added
- Pension tracker for retirement planning
  - Track multiple pension accounts (workplace, personal, SIPP, defined benefit, state)
  - Balance history tracking via manual snapshots
  - One-off contribution tracking with notes
  - Per-pension settings: growth rate, retirement age, currency
  - Projections showing pot value at retirement using compound interest formula
  - Combined projection across all pensions
  - Dashboard card showing total pension worth or projected income
  - Separate "Pensions" section in navigation
- Pension types with different display logic:
  - DC pensions (workplace, personal, SIPP): show pot value with growth projections
  - DB pensions: show annual income at retirement with optional transfer value
  - State pension: show annual amount

## [1.0.6] - 2026-01-15

### Added
- Transaction matching for transfer detection between accounts
- Automatic detection of potential transfer matches (same amount, opposite type, within 3 days)
- Link/unlink transactions as transfer pairs
- Visual indicator for linked transactions in transaction list
- Bulk "Match All" feature for batch transaction matching
  - Auto-links transactions with exactly one match
  - Manual review modal for transactions with multiple potential matches
  - Undo option for auto-matched pairs
- Pagination controls at bottom of transaction table for easier navigation

### Changed
- App icon updated to piggy bank design for better theme compatibility

### Fixed
- PHP 8 deprecation warning: optional parameter declared before required parameters in ReportService
- Transaction page pagination not loading subsequent pages (page parameter was missing from API requests)
- Category creation failing with "updatedAt is not a valid attribute" error (added missing column)

## [1.0.5] - 2026-01-14

### Fixed
- Removed deprecated app.php (IBootstrap handles all bootstrapping)
- Boolean columns made nullable to avoid DBAL compatibility issues across databases

## [1.0.3] - 2026-01-13

### Fixed
- Database index naming collision that prevented installation
- Boolean column default values incompatible with Nextcloud DBAL

## [1.0.0] - 2026-01-13

### Added
- Multi-account management with support for multiple currencies
- Transaction tracking with advanced filtering and search
- Bank statement import (CSV, OFX, QIF formats)
- Automatic vendor matching during import
- Custom import rules for auto-categorization
- Hierarchical categories with drag-and-drop reordering
- Balance forecasting with trend analysis and scenario modeling
- Recurring bill detection and due date monitoring
- Savings goals with progress tracking and achievement forecasting
- Reports and charts for spending patterns, income, and cash flow
- Full data export/import for instance migration
- Audit logging for all financial actions

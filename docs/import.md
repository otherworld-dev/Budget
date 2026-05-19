# Importing Bank Statements

> Import transactions directly from your bank's exported files instead of entering them manually. The Budget app supports CSV, OFX, and QIF formats with automatic duplicate detection.

## Supported Formats

| Format | Best For | Notes |
|--------|----------|-------|
| **CSV** | Most banks, custom exports | Most flexible; requires column mapping |
| **OFX** | US/Canadian banks, direct downloads | Automatic field parsing, no mapping needed |
| **QIF** | Quicken exports, older software | Legacy format with basic field support |

> **Tip:** If your bank offers multiple export formats, OFX is usually the easiest since it requires no manual column mapping. Use CSV when you need full control over how fields are interpreted.

## CSV Import Step-by-Step

CSV is the most flexible import format. The import process walks you through uploading, mapping, previewing, and executing the import.

### 1. Upload Your File

Navigate to **Import > Upload File** and select your CSV file. The file must include a header row as the first line so the app can identify your columns.

> **Note:** The app automatically detects and converts common non-UTF-8 encodings (ISO-8859-1, Windows-1252, and ISO-8859-15) to UTF-8. In most cases no manual conversion is needed. If special characters still appear incorrectly, re-save your file as UTF-8 from your spreadsheet application.

### 2. Delimiter Detection

The app automatically detects whether your file uses commas, semicolons, or tabs as delimiters. The detected delimiter is shown in the preview. If the detection is wrong, you can override it manually.

> **Tip:** European bank exports commonly use semicolons as delimiters since commas are used as decimal separators in those regions.

### 3. Column Mapping

Map each column in your CSV to the corresponding transaction field:

| Field | Required | Description |
|-------|----------|-------------|
| **Date** | Yes | Transaction date |
| **Amount** | Yes (unless using dual columns) | Transaction amount |
| **Income Amount** | No | Separate column for credits/deposits |
| **Expense Amount** | No | Separate column for debits/withdrawals |
| **Description** | No | Transaction description or memo |
| **Vendor** | No | Payee or merchant name |
| **Reference** | No | Check number or reference ID |
| **Category** | No | Category name; categories are auto-created if they do not exist |
| **Account** | No | Account name; accounts are auto-created with inferred types |
| **Currency** | No | Currency code for the transaction |

Select the appropriate column header from the dropdown for each field. Columns you do not map are ignored.

#### Category Column

When you map a column to **Category**, the app automatically creates any categories that do not already exist. Imported transactions are assigned to the matching category by name. This is useful when your bank export or finance app includes category information.

#### Account and Currency Columns

Mapping an **Account** column lets you import transactions across multiple accounts from a single file. Accounts that do not already exist are auto-created with types inferred from the account name (e.g., names containing "Cash" default to the cash type, "Investment" to the investment type, and so on). The **Currency** column sets the currency for each transaction and is especially useful alongside the Account column for multi-currency imports.

### 4. Dual-Column Amount Mapping

Some banks, particularly European ones, export income and expenses in two separate columns rather than using positive and negative values in a single column.

If your file uses this format, map the **Income Amount** and **Expense Amount** columns individually instead of mapping a single **Amount** column.

> **Warning:** You must map either a single **Amount** column or the **Income Amount** and **Expense Amount** pair. Mapping both at the same time is not allowed and will display a validation error.

### 5. European Number Format

If your bank uses European number formatting (e.g., `1.234,56` instead of `1,234.56`), enable the **European number format** toggle during column mapping. This tells the app to interpret periods as thousands separators and commas as decimal separators.

### 6. Preview

After mapping your columns, click **Preview** to see a table of parsed transactions before anything is written to the database. Review the preview carefully:

- Verify dates are parsed correctly
- Confirm amounts have the right sign (positive for income, negative for expenses)
- Check that descriptions and vendors look right

> **Tip:** If something looks wrong in the preview, go back and adjust your column mapping or delimiter settings. No data is saved until you execute the import.

### 7. Execute Import

When the preview looks correct, click **Execute Import** to save the transactions to your selected account. The app reports how many transactions were imported and how many were skipped as duplicates.

## OFX Import

OFX (Open Financial Exchange) files are structured financial data files that many banks offer as a download option, sometimes labeled as "Microsoft Money" or "Quicken" format.

1. Navigate to **Import > Upload File** and select your `.ofx` file
2. The app parses the file automatically -- no column mapping is needed
3. If the file contains an account identifier, the app attempts to match it to one of your existing accounts
4. Review the parsed transactions in the **Preview** step
5. Click **Execute Import** to save

> **Note:** OFX files contain standardized field names, so the date, amount, and description are extracted automatically.

## QIF Import

QIF (Quicken Interchange Format) is a legacy format still exported by some banks and financial software.

1. Navigate to **Import > Upload File** and select your `.qif` file
2. Map the detected fields to transaction fields (similar to CSV mapping)
3. Review the preview and click **Execute Import**

> **Tip:** QIF has limited field support compared to OFX and CSV. If your bank offers OFX as an alternative, prefer that format for more complete data.

## Import Presets (App-Specific Import)

Import presets provide one-click column mapping for exports from other finance apps. When you select a preset, the app knows exactly which columns to expect, so the column mapping step is skipped entirely.

**Flow:** Upload CSV → Select preset from the **Import Format** dropdown → Preview → Execute Import.

Presets handle format details like date patterns, number formats, and special columns automatically. If your source app is supported, using the preset is always easier than manual CSV mapping.

### Toshl Finance

To import from Toshl Finance, export your data as CSV from Toshl and upload the file in the Budget app. Then select **Toshl Finance** from the **Import Format** dropdown.

**Key features of the Toshl preset:**

- **Language-independent** — Works regardless of the language Toshl used for the export. Column detection is based on position, not header names.
- **Date and number handling** — Automatically parses European-style dates (`DD.MM.YY`) and comma-decimal amounts (`1.234,56`).
- **Multi-currency support** — When a transaction is in a foreign currency, the preset uses the converted amount from Toshl's "In Main Currency" column, so all imported amounts are in your main currency.
- **Category auto-creation** — Categories from Toshl's Category column are created automatically if they do not already exist in Budget.
- **Tag set integration** — Tags from Toshl are imported as tag sets attached to the corresponding category. This preserves your Toshl tagging structure.
- **Account auto-creation** — Accounts from Toshl's Account column are created automatically. Account types are inferred from the name (e.g., "Cash" becomes a cash account, "Investment" becomes an investment account).
- **Transfer handling** — Rows where Toshl's Category is "transaction" (inter-account transfers) are skipped automatically since transfers are not regular transactions.
- **Full preview** — Before executing, the preview shows accounts to create, categories to create, tags to import, and transfer rows that will be skipped.

## Duplicate Detection

The app automatically checks for duplicate transactions during import. A transaction is considered a duplicate when it matches an existing transaction in the same account on all of the following:

- Date
- Amount
- Description

Duplicates are skipped during import and reported in the results summary. This makes it safe to import overlapping date ranges without creating duplicate entries.

> **Note:** Duplicate detection is based on exact matching. If your bank changes the description text between exports, the same transaction may not be recognized as a duplicate.

## Rolling Back an Import

If you imported transactions by mistake or with incorrect settings, you can undo the entire import:

1. Navigate to **Import**
2. Find the import in your import history
3. Click **Rollback** to delete all transactions that were created by that import

Rolling back removes only the transactions from that specific import. Transactions you entered manually or imported separately are not affected.

> **Warning:** If you have edited any imported transactions (changed categories, amounts, etc.) since the import, those edits will be lost when you roll back.

## Tips

- The first row of a CSV file must contain column headers. Files without headers cannot be mapped correctly.
- CSV encoding is auto-detected for common Western encodings (ISO-8859-1, Windows-1252, ISO-8859-15). For other encodings, save as UTF-8 before importing.
- For large imports, the preview shows a sample of rows. Scroll through to verify different transaction types are parsed correctly.
- Import into the correct account before clicking **Execute Import** -- transactions cannot be moved between accounts after import.
- Use [Import Rules](rules.md) to automatically categorize transactions after import, saving you from manually categorizing each one.

## Related Features

- [Import Rules](rules.md) -- Create rules to auto-categorize imported transactions by matching description or vendor patterns
- [Transactions](transactions.md) -- View, edit, and manage all your transactions including imported ones
- [Accounts](accounts.md) -- Set up accounts that correspond to your bank accounts before importing

## Settings

- **Auto-apply import rules** -- When enabled, import rules are applied automatically to new transactions during import. Disable this if you prefer to review transactions before categorizing.
- **Skip duplicate transactions** -- Controls whether duplicate detection is active during import. Enabled by default.

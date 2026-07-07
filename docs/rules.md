# Import Rules

> Auto-categorize imported transactions using pattern-based rules with a visual query builder, so you spend less time manually sorting transactions and more time understanding your finances.

## Overview

Import Rules let you define conditions that automatically assign categories, tags, vendors, and other fields to transactions as they are imported. Rules use a visual query builder with boolean logic, support multiple actions per rule, and can be previewed or applied retroactively to existing transactions.

## Creating a Rule

1. Navigate to **Budget > Rules**
2. Click **Add Rule**
3. Give the rule a descriptive name (e.g., "Amazon purchases", "Monthly rent")

## Building Criteria

Each criterion consists of three parts:

| Component | Options |
|-----------|---------|
| **Field** | Description, Vendor, Amount, Date, Reference, Notes, Transaction Type |
| **Match type** | Contains, Equals, Starts with, Ends with, Regex, Greater than, Less than |
| **Pattern** | The value to match against |

> **Tip:** Use "Contains" for most text matching. Reserve "Regex" for complex patterns where simpler match types won't suffice.

The **Transaction Type** field lets you filter by Income or Expense. This is useful when the same description appears as both inflow and outflow (e.g., internal transfers between accounts).

## Boolean Logic

Combine multiple conditions using logical operators:

- **AND** — all conditions must be true
- **OR** — at least one condition must be true
- **NOT** — negate a condition or group

Groups can be nested to unlimited depth for complex logic. For example:

```
(vendor contains "Amazon" AND amount > 50)
OR
(description contains "Prime")
```

This matches any Amazon purchase over £50, or any transaction mentioning "Prime" regardless of vendor or amount.

## Actions

When a rule matches a transaction, one or more actions are applied:

| Action | Effect |
|--------|--------|
| Set category | Assign a specific category |
| Set vendor | Set the vendor/payee field |
| Set notes | Add or replace notes |
| Add tags | Attach tags from your tag sets |
| Set account | Assign to a specific account |
| Set type | Mark as expense or income |
| Set reference | Set the reference field |
| Exclude from Forecast | Mark the transaction as extraordinary so it stays out of [forecast](forecast.md) projections |
| Auto-Link as Transfer | Find and link a matching opposite transaction as a transfer |

You can configure multiple actions per rule — for example, set the category to "Subscriptions" and add a "streaming" tag simultaneously.

### Auto-Link as Transfer

The **Auto-Link as Transfer** action automatically finds a matching transaction on a different account — same amount, opposite type (income vs expense), within a few days — and links the two as a transfer. This is particularly useful for banks that represent internal movements as separate income/expense entries (e.g., Monzo pot transfers).

Example rule for automatic transfer linking:
- **Criteria:** Transaction Type is Expense AND Description contains "Pot Transfer"
- **Action:** Auto-Link as Transfer

This works when applying rules manually, during CSV import, and during bank sync.

## Priority

Each rule has a priority number. **Higher priority numbers run first.** When multiple rules match the same transaction, the highest-priority rule wins for any conflicting actions.

> **Tip:** Use priority strategically — give specific rules (e.g., "Netflix subscription") a higher priority than general rules (e.g., "All entertainment vendors").

## Behavior Settings

Control how actions interact with existing field values:

| Behavior | Effect |
|----------|--------|
| **Always** | Overwrite existing value |
| **If empty** | Only set the field if it is currently blank |
| **Append** | Add to the existing value (text fields) |
| **Merge** | Combine with existing values (for tags) |

> **Note:** "If empty" is useful when you want manual categorization to take precedence over rules.

## Preview Matches

Before saving a rule, click **Preview** to see which existing transactions in your account would match the criteria. This lets you verify the rule behaves as expected without making any changes.

> **Tip:** If the preview shows unexpected matches, refine your criteria — add more conditions or switch to a more specific match type.

## Run on Existing Transactions

Rules normally apply during import, but you can also apply them retroactively:

1. Open the rule you want to apply
2. Click **Run Rule Now**
3. Optionally restrict to uncategorized transactions only
4. Confirm to apply

> **Warning:** Running a rule with "Always" behavior on existing transactions will overwrite any manual categorization you've previously done on matching transactions.

## Editing a Rule as JSON

Every rule can be edited as raw JSON instead of clicking through the visual builder — useful for power users who prefer to type, and for copying a rule between instances.

At the top of the rule editor, switch from **Builder** to **JSON**. The current rule is shown as an editable JSON document (its criteria, actions, and settings). Edit it freely, or paste a rule copied from elsewhere, then:

- **Save** — the JSON is checked when you save; if the structure is wrong (an unknown field, an invalid match type, a missing category) the error is shown so you can fix it. Switching back to **Builder** first re-parses the JSON and repopulates the visual widgets, which is a quick way to sanity-check it.
- **Copy JSON** — copies the document to your clipboard so you can share a rule or move it to another Budget instance.

> **Note:** Category and account IDs in the JSON are specific to the instance the rule came from. When you paste a rule into a different instance, point those IDs at the matching categories/accounts there (or set them in the Builder afterwards).

## Related Features

- [Import](import.md) — rules are applied automatically during the import process
- [Transactions](transactions.md) — rules modify transaction fields
- [Categories](categories.md) — the most common rule action is setting a category
- Tags — rules can add tags from your configured tag sets

## Settings

- **Auto-apply import rules** — toggle whether rules run automatically on new imports (on/off). When disabled, you must run rules manually.

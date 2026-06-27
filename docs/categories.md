# Categories

> Categories organize your transactions into a hierarchical tree of expense and income types. Each transaction is assigned to a category so you can track where your money goes and where it comes from.

## Overview

Categories are the foundation of budgeting in the Budget app. Every transaction belongs to a category, and categories are divided into two types:

- **Expense categories** -- money going out (groceries, rent, entertainment)
- **Income categories** -- money coming in (salary, freelance, dividends)

Categories can be nested to any depth, letting you organize spending as broadly or granularly as you need. For example, you might have a top-level **Food** category with children **Groceries**, **Restaurants**, and **Coffee**.

## Creating Categories

To create a new category:

1. Navigate to **Categories**
2. Click **Add Category**
3. Fill in the details:

| Field | Required | Description |
|-------|----------|-------------|
| **Name** | Yes | Display name for the category |
| **Type** | Yes | Expense or Income |
| **Parent** | No | Place this category under an existing one |
| **Icon** | No | Emoji or icon to visually identify the category |
| **Color** | No | Color used in charts and reports |

> **Tip:** Assign distinct colors to your top-level categories. Child categories inherit their parent's color in charts, so setting colors at the top level keeps your reports visually consistent.

## Category Hierarchy

Categories support unlimited nesting depth. Child categories roll up into their parent for reporting purposes -- when you view spending on **Food**, you see the combined total of **Groceries**, **Restaurants**, and any other children.

Common hierarchy patterns:

```
Housing
  Rent / Mortgage
  Utilities
  Insurance
  Maintenance

Food
  Groceries
  Restaurants
  Coffee

Transportation
  Fuel
  Public Transit
  Parking
```

> **Note:** A category's type (expense or income) is determined by its top-level ancestor. All children under an expense category are expense categories; the same applies for income.

## Drag-and-Drop Reordering

You can reorder categories by dragging them within the category tree:

- **Reorder within the same level** -- Drag a category up or down among its siblings to change the display order
- **Move to a different parent** -- Drag a category onto another category to make it a child of that category
- **Promote to top level** -- Drag a child category to the root level to make it a top-level category

Changes are saved automatically as you drop.

## Default Categories

When you first set up the Budget app, the **Setup Wizard** offers to create a default set of categories based on the 50/30/20 budgeting rule:

**Needs (50%)**
- Housing
- Food
- Transportation
- Healthcare
- Subscriptions

**Wants (30%)**
- Entertainment
- Shopping
- Personal

**Savings (20%)**
- Savings

**Income**
- Salary
- Freelance
- Investments
- Other Income

> **Tip:** The defaults are a starting point. Rename, reorganize, or delete them to match your actual spending patterns. You can always create new categories later.

## Category Details

Click any category to open its detail view, which shows:

- **Total spending** -- Lifetime total for this category
- **Average** -- Average monthly spending
- **This month** -- Spending in the current month
- **Trend** -- Whether spending is increasing, decreasing, or stable compared to recent months
- **Monthly spending chart** -- A bar chart showing spending over time
- **Recent transactions** -- The most recent transactions assigned to this category

This view helps you quickly understand your spending patterns for any category without navigating to the full reports.

## Shared Categories

If another user shares categories with you, they appear in your category tree with a badge naming the owner:

- **"Shared · &lt;owner&gt;"** -- read-only. You can classify transactions with it but cannot change the category itself.
- **"Shared (editable) · &lt;owner&gt;"** -- shared with **Edit** permission. You can rename it and change its colour.

A shared category's type, parent, budget, report visibility and deletion always remain with its owner. See [Sharing](sharing.md#shared-categories) for the full details.

## Deleting Categories

To delete a category, open it and click **Delete**.

> **Warning:** You cannot delete a category that has transactions assigned to it. Reassign or delete those transactions first, then delete the category.

When you delete a parent category, all of its child categories are also deleted (cascade delete). Make sure none of the children have transactions assigned before deleting a parent.

> **Tip:** If you want to stop using a category without deleting it, consider moving it under a "Retired" parent category instead. This preserves your historical data while keeping your active category list clean.

## Transaction Counts

Each category in the tree displays a count of how many transactions are assigned to it. This count includes only direct transactions -- it does not roll up transactions from child categories.

The count helps you quickly identify:

- Categories that are heavily used and might benefit from being split into subcategories
- Categories with zero transactions that could be cleaned up or removed

## Related Features

- [Budget](budget.md) -- Set monthly spending targets for each category
- [Tags](tags.md) -- Add additional classification dimensions to categories with tag sets
- [Transactions](transactions.md) -- Assign categories to individual transactions
- [Import Rules](rules.md) -- Automatically categorize imported transactions based on patterns

## Settings

There are no dedicated settings for categories. Category behavior is managed directly through the category tree interface.

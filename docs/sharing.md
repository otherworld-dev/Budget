# Sharing

> Share your budget data with other Nextcloud users for household or team financial management.

## Overview

The Sharing feature allows you to grant other Nextcloud users access to your financial data. This is useful for households managing joint finances, teams tracking shared budgets, or accountants who need visibility into a client's accounts.

Shared users can view your accounts, transactions, and reports based on the permission level you assign.

## Adding a Share

To share your budget data with another user:

1. Navigate to the **Sharing** page from the sidebar
2. In the search field, type the name or username of a Nextcloud user
3. A dropdown will appear with matching users on your instance
4. Select the user you want to share with
5. The user will immediately gain access based on the default permission level

> **Note:** You can only share with users who have accounts on the same Nextcloud instance. The user does not need to have the Budget app enabled themselves.

## Permission Levels

When you share your budget with another user, they receive access according to defined permission levels:

| Permission | Description |
|------------|-------------|
| **View** | Can see accounts, transactions, categories, and reports. Cannot make any changes. |
| **Edit** | Can add and modify transactions, categories, and other data. Cannot delete accounts or manage settings. |
| **Full Access** | Can perform all actions except removing the share itself or accessing security settings. |

> **Tip:** Start with **View** access and increase permissions as needed. You can always change the permission level later.

## Shared Categories

Categories shared with you appear on your **Categories** page alongside your own, each marked with a badge naming the owner:

- **"Shared · &lt;owner&gt;"** — read-only. You can still use the category to classify transactions, but you cannot change the category itself.
- **"Shared (editable) · &lt;owner&gt;"** — shared with **Edit** permission. You can **rename** it and change its **colour** directly from the Categories page.

Even with Edit permission, some aspects of a category stay with its owner: the **type** (income or expense), the **parent** category, the **budget**, whether it is **excluded from reports**, and **deleting** it. These change how the owner's budget and reports are calculated, so they remain owner-controlled.

## Managing Shares

To view and manage your active shares:

1. Go to the **Sharing** page
2. You will see a list of all users you have shared with, along with their current permission level
3. To change permissions, select a different level from the dropdown next to the user's name
4. To remove access, click the **Remove** button next to the share entry

When you remove a share, the user loses access immediately. No data is deleted from your account.

> **Warning:** Removing a share cannot be undone from the shared user's perspective. They will lose access to all your budget data instantly.

## Dashboard Integration

When you receive shared access to another user's accounts, those accounts are automatically included in your [Dashboard](dashboard.md) summary. This means:

- **Net Worth** includes balances from shared accounts
- **Income/Expenses This Month** includes transactions from shared accounts
- **Currency breakdowns** reflect shared account currencies
- **Account count** includes shared accounts

The **Recent Transactions** widget also displays transactions from shared accounts.

## Related Features

- [Shared Expenses](shared-expenses.md) - Track and split expenses with contacts
- [Settings](settings.md) - Configure app preferences and security

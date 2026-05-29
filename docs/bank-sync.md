# Bank Sync

> **BETA** - Connect external bank accounts for automatic transaction imports. Must be enabled by a Nextcloud administrator.

## Overview

Bank Sync allows you to connect your real bank accounts to the Budget app and automatically import transactions. Instead of manually uploading CSV or OFX files, transactions flow in directly from your bank.

> **Note:** This is a beta feature and may have rough edges. If you encounter issues, please report them on the [GitHub issue tracker](https://github.com/otherworld-dev/budget/issues).

## Enabling Bank Sync (Admin)

Bank Sync must be enabled by a Nextcloud administrator before any user can access it:

1. Log in as a Nextcloud admin
2. Open the Budget app and go to **Settings**
3. Scroll down to the **Admin Settings** section
4. Toggle **Enable Bank Sync** to on

> **Warning:** This is an experimental feature. The warning banner displayed in Admin Settings explains that bank connectivity relies on third-party services and may experience interruptions or breaking changes.

Once enabled, all users on the instance will see the Bank Sync option in their account management interface.

## Supported Providers

Bank Sync supports two providers, each covering different regions:

| Provider | Region | Connection Method |
|----------|--------|-------------------|
| **GoCardless** | UK and European banks | OAuth authorization flow |
| **SimpleFIN** | US banks | Token-based authentication |

> **Tip:** If your bank isn't listed under one provider, try the other. Coverage varies and is continuously expanding.

## Connecting via GoCardless

GoCardless uses a 3-step wizard to guide you through connecting a UK or European bank account.

### Step 1: Enter API Credentials

1. Navigate to your accounts list and select **Add Bank Connection**
2. Choose **GoCardless** as the provider
3. Enter a **Connection Name** (a label for your reference, e.g. "Main Bank")
4. Enter your **Secret ID** and **Secret Key** from your GoCardless account
5. Click **Next** — the app validates your credentials before proceeding

### Step 2: Select Your Bank

1. Your country is auto-detected from your Nextcloud locale, but you can change it from the dropdown
2. Browse the grid of available banks (each shown with its logo) or use the search bar to find yours
3. Click your bank to select it
4. Click **Connect** to begin the authorization process

### Step 3: Authorize at Your Bank

1. A new browser window opens, directing you to your bank's authorization page
2. Log in to your bank and grant read access to your accounts and transactions
3. Once you have completed the authorization at your bank, return to the Budget app and click **I've Completed Authorization**
4. The app checks the authorization status with GoCardless
5. On success, your discovered bank accounts are displayed for mapping

> **Note:** GoCardless connections typically remain valid for 90 days under PSD2 regulations before requiring re-authorization. See [Re-Authorization](#re-authorization-gocardless) below.

### Pending Authorization Status

If you close the wizard before completing Step 3, the connection is saved with an **Awaiting Authorization** status. You can return later to finish the process. Connections in this state are skipped by the daily background sync — only fully authorized connections are synced automatically.

### Re-Authorization (GoCardless)

GoCardless connections expire after approximately 90 days due to PSD2 regulations. When a connection expires:

1. The connection shows an **Expired** status with a **Re-authorize** button
2. Click **Re-authorize** to open the connection wizard
3. Walk through the same 3-step flow: enter your API credentials (Step 1), select your bank (Step 2), and authorize at your bank (Step 3)
4. Once re-authorized, the connection resumes normal operation

There is no need to disconnect and reconnect — re-authorization preserves your existing account mappings and transaction history.

## Connecting via SimpleFIN

To connect a US bank account:

1. Navigate to your accounts list and select **Add Bank Connection**
2. Choose **SimpleFIN** as the provider
3. Obtain a SimpleFIN token from [simplefin.org](https://simplefin.org) (requires a SimpleFIN Bridge subscription)
4. Paste your token into the token field
5. Click **Connect**
6. The app claims the token and fetches your available accounts

> **Note:** SimpleFIN tokens are single-use for claiming. Once claimed by the Budget app, the same token cannot be used elsewhere.

Each sync fetches the last 90 days of transactions. This includes credit card accounts, which require a date range parameter to return transaction data.

## Account Mapping

After connecting a bank, you must map external accounts to local Budget accounts:

1. A list of discovered external bank accounts is displayed
2. For each external account, select an existing local Budget account from the dropdown, or create a new one
3. Only mapped accounts will have their transactions synced
4. Unmapped accounts are ignored during sync

> **Tip:** You can change account mappings at any time. Transactions already imported will remain in their original local account.

### Refresh Accounts

A **Refresh** button appears in the account mappings section for each connection. Clicking it re-fetches the account list from your bank provider, which is useful when:

- You have opened a new bank account since you first connected
- An account was not discovered during the initial connection

Refreshing does not trigger a full transaction sync — it only updates the list of available external accounts for mapping.

## Syncing Transactions

Transactions are synced in three ways:

- **Manual sync** - Click the **Sync** button on a connection to immediately fetch new transactions for that connection
- **Sync All** - When you have two or more active connections, a **Sync All** button appears. It syncs all connections sequentially, showing progress as it goes (e.g. "Syncing 1 of 3..."). When complete, it displays aggregated results across all connections.
- **Automatic sync** - A background job runs daily to sync all mapped accounts automatically. Connections with a pending or expired authorization status are skipped.

Duplicate detection prevents the same transaction from being imported twice. Each synced transaction carries a unique import ID from the bank, which is checked against existing transactions before importing.

> **Note:** The first sync may take longer as it imports your recent transaction history. Subsequent syncs only fetch new transactions.

## Security

Bank Sync takes security seriously:

- All connection credentials (tokens, access keys) are **encrypted at rest** using Nextcloud's built-in encryption service
- Credentials are **never exposed in API responses** - the app only confirms whether a connection exists
- Communication with bank providers uses encrypted HTTPS connections
- You can revoke access at any time by disconnecting (see below)

> **Tip:** Review your connected banks periodically and disconnect any you no longer use.

## Disconnecting

To remove a bank connection:

1. Go to the account management page
2. Find the bank connection you want to remove
3. Click **Disconnect**
4. Confirm the action

When disconnecting:

- The external link and stored credentials are permanently deleted
- All previously imported transactions **remain in your local accounts**
- You can reconnect the same bank later, but it will be treated as a new connection

## Troubleshooting

Common issues and solutions:

| Issue | Solution |
|-------|----------|
| Bank Sync option not visible | Ask your Nextcloud admin to enable it in **Settings > Admin Settings** |
| OAuth redirect fails | Ensure your Nextcloud instance URL is publicly accessible and HTTPS is configured correctly |
| GoCardless bank not found | Try searching by the bank's official name rather than a nickname. Not all institutions are supported |
| SimpleFIN token rejected | Tokens are single-use. Generate a new token from simplefin.org if the previous one was already claimed |
| Sync shows no new transactions | Check that the account is mapped. Unmapped accounts are skipped during sync. For SimpleFIN credit cards, ensure you are running v2.27.1+ which includes the required date range parameter |
| Transactions appear duplicated | This should not happen due to import ID tracking. File a bug report with details |
| Connection expired | GoCardless connections expire after ~90 days. Click **Re-authorize** on the expired connection and complete the 3-step wizard |
| Connection stuck on "Awaiting Authorization" | Complete the bank authorization step. Open the connection and follow the wizard from where you left off |
| Daily sync not running | Ensure Nextcloud background jobs (cron) are configured and running on your server |

> **Note:** If you encounter persistent issues, check your Nextcloud log file for error messages related to Bank Sync and include them when reporting bugs.

## Related Features

- [Accounts](accounts.md) - Managing your local budget accounts
- [Transactions](transactions.md) - Working with imported transactions
- [Import](import.md) - Manual file-based import as an alternative
- [Settings](settings.md) - Admin toggle and general configuration

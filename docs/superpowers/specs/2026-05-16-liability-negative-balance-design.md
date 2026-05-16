# Liability Accounts: Negative Balance Storage Model

**Date:** 2026-05-16
**Issue:** otherworld-dev/budget#187
**Status:** Approved

## Problem

Liability accounts (loan, credit card) store positive balances representing "amount owed" (e.g., loan balance = 10000). But the balance engine universally applies `credit = add, debit = subtract`. This means a loan payment (credit of $500) increases the balance from 10000 to 10500, instead of decreasing it to 9500.

## Solution

Store liability balances as negative numbers internally. A loan with $10,000 owed stores `balance = -10000`. The universal formula `credit = add, debit = subtract` then works correctly for all accounts:

- **Asset** (checking, $5000): Payment received (credit $100) → 5000 + 100 = 5100 ✓
- **Liability** (loan, -$10000): Payment made (credit $500) → -10000 + 500 = -9500 ✓
- **Liability** (credit card, -$2000): New charge (debit $50) → -2000 - 50 = -2050 ✓

The display layer shows `abs(balance)` with context (e.g., "10,000 owed") — this already exists in the frontend.

## What Does NOT Change

- `TransactionService::updateAccountBalance()` — unchanged, formula is now universally correct
- All `TransactionMapper` SQL queries (net change, pagination, trends) — unchanged
- Forecast projections — arithmetic is sign-agnostic
- Interest service — already designed for negative liability balances
- Transfer logic — credit/debit types are absolute
- Reconciliation, currency conversion, savings goals, shared expenses — unaffected

## What Changes

### 1. AccountType Enum (`lib/Enum/AccountType.php`)

Add new cases and update helpers:
- Add `MORTGAGE = 'mortgage'` and `LINE_OF_CREDIT = 'line_of_credit'`
- Update `isLiability()` to include them
- Update `label()` and `supportsInterest()`

### 2. Account Creation (`lib/Controller/AccountController.php`)

When creating or updating a liability account, negate the opening balance before storage:
- User enters `10000` → store as `-10000` for `balance` and `opening_balance`
- Only apply when type is liability AND the value is positive (user input convention)

### 3. Account Update — Opening Balance (`lib/Service/AccountService.php`)

When `openingBalance` is updated on a liability account, negate before storing and recalculating.

### 4. Net Worth Service (`lib/Service/NetWorthService.php`)

Currently negates liability balances (since they're positive). Remove the negation — they're already negative, so just add them directly. Specifically:
- Change `$totalLiabilities -= $balance` to `$totalLiabilities += $balance`
- Keep the interest handling (subtracting interest from a negative balance makes it more negative — correct)

### 5. Report Aggregator (`lib/Service/Report/ReportAggregator.php`)

Same double-negation fix as net worth:
- Change `$totalLiabilities -= $currentBalance` to `$totalLiabilities += $currentBalance`

### 6. Data Migration (`lib/Migration/Version*.php`)

A database migration that:
1. Selects all accounts where `type IN ('credit_card', 'loan', 'mortgage', 'line_of_credit')`
2. Where `balance > 0` OR `opening_balance > 0`
3. Sets `balance = -balance` and `opening_balance = -opening_balance`

This runs automatically on app upgrade.

### 7. Export/Import (`lib/Service/MigrationService.php`)

- Bump export version to `1.1.0`
- On import, if version < `1.1.0`, negate positive liability balances before processing

### 8. Bank Sync Balance Update (`lib/Service/BankSync/BankSyncService.php`)

The `lastBalance` stored on bank account mappings is display-only metadata — not used for account balance calculations. No change needed. Account balances are updated via `TransactionService::create()` which now works correctly.

### 9. Frontend — Account Type Dropdown (`templates/index.php`)

Add `mortgage` and `line_of_credit` to the account type `<select>` options so users can create these account types.

### 10. Frontend — Account Type Info (`src/modules/accounts/AccountsModule.js`)

Add `mortgage` and `line_of_credit` to the `getAccountTypeInfo()` map and any `isLiability` checks.

## Non-Goals

- No changes to the balance engine (`updateAccountBalance`, `getNetChangeAll`, etc.)
- No changes to report/spending queries (they measure cash flow, not balance)
- No changes to forecast, interest, debt payoff, reconciliation

## Data Safety

- The migration is idempotent: only negates balances that are positive on liability accounts
- A balance of 0 remains 0
- Accounts that somehow already have negative balances (data corruption) are not touched (WHERE balance > 0)
- The migration runs within a transaction for atomicity

## Verification

After deployment:
1. Create a loan account with opening balance 10000 → verify stored as -10000, displayed as "10,000"
2. Transfer $500 from checking to loan → verify loan shows 9,500 (stored as -9500)
3. Net worth = checking balance - loan balance (positive net if checking > loan)
4. Run "Repair → Fix Balance Drift" → verify no changes (balances already correct)

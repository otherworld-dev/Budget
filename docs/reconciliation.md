# Statement Reconciliation

> Reconciling means confirming, statement by statement, that the app and your bank agree. Enter the statement's ending balance, tick each transaction that appears on it, and finish when the difference reaches zero. Completed reconciliations form a permanent history per account.

## Starting a Reconciliation

From **Accounts > (account) > Reconcile**, or the reconcile button on the Transactions view:

1. Pick the account, enter the **statement ending balance** and **statement date**, and start.
2. The session bar appears at the bottom with four numbers:

| Number | Meaning |
|--------|---------|
| **Statement balance** | What your bank says the account held at the statement date. |
| **Starting balance** | Where the last reconciliation left off (first time: opening balance plus everything already marked reconciled). Verify it matches your statement's *opening* balance. |
| **Ticked** | The net of the transactions you've checked off so far. |
| **Difference** | `statement − (starting + ticked)`. Reconciled when this is **0.00**. |

3. Tick each transaction that appears on the statement using the row checkboxes — the difference updates live.
4. When the difference is zero, **Finish** marks everything ticked as reconciled and stamps the account.

## If It Doesn't Balance

- A transaction on the statement that's missing in the app? Add it normally, then tick it.
- Stubborn small differences (bank fees, interest you never recorded) — **Create adjustment** makes a balancing transaction and ticks it automatically.
- Not done? **Leave** keeps the session exactly as it is; a banner offers to resume next time you view that account's transactions. **Cancel session** releases everything ticked (nothing gets marked reconciled).

## After Reconciling

- Completed sessions appear under **Reconciliation History** on the account's detail page (statement date, balance, transaction count).
- Reconciled transactions are protected by a warning: editing a balance-affecting field (amount, type, account, date) or deleting one prompts first, since it would make past reconciliations no longer match — and the change is recorded in the [audit log](settings.md).
- The next reconciliation starts from this statement's balance, so statements chain cleanly month to month.

## Tips

- Reconcile in statement order — each session anchors on the previous one.
- Older uncleared transactions you didn't tick stay unreconciled and are counted in the completion message; they remain candidates for the next session.
- One session per account at a time; different accounts can have sessions in parallel.

## Related Features

- [Accounts](accounts.md) — opening balances and account detail view
- [Transactions](transactions.md) — the transaction list where ticking happens
- [Import](import.md) — imported statements pair naturally with reconciliation

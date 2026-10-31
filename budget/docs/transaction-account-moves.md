# Moving a transaction through the public API

Native clients can change the account of an existing transaction with:

```http
PATCH /ocs/v2.php/apps/budget/api/v1/transactions/123
OCS-APIRequest: true
Content-Type: application/json

{"account_id": 42}
```

The response is the updated transaction: `id` remains `123`, and `account_id`
becomes `42`. The destination must be visible to the caller, writable, open,
and have the same owner and currency as the source. No currency conversion
or cross-owner migration is performed.

The ID, notes, receipt attachment links, split parts and retained tags stay
with the transaction. Both account balances are recalculated from their
ledgers. Categories (including split categories) and tags must remain usable
by the same ledger owner. A category tag already attached to the transaction
may stay after a category edit, following the existing tag retention rules.
If metadata cannot be retained, the edit fails without dropping it.

An account change on either side of a transfer is refused. A reconciled
transaction first returns `409` with `error_code: reconciled`. After asking
the user, a client may repeat the move with `confirm_reconciled: true`. This
marks the transaction unreconciled, clears its old statement session and
records the change in the audit log, including the old session. It can then
be reconciled in its destination account.

A transaction ticked into an unfinished reconciliation cannot move, even
with confirmation. Untick it or cancel that session first, so completing
the source statement cannot reconcile a row in the destination account.

| HTTP status | `error_code` | Meaning |
| --- | --- | --- |
| 400 | `invalid_account` | `account_id` is not a positive integer. |
| 400 | `account_closed` | Reopen the destination account before moving. |
| 400 | `account_owner_mismatch` | Both accounts must have the same owner. |
| 400 | `account_currency_mismatch` | Both accounts must use the same currency. |
| 400 | `invalid_category` | A retained category is unavailable to the ledger owner. |
| 400 | `invalid_tags` | Tags cannot be retained or the requested replacement is invalid. |
| 403 | — | The caller cannot write to the source or destination. |
| 404 | — | The transaction or destination is missing or not visible to the caller. |
| 409 | `transfer_leg` | A transfer leg cannot move on its own. |
| 409 | `reconciled` | Explicit confirmation is required. |
| 409 | `reconciliation_in_progress` | Untick the transaction from its current reconciliation session first. |

A move and any other fields or `tag_ids` sent with it form one atomic edit:
transaction, balances, split rescaling, audit entries and tags all commit or
roll back together. Requests without `account_id` keep their existing behavior.
Sending the same account ID again is a no-op and requires no confirmation,
even for a reconciled transaction. `status` and `reconciled` remain protected
from direct changes through PATCH; use the reconciliation endpoints.

See [`openapi.json`](../openapi.json) for the complete request and response schema.

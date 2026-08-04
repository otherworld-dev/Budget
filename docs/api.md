# REST API

> A documented, versioned REST API for reading your budget and recording transactions from outside the web UI — a phone app, a script, or an automation platform like n8n. It uses standard Nextcloud app passwords, so nothing new to set up and nothing extra to revoke.

## Overview

The API lives at:

```
https://<your-nextcloud>/ocs/v2.php/apps/budget/api/v1
```

It is an [OCS](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/index.html) API — the same style Nextcloud's own clients use. That means two things in practice: every response is wrapped in an `ocs` envelope, and every request must carry the `OCS-APIRequest: true` header.

Version 1 is deliberately small. It covers reading your accounts and categories, browsing recent transactions, and recording a new transaction with a receipt photo. Editing, deleting, and everything else stays in the web UI — see [What v1 does not do](#what-v1-does-not-do).

A machine-readable **OpenAPI 3.0 description** ships with the app at `openapi.json` in the app directory, and is on GitHub at [otherworld-dev/budget](https://github.com/otherworld-dev/budget/blob/master/budget/openapi.json) — point a client generator at it if you would rather not write the HTTP calls by hand.

## Authentication

Use a **Nextcloud app password**, not your login password.

1. Open your Nextcloud **personal settings > Security**.
2. Under *Devices & sessions*, type a name (e.g. `Budget on my phone`) and click **Create new app password**.
3. Copy the generated password. It is shown once.

Authenticate with HTTP Basic auth: your Nextcloud username plus that app password. Revoking the entry in *Devices & sessions* immediately cuts off whatever was using it, without touching your account.

Apps can also obtain a token through Nextcloud's standard [Login flow v2](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/LoginFlow/index.html) — the browser-based flow where the user approves the app in Nextcloud rather than typing credentials into it. The resulting token is used exactly like an app password.

Two-factor authentication does not get in the way: app passwords bypass it by design.

### Required headers

| Header | Value | Why |
|--------|-------|-----|
| `OCS-APIRequest` | `true` | Required on every request. Without it Nextcloud answers `412 Precondition Failed`. It is also what stops a malicious web page from calling this API with your browser session. |
| `Accept` | `application/json` | Without it you get XML. |

### A first request

```bash
curl -u 'USER:APP_PASSWORD' \
     -H 'OCS-APIRequest: true' \
     -H 'Accept: application/json' \
     'https://cloud.example.com/ocs/v2.php/apps/budget/api/v1'
```

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
    "data": {
      "apiVersion": "1.0",
      "appVersion": "2.40.0",
      "userId": "alice",
      "baseCurrency": "GBP",
      "features": {
        "accounts": true,
        "categories": true,
        "transactions": true,
        "createTransaction": true,
        "receiptUpload": true,
        "receiptOcr": false
      },
      "limits": {
        "maxReceiptBytes": 26214400,
        "receiptMimeTypes": ["image/jpeg", "image/png", "image/webp", "image/heic", "application/pdf"],
        "receiptOcrMimeTypes": ["image/jpeg", "image/png", "image/webp"],
        "transactionsMaxLimit": 200
      }
    }
  }
}
```

Call this endpoint first. It confirms the app is installed and your credentials work, and it tells you which optional features this particular server has — so a client can hide a flow the server cannot serve instead of failing halfway through it. `receiptOcr` is `true` only when the administrator has [configured an OCR provider](receipt-scanning.md); it can flip either way as the server's configuration changes, so re-check it rather than caching it forever.

## Responses

Everything is wrapped:

```json
{ "ocs": { "meta": { "status": "ok", "statuscode": 200, "message": "OK" }, "data": ... } }
```

The payload you want is always `ocs.data`. The HTTP status matches `ocs.meta.statuscode`, so you can check either.

| Status | Meaning |
|--------|---------|
| `200` | Success. |
| `201` | Created — returned by the recording `POST`s (`/transactions` and receipt upload). The extract endpoint returns `200`: it creates nothing. |
| `400` | Invalid input. `ocs.data.error` says what. |
| `401` | Not authenticated, or the app password has been revoked. |
| `403` | You can see that account but cannot write to it (a read-only [share](sharing.md)). |
| `404` | No such transaction, or one you cannot see. |
| `412` | The `OCS-APIRequest: true` header is missing. |
| `429` | Rate limited — see [Rate limits](#rate-limits). |

Errors carry a human-readable message:

```json
{ "ocs": { "meta": { "status": "failure", "statuscode": 400, "message": "" },
           "data": { "error": "Date must be in YYYY-MM-DD format" } } }
```

## Money

**Every amount is a string, never a JSON number** — `"42.50"`, `"-1246.50"`, `"0.00"`. Always two decimal places, and no currency symbol or thousands separator.

This is deliberate. Budget stores money as an exact decimal and does its arithmetic accordingly, so a penny cannot go astray. JSON numbers are floating point in most parsers, which would quietly undo that at the last step: add `0.1` and `0.2` as JSON numbers and you get `0.30000000000000004`.

Parse them into whatever exact decimal type your language offers — `BigDecimal` in Java and Kotlin, `decimal` in C#, `decimal.Decimal` in Python, `BigDecimal` in Ruby. If you are only displaying the figure, print it as it arrives. Avoid parsing amounts into a float or a double, and never do money arithmetic in one.

The currency itself is a property of the **account**, not the amount: read `currency` from `GET /accounts`, or `accountCurrency` on a transaction from a list response.

When sending an amount, either form is accepted — `amount=42.50` and `amount=42.5` mean the same thing. It always comes back as `"42.50"`.

## Endpoints

All paths below are relative to `/ocs/v2.php/apps/budget/api/v1`.

### `GET /` — server info

Described above. No parameters.

### `GET /accounts`

Every account you own, plus every account [shared with you](sharing.md). Balances are as of today: future-dated transactions are excluded, matching what the web UI shows.

```json
[
  {
    "id": 36,
    "name": "Current Account",
    "type": "checking",
    "currency": "GBP",
    "balance": "-1246.50",
    "balanceInBaseCurrency": null,
    "baseCurrency": null,
    "institution": "NatWest",
    "shared": false,
    "updatedAt": "2026-08-01 03:20:02"
  }
]
```

`balanceInBaseCurrency` and `baseCurrency` are set only when the account is not already in your base currency. `shared` is `true` for accounts someone else shared with you — you may or may not be able to write to them, so handle a `403` when creating.

Account numbers, IBANs, and sort codes are **not** exposed. A capture client does not need them.

### `GET /categories`

Your categories plus any shared with you, as a flat list. Build the tree yourself from `parentId`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | Optional. `expense` or `income`. |

```json
[
  { "id": 415, "name": "Bank Fees", "type": "expense", "parentId": null,
    "icon": null, "color": "#ef4444", "shared": false }
]
```

Note that categories are typed `expense`/`income` while transactions are typed `debit`/`credit`. The two vocabularies are separate; the API passes each through unchanged rather than inventing a third.

### `GET /transactions`

Most recent first, across every account you can see.

| Parameter | Type | Description |
|-----------|------|-------------|
| `accountId` | int | Restrict to one account. |
| `categoryId` | int | Restrict to one category. |
| `dateFrom` | date | Inclusive lower bound, `YYYY-MM-DD`. |
| `dateTo` | date | Inclusive upper bound, `YYYY-MM-DD`. |
| `search` | string | Free-text match on description and vendor. |
| `limit` | int | Default 50, maximum 200. Larger values are clamped, not rejected. |
| `offset` | int | Default 0. |

```json
{
  "transactions": [
    {
      "id": 20070, "accountId": 36, "categoryId": 427,
      "date": "2026-08-08", "description": "Monthly plan", "vendor": "Practice Plan",
      "amount": "15.00", "type": "debit", "reference": null, "notes": null,
      "status": "scheduled", "reconciled": false, "isSplit": false,
      "createdAt": "2026-07-31 22:49:45", "updatedAt": "2026-07-31 22:49:45",
      "accountName": "Current Account", "accountCurrency": "GBP",
      "categoryName": "Health & Fitness"
    }
  ],
  "total": 6346,
  "limit": 50,
  "offset": 0
}
```

`total` is the full match count, not the page size — use it to drive paging. `accountName`, `accountCurrency`, and `categoryName` are conveniences so a list view needs no second round-trip; `categoryName` is absent on uncategorised rows.

`amount` is always positive, and a string — see [Money](#money). `type` carries the direction: `debit` is money out, `credit` is money in.

### `GET /transactions/{id}`

One transaction, from any account you can see. Returns the same shape without the joined name fields. `404` if it does not exist or is not yours.

### `POST /transactions`

Record a transaction. Send `application/x-www-form-urlencoded` or `multipart/form-data`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `accountId` | int | yes | Must be an account you can write to. |
| `date` | date | yes | `YYYY-MM-DD`. A future date is stored as `scheduled`. |
| `description` | string | yes | |
| `amount` | decimal | yes | Always positive. `42.50` or `"42.50"`; returned as a string. |
| `type` | string | yes | `debit` (money out) or `credit` (money in). |
| `categoryId` | int | no | Leave empty to file it as uncategorised. |
| `vendor` | string | no | |
| `reference` | string | no | |
| `notes` | string | no | |

```bash
curl -u 'USER:APP_PASSWORD' \
     -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
     -d accountId=36 -d date=2026-08-01 -d description='Weekly shop' \
     -d amount=42.50 -d type=debit -d vendor=Tesco \
     'https://cloud.example.com/ocs/v2.php/apps/budget/api/v1/transactions'
```

Returns `201` with the created transaction, including its `id`. The account balance is recalculated automatically. Writing into an account someone shared with you records the transaction against **their** ledger, as it should — you get a `403` if the share is read-only.

### `GET /transactions/{id}/receipts`

Receipts attached to a transaction.

```json
[
  { "id": 1, "transactionId": 30159, "fileId": 85, "fileName": "receipt.png",
    "mimeType": "image/png", "createdAt": "2026-08-01 22:25:40", "missing": false }
]
```

`missing` is `true` when the underlying file has been deleted from your Files — the reference survives so you can see something was there.

### `POST /transactions/{id}/receipts`

Attach a receipt photo. Send it as `multipart/form-data` under the field name `file`.

```bash
curl -u 'USER:APP_PASSWORD' \
     -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
     -F 'file=@receipt.jpg' \
     'https://cloud.example.com/ocs/v2.php/apps/budget/api/v1/transactions/30159/receipts'
```

The file is stored in **your own Files** under `Budget/Receipts/<year>/` and referenced from the transaction — it counts against your normal quota and is included in your Files backups. Allowed types and the size cap are reported by `GET /` (currently JPEG, PNG, WebP, HEIC, PDF, up to 25 MB).

These two receipt endpoints are **owner-only**. Receipts live in the owner's Files, which a share recipient cannot resolve, so a transaction in a shared account returns `404` here even though `GET /transactions/{id}` can read it.

### `POST /receipts/extract`

Turn a receipt photo into a **draft transaction** — the capture-before-save flow. Send the image as `multipart/form-data` under `file` (JPEG, PNG or WebP; the size cap is the same 25 MB as uploads). Nothing is recorded: show the draft to the user, let them correct it, then record it with `POST /transactions` and attach the photo with `POST /transactions/{id}/receipts`.

Check `features.receiptOcr` on `GET /` first — it is `true` only when the server's administrator has [set up an OCR provider](receipt-scanning.md). Calling without one configured returns `501`.

```bash
curl -u 'USER:APP_PASSWORD' \
     -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
     -F 'file=@receipt.jpg' \
     'https://cloud.example.com/ocs/v2.php/apps/budget/api/v1/receipts/extract'
```

```json
{
  "merchant": "Tesco Express",
  "date": "2026-08-01",
  "currency": "GBP",
  "total": "9.75",
  "lineItems": [
    { "description": "Milk 2L", "amount": "1.65" },
    { "description": "Bread", "amount": "1.10" }
  ],
  "suggestedCategoryId": 427,
  "suggestedCategoryName": "Groceries",
  "warnings": []
}
```

Every field the provider could not read is `null` (or `[]`) rather than a guess — the user fills those in, exactly as they would have typed the whole thing before. Amounts are [money strings](#money). `currency` is the code printed on the receipt when one was legible, which a client can compare against the target account's currency before saving — note the **Nextcloud AI** provider never reports one (its OCR returns plain text and the parser does not guess currencies), so treat `null` as "unknown", not "same as the account".

The category suggestion is produced **locally** by running your own [rules](rules.md) against the extracted merchant — the provider never sees your categories, accounts, or anything else in your ledger. Only the image is sent to it.

`warnings` is a list of machine-readable flags:

| Warning | Meaning |
|---------|---------|
| `no-total` | No total could be read. |
| `no-date` | No date could be read. |
| `line-items-sum-mismatch` | The line items do not add up to the printed total. The printed total is reported anyway — a till adds better than an OCR reads — but show the user. |

Two statuses are specific to this endpoint:

| Status | Meaning |
|--------|---------|
| `501` | No OCR provider is configured on this server. Tell the user it is not set up, and hide the flow (`features.receiptOcr` is `false`). |
| `502` | The configured provider failed — unreachable, timed out, or returned nonsense. Retrying is safe; extraction has no side effects. |

## Rate limits

| Endpoint | Limit |
|----------|-------|
| `POST /transactions` | 60 per minute, per user |
| `POST /transactions/{id}/receipts` | 10 per minute, per user |
| `POST /receipts/extract` | 10 per minute, per user |

Reads are not rate limited by the app. Exceeding a limit returns `429`; wait and retry.

## Recipes

### n8n — record a transaction

Use the **HTTP Request** node:

- **Method** `POST`, **URL** `https://cloud.example.com/ocs/v2.php/apps/budget/api/v1/transactions`
- **Authentication** > Generic Credential Type > **Basic Auth** (username + app password)
- **Headers**: `OCS-APIRequest: true` and `Accept: application/json`
- **Body Content Type** `Form-Urlencoded`, with `accountId`, `date`, `description`, `amount`, `type`

The response id is at `{{ $json.ocs.data.id }}` — feed it into a second HTTP Request node to attach a receipt.

### Shell — this month's spending

```bash
BASE='https://cloud.example.com/ocs/v2.php/apps/budget/api/v1'
curl -s -u "$USER:$APP_PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  "$BASE/transactions?dateFrom=$(date +%Y-%m-01)&limit=200" |
  jq '[.ocs.data.transactions[] | select(.type == "debit") | (.amount | tonumber)] | add'
```

## Stability

`v1` is a contract. Within it:

- Fields are **added**, never removed or renamed, and a field's type never changes.
- New optional query parameters may appear; existing ones keep their meaning.
- New endpoints may appear under `/api/v1/`.
- `features` in `GET /` may flip from `false` to `true` as capabilities land. Clients must tolerate that.

A breaking change means a new `/api/v2/`, and `v1` keeps working through a deprecation period. Read `apiVersion` from `GET /` rather than assuming.

The internal endpoints the web UI itself calls (under `/apps/budget/api/...`, without `/ocs/`) are **not** covered by any of this. They follow the database, change without notice, and require a browser session. Do not build against them.

## What v1 does not do

| Not available | Use instead |
|---------------|-------------|
| Editing or deleting transactions | The web UI. A capture client only appends; anything it gets wrong is fixable there. |
| Creating accounts or categories | The web UI — see [Accounts](accounts.md) and [Categories](categories.md). |
| Budgets, bills, reports, forecasts | The web UI. |
| Receipt OCR on an unconfigured server | Ask the administrator to [set up a provider](receipt-scanning.md); `features.receiptOcr` reports whether this server has one. |
| Webhooks / push | The API is poll-based by design. To react to changes, poll `GET /transactions` — every few minutes is ample, since transactions arrive at human speed. |

## Troubleshooting

| Symptom | Cause |
|---------|-------|
| `412 Precondition Failed` | The `OCS-APIRequest: true` header is missing. |
| `401` on every request | Wrong username, or the app password was revoked. Note the username is your Nextcloud **user ID**, which is not always your email address. |
| XML instead of JSON | Add `Accept: application/json`. |
| `403` when creating a transaction | That account belongs to someone else and the share is read-only, or is not shared with you at all. |
| `404` listing receipts on a shared transaction | Expected — the receipt endpoints are owner-only. |
| An empty `data` array where you expected records | Check `ocs.meta.statuscode`; a failure still returns HTTP 200 on some proxies. |

## See also

- [Sharing](sharing.md) — how shared accounts affect what the API returns
- [Nextcloud Integration](nextcloud-integration.md) — receipts, calendar feed, dashboard widgets
- [Transactions](transactions.md) — what the fields mean in the UI

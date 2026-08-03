# Receipt Scanning

> Photograph a receipt and have the transaction filled in for you. **Off by default** — a Nextcloud administrator has to turn it on and choose who reads the images.

> **The settings ship ahead of the feature.** This release contains the configuration described on this page; the scan buttons themselves arrive in the next release. Anything you configure now simply takes effect the moment they do — there is nothing else to redo.

## Overview

Receipt scanning takes a photo of a till receipt and turns it into a draft transaction: the merchant, the date, the total, and a suggested category. You check it and save it, or correct it first. Nothing is recorded without you seeing it.

Reading a photograph needs an OCR or vision model, and that model has to run somewhere. This page is mostly about choosing that somewhere, because it decides who else sees your receipts.

> **The short version:** until an administrator configures a provider, this app sends receipt images nowhere at all. There is no default backend and no fallback.

## How the images travel

Whatever you choose, the shape is the same:

```
Your phone or browser  →  your Nextcloud  →  the OCR provider
                                          ←  merchant, date, total
```

Your Nextcloud server makes the request, not your phone and not your browser. Two things follow from that:

- **The credential lives on the server.** No API key is ever stored in the phone app or sent to a browser. An admin who saves a key cannot read it back out — the settings page reports only that one exists.
- **A provider on your own network works.** Because the request comes from the server, a machine that is not reachable from the internet is a perfectly good backend. This is the most private option, and it is deliberately a first-class one rather than a footnote.

Only the image goes to the provider. Your account names, balances, other transactions and category tree are not sent.

## Choosing a provider (admin)

Go to **Settings → Admin Settings → Receipt scanning**. Four options:

| Provider | Where the image goes | Needs |
|----------|---------------------|-------|
| **Off** | Nowhere. | — |
| **Nextcloud AI** | Whatever AI backend this Nextcloud is already set up to use. | An AI app configured on the server |
| **Custom endpoint** | Any OpenAI-compatible server you name. | A URL and a model name |
| **Otherworld relay** | Otherworld's hosted service. | A license key |

### Off

The default. No provider is contacted, the capture flows stay hidden in the app, and the API reports the feature as unavailable so a phone client hides it too rather than failing at the point of use.

### Nextcloud AI

Uses Nextcloud's own AI provider framework, so receipt scanning inherits whatever backend you already chose for the rest of your instance — and whatever privacy policy came with it. If that is a local model, images never leave the server. If it is a cloud service, images go to that company.

If no AI provider is configured on the instance, the option still appears but says so, and receipt scanning stays off until one is set up.

### Custom endpoint

Any server speaking the OpenAI-compatible API. This covers a local [Ollama](https://ollama.com) running a vision model, a self-hosted vLLM, or a commercial API.

You need:

- **Endpoint URL** — the base URL, e.g. `http://192.168.1.10:11434/v1`. Plain `http://` is accepted for local addresses on purpose.
- **Model** — the vision model to ask for, exactly as the endpoint names it, e.g. `qwen2.5vl`. It must be able to read images; a text-only model will not work.
- **API key** — optional. A local Ollama usually needs none; a commercial API will.

A vision model on your own hardware is the only arrangement where receipt images never leave your control at all.

### Otherworld relay

A hosted service run by Otherworld, authenticated with a license key bought from [budget.otherworld.dev](https://budget.otherworld.dev). Images are read and discarded — not stored, and not used to train anything. Use this if you want scanning to work without running or paying for a model yourself.

## The API key

Keys are stored encrypted, using Nextcloud's own encryption, and are never returned to a browser or included in any API response.

Practically, that means the settings field is always blank when you open it:

- **Leave it blank** to keep the key that is already saved.
- **Type a new key** to replace it.
- **Remove key** to delete it. Scanning stops until a new one is saved.

An admin who has lost a key re-enters it rather than reading it back. That is deliberate.

## Privacy, plainly

- While the provider is **Off**, this app makes no external calls for receipts under any circumstances.
- Only the receipt image is sent — never your ledger.
- The image is sent by your server, so with a local provider nothing leaves your network.
- With **Nextcloud AI**, the destination is whatever your instance is configured to use; check that app's own terms.
- With a **custom endpoint**, the destination is whatever you typed in.
- With the **relay**, images reach Otherworld, are processed, and are discarded.
- Receipts you attach to a transaction are stored in your own Nextcloud Files under `Budget/Receipts/`, exactly as they were before this feature existed.

## Limits

Receipt scanning reads a receipt; it does not audit it. Expect to check the result:

- Faded, crumpled or partly cut-off receipts read badly, as they would for a person.
- Handwritten totals are unreliable.
- A suggested category is a guess based on the merchant, matched against your own category tree. It is never applied without you seeing it.
- Where the arithmetic on the receipt does not add up, the app trusts the printed total rather than the sum of the lines, and tells you it did.

## Related

- [Transactions](transactions.md) — attaching receipts to a transaction by hand
- [REST API](api.md) — how a phone app discovers whether this server has scanning enabled
- [Settings](settings.md) — the rest of the settings page

<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Parser;

/**
 * Parser for ISO 20022 camt.053 (bank-to-customer statement) and camt.052
 * (account report) XML — the statement export most European banks offer (#350).
 *
 * Element lookups go by local name, so every camt.05x schema version
 * (.001.02 … .001.08 and later) parses, as do exports from banks that strip
 * the namespace. Output mirrors OfxParser so the multi-account import flow
 * (source-account routing, IBAN auto-match, remembered links) applies as is.
 */
class CamtParser {

    /**
     * @return array{accounts: array<array{
     *     accountId: string, bankId: string|null, bankName: string|null,
     *     name: string|null, type: string, currency: string,
     *     ledgerBalance: float|null, availableBalance: float|null,
     *     balanceDate: string|null, openingBalance: float|null,
     *     statementId: string|null, fromDate: string|null, toDate: string|null,
     *     transactions: array
     * }>}
     * @throws \InvalidArgumentException when the content is not a camt document
     */
    public function parse(string $content): array {
        $doc = $this->loadDocument($content);
        $statements = $this->findStatements($doc);
        if ($statements === []) {
            throw new \InvalidArgumentException('Not a camt.053/camt.052 document: no statement or account report found');
        }

        $accounts = [];
        foreach ($statements as $statement) {
            $accounts[] = $this->parseStatement($statement);
        }

        return ['accounts' => $accounts];
    }

    /**
     * Flatten into the transaction list ImportService expects, each row
     * carrying its account metadata (same shape as OfxParser).
     */
    public function parseToTransactionList(string $content, ?int $limit = null): array {
        $parsed = $this->parse($content);
        $transactions = [];

        foreach ($parsed['accounts'] as $account) {
            foreach ($account['transactions'] as $transaction) {
                $transactions[] = array_merge($transaction, [
                    '_account' => [
                        'accountId' => $account['accountId'],
                        'bankId' => $account['bankId'],
                        'type' => $account['type'],
                        'currency' => $account['currency'],
                    ],
                    '_balances' => [
                        'ledger' => $account['ledgerBalance'],
                        'available' => $account['availableBalance'],
                        'date' => $account['balanceDate'],
                    ],
                ]);

                if ($limit !== null && count($transactions) >= $limit) {
                    return $transactions;
                }
            }
        }

        return $transactions;
    }

    // ── document ────────────────────────────────────────────────────

    private function loadDocument(string $content): \DOMDocument {
        $content = ltrim($content, "\xEF\xBB\xBF");
        if (trim($content) === '') {
            throw new \InvalidArgumentException('File is empty');
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT (entities stay unexpanded) and LIBXML_NONET (nothing
            // is fetched): an uploaded statement can never read server files.
            $loaded = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            throw new \InvalidArgumentException('File is not well-formed XML');
        }
        if ($doc->doctype !== null) {
            // Bank statements carry no DTD; one is only ever an attack vector.
            throw new \InvalidArgumentException('XML documents with a DTD are not accepted');
        }

        return $doc;
    }

    /** @return \DOMElement[] Stmt (camt.053) or Rpt (camt.052) elements */
    private function findStatements(\DOMDocument $doc): array {
        $root = $doc->documentElement;
        if ($root === null) {
            return [];
        }
        $wrapper = $this->child($root, 'BkToCstmrStmt') ?? $this->child($root, 'BkToCstmrAcctRpt');
        if ($wrapper === null) {
            return [];
        }
        $items = $this->children($wrapper, 'Stmt');
        return $items !== [] ? $items : $this->children($wrapper, 'Rpt');
    }

    // ── statement / account ─────────────────────────────────────────

    private function parseStatement(\DOMElement $stmt): array {
        $acct = $this->child($stmt, 'Acct');
        $accountId = $acct !== null
            ? ($this->text($acct, 'Id/IBAN') ?? $this->text($acct, 'Id/Othr/Id'))
            : null;
        $currency = $acct !== null ? ($this->text($acct, 'Ccy') ?? '') : '';
        $name = $acct !== null
            ? ($this->text($acct, 'Nm') ?? $this->text($acct, 'Tp/Prtry') ?? $this->text($acct, 'Ownr/Nm'))
            : null;
        $bankId = $acct !== null
            ? ($this->text($acct, 'Svcr/FinInstnId/BICFI') ?? $this->text($acct, 'Svcr/FinInstnId/BIC'))
            : null;
        $bankName = $acct !== null ? $this->text($acct, 'Svcr/FinInstnId/Nm') : null;

        $balances = $this->parseBalances($stmt);
        $closing = $balances['CLBD'] ?? $balances['ITBD'] ?? null;

        $transactions = [];
        foreach ($this->children($stmt, 'Ntry') as $entry) {
            foreach ($this->parseEntry($entry) as $transaction) {
                $transactions[] = $transaction;
            }
        }
        $this->dropRepeatedIds($transactions);

        if ($currency === '' && $transactions !== []) {
            $currency = (string) ($transactions[0]['currency'] ?? '');
        }

        return [
            'accountId' => $accountId ?? ($this->text($stmt, 'Id') ?? ''),
            'bankId' => $bankId,
            'bankName' => $bankName,
            'name' => $name,
            'type' => 'checking',
            'currency' => $currency,
            'ledgerBalance' => $closing['amount'] ?? null,
            'availableBalance' => $balances['CLAV']['amount'] ?? null,
            'balanceDate' => $closing['date'] ?? null,
            'openingBalance' => $balances['OPBD']['amount'] ?? null,
            'statementId' => $this->text($stmt, 'Id'),
            'fromDate' => $this->dateOf($this->text($stmt, 'FrToDt/FrDtTm')),
            'toDate' => $this->dateOf($this->text($stmt, 'FrToDt/ToDtTm')),
            'transactions' => $transactions,
        ];
    }

    /** @return array<string, array{amount: float, date: string|null}> keyed by balance code */
    private function parseBalances(\DOMElement $stmt): array {
        $balances = [];
        foreach ($this->children($stmt, 'Bal') as $bal) {
            $code = $this->text($bal, 'Tp/CdOrPrtry/Cd') ?? $this->text($bal, 'Tp/CdOrPrtry/Prtry');
            $amount = $this->signedAmount($this->text($bal, 'Amt'), $this->text($bal, 'CdtDbtInd'));
            if ($code === null || $amount === null) {
                continue;
            }
            $balances[$code] = [
                'amount' => $amount,
                'date' => $this->dateOf($this->text($bal, 'Dt/Dt') ?? $this->text($bal, 'Dt/DtTm')),
            ];
        }
        return $balances;
    }

    // ── entries ─────────────────────────────────────────────────────

    /**
     * One entry normally yields one transaction. A batch entry (several
     * TxDtls, each with its own amount — collective payments, card
     * settlements) yields one per detail so each keeps its own counterparty.
     *
     * @return array[]
     */
    private function parseEntry(\DOMElement $entry): array {
        $entryAmount = $this->text($entry, 'Amt');
        $entryIndicator = $this->text($entry, 'CdtDbtInd');
        $date = $this->dateOf(
            $this->text($entry, 'BookgDt/Dt') ?? $this->text($entry, 'BookgDt/DtTm')
            ?? $this->text($entry, 'ValDt/Dt') ?? $this->text($entry, 'ValDt/DtTm')
        );
        if ($entryAmount === null || $date === null) {
            return [];
        }

        $entryRef = $this->text($entry, 'AcctSvcrRef');
        $status = $this->text($entry, 'Sts/Cd') ?? $this->text($entry, 'Sts');
        $currency = $this->attr($entry, 'Amt', 'Ccy');
        $txCode = $this->bankTransactionCode($entry);
        $additional = $this->text($entry, 'AddtlNtryInf');
        $valueDate = $this->dateOf($this->text($entry, 'ValDt/Dt') ?? $this->text($entry, 'ValDt/DtTm'));

        $details = [];
        foreach ($this->children($entry, 'NtryDtls') as $dtls) {
            foreach ($this->children($dtls, 'TxDtls') as $tx) {
                $details[] = $tx;
            }
        }

        if (count($details) > 1 && $this->allHaveAmounts($details)) {
            $out = [];
            foreach ($details as $n => $tx) {
                $signed = $this->signedAmount(
                    $this->text($tx, 'Amt') ?? $this->text($tx, 'AmtDtls/TxAmt/Amt'),
                    $this->text($tx, 'CdtDbtInd') ?? $entryIndicator
                );
                if ($signed === null) {
                    continue;
                }
                $reference = $this->text($tx, 'Refs/EndToEndId')
                    ?? $this->text($tx, 'Refs/TxId')
                    ?? $this->text($tx, 'Refs/AcctSvcrRef');
                $id = $entryRef !== null ? $entryRef . '/' . ($reference ?? (string) ($n + 1)) : null;
                $out[] = $this->buildTransaction($signed, $date, $valueDate, $id, $tx, $additional, $txCode, $status, $currency, $reference);
            }
            return $out;
        }

        $signed = $this->signedAmount($entryAmount, $entryIndicator);
        if ($signed === null) {
            return [];
        }
        $tx = $details[0] ?? null;
        $reference = $tx !== null
            ? ($this->text($tx, 'Refs/EndToEndId') ?? $this->text($tx, 'Refs/TxId'))
            : null;

        return [$this->buildTransaction($signed, $date, $valueDate, $entryRef, $tx, $additional, $txCode, $status, $currency, $reference)];
    }

    private function buildTransaction(
        float $signed,
        string $date,
        ?string $valueDate,
        ?string $id,
        ?\DOMElement $tx,
        ?string $additional,
        ?string $txCode,
        ?string $status,
        ?string $currency,
        ?string $reference
    ): array {
        $remittance = $tx !== null ? $this->remittanceText($tx) : null;
        $counterparty = $tx !== null ? $this->counterpartyName($tx, $signed) : null;
        // Best human-readable line first: what the payer wrote, then who the
        // other side was, then the bank's own entry text, then the type code.
        $description = $remittance ?? $counterparty ?? $additional ?? $txCode ?? '';
        $memo = ($additional !== null && $additional !== $description) ? $additional : null;

        return [
            'id' => $id,
            'date' => $date,
            'amount' => abs($signed),
            'type' => $signed >= 0 ? 'credit' : 'debit',
            'rawAmount' => $signed,
            'description' => $description,
            'name' => $counterparty,
            'memo' => $memo,
            'reference' => $reference ?? $id,
            'transactionType' => $txCode,
            'checkNumber' => null,
            'valueDate' => $valueDate,
            'status' => $status,
            'currency' => $currency,
        ];
    }

    /**
     * A bank reference that repeats within one file is not a transaction
     * identity (some banks put the statement reference on every entry).
     * Trusting it would collapse those entries into one on import, so the
     * repeats fall back to the content hash instead.
     */
    private function dropRepeatedIds(array &$transactions): void {
        $counts = [];
        foreach ($transactions as $t) {
            if ($t['id'] !== null) {
                $counts[$t['id']] = ($counts[$t['id']] ?? 0) + 1;
            }
        }
        foreach ($transactions as &$t) {
            if ($t['id'] !== null && $counts[$t['id']] > 1) {
                $t['id'] = null;
            }
        }
        unset($t);
    }

    private function remittanceText(\DOMElement $tx): ?string {
        $parts = [];
        foreach ($this->children($tx, 'RmtInf') as $rmt) {
            foreach ($this->children($rmt, 'Ustrd') as $ustrd) {
                $parts[] = trim($ustrd->textContent);
            }
            foreach ($this->children($rmt, 'Strd') as $strd) {
                foreach (['CdtrRefInf/Ref', 'AddtlRmtInf'] as $path) {
                    $value = $this->text($strd, $path);
                    if ($value !== null) {
                        $parts[] = $value;
                    }
                }
            }
        }
        $parts = array_values(array_filter($parts, static fn(string $p) => $p !== ''));
        return $parts === [] ? null : implode(' ', $parts);
    }

    /** Money out → the creditor is the counterparty; money in → the debtor. */
    private function counterpartyName(\DOMElement $tx, float $signed): ?string {
        $parties = $this->child($tx, 'RltdPties');
        if ($parties === null) {
            return null;
        }
        $order = $signed < 0 ? ['Cdtr', 'UltmtCdtr', 'Dbtr'] : ['Dbtr', 'UltmtDbtr', 'Cdtr'];
        foreach ($order as $party) {
            // camt.053.001.08 wraps the party in Pty; older versions don't
            $name = $this->text($parties, $party . '/Nm') ?? $this->text($parties, $party . '/Pty/Nm');
            if ($name !== null) {
                return $name;
            }
        }
        return null;
    }

    private function bankTransactionCode(\DOMElement $entry): ?string {
        $code = $this->child($entry, 'BkTxCd');
        if ($code === null) {
            return null;
        }
        $parts = array_filter([
            $this->text($code, 'Domn/Cd'),
            $this->text($code, 'Domn/Fmly/Cd'),
            $this->text($code, 'Domn/Fmly/SubFmlyCd'),
        ]);
        if ($parts !== []) {
            return implode('/', $parts);
        }
        return $this->text($code, 'Prtry/Cd');
    }

    /** @param \DOMElement[] $details */
    private function allHaveAmounts(array $details): bool {
        foreach ($details as $tx) {
            if ($this->text($tx, 'Amt') === null && $this->text($tx, 'AmtDtls/TxAmt/Amt') === null) {
                return false;
            }
        }
        return true;
    }

    // ── value helpers ───────────────────────────────────────────────

    private function signedAmount(?string $amount, ?string $indicator): ?float {
        if ($amount === null) {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], $amount);
        if (!is_numeric($normalized)) {
            return null;
        }
        $value = abs((float) $normalized);
        return strtoupper((string) $indicator) === 'DBIT' ? -$value : $value;
    }

    /** ISO date or date-time → Y-m-d (the date part is authoritative; no TZ shifting). */
    private function dateOf(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) === 1) {
            return $m[1];
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    // ── DOM helpers (namespace-agnostic) ────────────────────────────

    /** @return \DOMElement[] direct children with the given local name */
    private function children(\DOMNode $node, string $name): array {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /** First element at a slash-separated local-name path, or null. */
    private function child(\DOMNode $node, string $path): ?\DOMElement {
        $current = $node;
        foreach (explode('/', $path) as $segment) {
            $next = $this->children($current, $segment)[0] ?? null;
            if ($next === null) {
                return null;
            }
            $current = $next;
        }
        return $current instanceof \DOMElement ? $current : null;
    }

    private function text(\DOMNode $node, string $path): ?string {
        $element = $this->child($node, $path);
        if ($element === null) {
            return null;
        }
        $value = trim($element->textContent);
        return $value === '' ? null : $value;
    }

    private function attr(\DOMNode $node, string $path, string $attribute): ?string {
        $element = $this->child($node, $path);
        if ($element === null || !$element->hasAttribute($attribute)) {
            return null;
        }
        $value = trim($element->getAttribute($attribute));
        return $value === '' ? null : $value;
    }
}

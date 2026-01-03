<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Parser;

/**
 * Parser for OFX (Open Financial Exchange) files.
 * Supports OFX 1.x (SGML) and 2.x (XML) formats.
 */
class OfxParser {

    /**
     * Parse OFX content and return structured data with accounts and transactions.
     *
     * @param string $content Raw OFX file content
     * @return array{
     *     accounts: array<array{
     *         accountId: string,
     *         bankId: string|null,
     *         type: string,
     *         currency: string,
     *         ledgerBalance: float|null,
     *         availableBalance: float|null,
     *         balanceDate: string|null,
     *         transactions: array
     *     }>
     * }
     */
    public function parse(string $content): array {
        // Normalize content - convert SGML to pseudo-XML for easier parsing
        $normalized = $this->normalizeOfxContent($content);

        $accounts = [];

        // Parse bank accounts (BANKMSGSRSV1)
        $bankAccounts = $this->parseBankAccounts($normalized);
        $accounts = array_merge($accounts, $bankAccounts);

        // Parse credit card accounts (CREDITCARDMSGSRSV1)
        $creditCardAccounts = $this->parseCreditCardAccounts($normalized);
        $accounts = array_merge($accounts, $creditCardAccounts);

        return [
            'accounts' => $accounts
        ];
    }

    /**
     * Flatten parsed OFX data into a simple transaction list with account metadata.
     * This is the format expected by ImportService.
     *
     * @param string $content Raw OFX content
     * @param int|null $limit Maximum transactions to return
     * @return array List of transactions with account metadata
     */
    public function parseToTransactionList(string $content, ?int $limit = null): array {
        $parsed = $this->parse($content);
        $transactions = [];

        foreach ($parsed['accounts'] as $account) {
            foreach ($account['transactions'] as $transaction) {
                $transactions[] = array_merge($transaction, [
                    '_account' => [
                        'accountId' => $account['accountId'],
                        'bankId' => $account['bankId'] ?? null,
                        'type' => $account['type'],
                        'currency' => $account['currency'],
                    ],
                    '_balances' => [
                        'ledger' => $account['ledgerBalance'],
                        'available' => $account['availableBalance'],
                        'date' => $account['balanceDate'],
                    ]
                ]);

                if ($limit !== null && count($transactions) >= $limit) {
                    return $transactions;
                }
            }
        }

        return $transactions;
    }

    /**
     * Normalize OFX content by converting SGML format to parseable structure.
     * OFX 1.x uses SGML with self-closing tags, OFX 2.x uses proper XML.
     */
    private function normalizeOfxContent(string $content): string {
        // Remove header section (everything before <OFX>)
        $ofxStart = stripos($content, '<OFX>');
        if ($ofxStart !== false) {
            $content = substr($content, $ofxStart);
        }

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Remove extra whitespace but preserve structure
        $content = preg_replace('/>\s+</', '><', $content);

        return $content;
    }

    /**
     * Parse bank account statements from BANKMSGSRSV1 section.
     */
    private function parseBankAccounts(string $content): array {
        $accounts = [];

        // Find all STMTRS blocks (statement responses)
        if (!preg_match_all('/<STMTRS>(.*?)<\/STMTRS>/si', $content, $stmtMatches)) {
            // Try SGML style without closing tags
            preg_match_all('/<STMTRS>(.*?)(?=<\/STMTRS>|<STMTTRNRS>|<\/BANKMSGSRSV1>)/si', $content, $stmtMatches);
        }

        foreach ($stmtMatches[1] ?? [] as $stmtContent) {
            $account = $this->parseAccountFromStatement($stmtContent, 'bank');
            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * Parse credit card account statements from CREDITCARDMSGSRSV1 section.
     */
    private function parseCreditCardAccounts(string $content): array {
        $accounts = [];

        // Find all CCSTMTRS blocks
        if (!preg_match_all('/<CCSTMTRS>(.*?)<\/CCSTMTRS>/si', $content, $stmtMatches)) {
            preg_match_all('/<CCSTMTRS>(.*?)(?=<\/CCSTMTRS>|<CCSTMTTRNRS>|<\/CREDITCARDMSGSRSV1>)/si', $content, $stmtMatches);
        }

        foreach ($stmtMatches[1] ?? [] as $stmtContent) {
            $account = $this->parseAccountFromStatement($stmtContent, 'creditcard');
            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * Parse account info and transactions from a statement block.
     */
    private function parseAccountFromStatement(string $content, string $accountCategory): ?array {
        // Extract account identification
        if ($accountCategory === 'creditcard') {
            $accountId = $this->extractTag($content, 'ACCTID', 'CCACCTFROM');
            $bankId = null;
            $accountType = 'credit_card';
        } else {
            $accountId = $this->extractTag($content, 'ACCTID', 'BANKACCTFROM');
            $bankId = $this->extractTag($content, 'BANKID', 'BANKACCTFROM');
            $accountType = strtolower($this->extractTag($content, 'ACCTTYPE', 'BANKACCTFROM') ?? 'checking');
        }

        if ($accountId === null) {
            return null;
        }

        // Extract currency
        $currency = $this->extractTag($content, 'CURDEF') ?? 'USD';

        // Extract balances
        $ledgerBalance = $this->extractBalanceAmount($content, 'LEDGERBAL');
        $availableBalance = $this->extractBalanceAmount($content, 'AVAILBAL');
        $balanceDate = $this->extractBalanceDate($content, 'LEDGERBAL');

        // Extract transactions
        $transactions = $this->parseTransactions($content);

        return [
            'accountId' => $accountId,
            'bankId' => $bankId,
            'type' => $accountType,
            'currency' => $currency,
            'ledgerBalance' => $ledgerBalance,
            'availableBalance' => $availableBalance,
            'balanceDate' => $balanceDate,
            'transactions' => $transactions,
        ];
    }

    /**
     * Parse all transactions from a BANKTRANLIST section.
     */
    private function parseTransactions(string $content): array {
        $transactions = [];

        // Find BANKTRANLIST section
        if (!preg_match('/<BANKTRANLIST>(.*?)(?:<\/BANKTRANLIST>|$)/si', $content, $listMatch)) {
            return [];
        }

        $listContent = $listMatch[1];

        // Find all STMTTRN blocks - handle both SGML and XML styles
        // SGML style: <STMTTRN> followed by content until next <STMTTRN> or </BANKTRANLIST>
        $pattern = '/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>|<\/STMTTRN>|$)/si';

        if (preg_match_all($pattern, $listContent, $trnMatches)) {
            foreach ($trnMatches[1] as $trnContent) {
                $transaction = $this->parseSingleTransaction($trnContent);
                if ($transaction !== null) {
                    $transactions[] = $transaction;
                }
            }
        }

        return $transactions;
    }

    /**
     * Parse a single transaction from STMTTRN content.
     */
    private function parseSingleTransaction(string $content): ?array {
        $fitId = $this->extractTagValue($content, 'FITID');
        $amount = $this->extractTagValue($content, 'TRNAMT');
        $datePosted = $this->extractTagValue($content, 'DTPOSTED');

        // Amount and date are required
        if ($amount === null || $datePosted === null) {
            return null;
        }

        $amountFloat = (float) $amount;

        return [
            'id' => $fitId,
            'date' => $this->parseOfxDate($datePosted),
            'amount' => abs($amountFloat),
            'type' => $amountFloat >= 0 ? 'credit' : 'debit',
            'rawAmount' => $amountFloat,
            'description' => $this->extractTagValue($content, 'NAME') ?? '',
            'memo' => $this->extractTagValue($content, 'MEMO'),
            'transactionType' => $this->extractTagValue($content, 'TRNTYPE'),
            'checkNumber' => $this->extractTagValue($content, 'CHECKNUM'),
            'reference' => $this->extractTagValue($content, 'REFNUM'),
        ];
    }

    /**
     * Extract a tag value from content, optionally within a parent tag.
     */
    private function extractTag(string $content, string $tag, ?string $parentTag = null): ?string {
        $searchContent = $content;

        if ($parentTag !== null) {
            // Find the parent section with closing tag (handles both XML and normalized SGML)
            if (preg_match("/<{$parentTag}>(.*?)<\/{$parentTag}>/si", $content, $parentMatch)) {
                $searchContent = $parentMatch[1];
            } else {
                return null;
            }
        }

        return $this->extractTagValue($searchContent, $tag);
    }

    /**
     * Extract tag value handling both SGML and XML formats.
     * SGML: <TAG>value (no closing tag, value ends at newline or next tag)
     * XML: <TAG>value</TAG>
     */
    private function extractTagValue(string $content, string $tag): ?string {
        // Try XML style first
        if (preg_match("/<{$tag}>(.*?)<\/{$tag}>/si", $content, $match)) {
            return trim($match[1]);
        }

        // Try SGML style: <TAG>value followed by newline, < or end
        if (preg_match("/<{$tag}>([^<\r\n]+)/i", $content, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * Extract balance amount from a balance block (LEDGERBAL or AVAILBAL).
     */
    private function extractBalanceAmount(string $content, string $balanceTag): ?float {
        if (preg_match("/<{$balanceTag}>(.*?)(?:<\/{$balanceTag}>|<[A-Z]+BAL>|<\/STMTRS>|<\/CCSTMTRS>)/si", $content, $match)) {
            $balanceContent = $match[1];
            $amount = $this->extractTagValue($balanceContent, 'BALAMT');
            return $amount !== null ? (float) $amount : null;
        }
        return null;
    }

    /**
     * Extract balance date from a balance block.
     */
    private function extractBalanceDate(string $content, string $balanceTag): ?string {
        if (preg_match("/<{$balanceTag}>(.*?)(?:<\/{$balanceTag}>|<[A-Z]+BAL>|<\/STMTRS>|<\/CCSTMTRS>)/si", $content, $match)) {
            $balanceContent = $match[1];
            $dateStr = $this->extractTagValue($balanceContent, 'DTASOF');
            return $dateStr !== null ? $this->parseOfxDate($dateStr) : null;
        }
        return null;
    }

    /**
     * Parse OFX date format (YYYYMMDD or YYYYMMDDHHMMSS) to Y-m-d.
     */
    private function parseOfxDate(string $dateStr): string {
        // Remove timezone info if present (e.g., [0:GMT])
        $dateStr = preg_replace('/\[.*?\]/', '', $dateStr);
        $dateStr = trim($dateStr);

        // Handle YYYYMMDDHHMMSS.XXX format
        if (strlen($dateStr) >= 14) {
            $dateStr = substr($dateStr, 0, 8);
        } elseif (strlen($dateStr) >= 8) {
            $dateStr = substr($dateStr, 0, 8);
        }

        // Parse YYYYMMDD
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateStr, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        // Fallback - return as-is if we can't parse
        return $dateStr;
    }
}

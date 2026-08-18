<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\OcrSettingsService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Receipt image in, draft transaction out (#533).
 *
 * The privacy contract set by the settings (#534) is enforced here, not just
 * described: the ONLY thing that ever leaves this server is the image. The
 * category suggestion therefore runs locally after extraction — the user's
 * import/categorisation rules are matched against the extracted merchant and
 * total, and the provider never sees a category name, an account, or any
 * other part of the ledger.
 *
 * Nothing in the returned draft is trusted: every field is normalised and
 * bounded here before it reaches a client, because "the model said so" is
 * not a provenance. A field the provider could not read comes back null and
 * the user types it, exactly as they would have without this feature.
 */
class ReceiptExtractionService {
    /**
     * Image types accepted for extraction. Narrower than the attachment
     * allow-list on purpose: vision endpoints and OCR task types read
     * bitmaps, and a PDF or HEIC that the backend cannot decode should be
     * rejected here with a clear message, not there with an opaque one.
     */
    public const EXTRACT_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Seconds until an unresponsive provider is declared failed. */
    private const HTTP_TIMEOUT = 60;
    private const HTTP_CONNECT_TIMEOUT = 10;

    /** Model name sent to the relay, which picks its real backend itself. */
    private const RELAY_MODEL = 'receipt-v1';

    private const PROMPT = 'Read this till receipt. Reply with ONLY a JSON object — no code fences, no commentary: '
        . '{"merchant": string|null, "date": "YYYY-MM-DD"|null, "currency": "ISO 4217 code"|null, '
        . '"total": number|null, "subtotal": number|null, "tax": number|null, "discount": number|null, '
        . '"lineItems": [{"description": string, "amount": number}]}. '
        . 'Use null for anything unreadable rather than guessing. "total" is the amount actually paid. '
        . '"subtotal" is the pre-tax figure and "tax" the VAT/sales tax line — report each ONLY when '
        . 'the receipt prints it as its own labelled line. Never calculate subtotal or tax yourself, '
        . 'and never report a negative one; use null when the receipt does not print it. '
        . '"discount" is the TOTAL money taken off: add together EVERY loyalty saving, coupon, staff '
        . 'discount and multibuy offer the receipt prints into this one positive number (or null if '
        . 'there are none). It is subtracted from the items to reach the total. '
        . 'Amounts are plain positive numbers without currency symbols. List purchased items only — '
        . 'not subtotals, tax lines, discount lines, change, or payment rows. '
        . 'Before replying, check that the item amounts minus "discount" plus "tax" equal "total". If '
        . 'they do not, re-read the receipt — you have most likely missed a discount line or misread '
        . 'an item amount — and correct your answer before you reply.';

    public function __construct(
        private OcrSettingsService $settings,
        private IClientService $clientService,
        private NextcloudOcrBackend $nextcloudBackend,
        private ReceiptParser $parser,
        private ImportRuleService $ruleService,
        private CategoryService $categoryService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array $uploadedFile PHP uploaded-file array (name, type, tmp_name, error, size)
     * @return array{
     *   merchant: ?string, date: ?string, currency: ?string, total: ?string,
     *   lineItems: list<array{description: string, amount: string}>,
     *   suggestedCategoryId: ?int, suggestedCategoryName: ?string,
     *   warnings: list<string>,
     * }
     * @throws OcrNotConfiguredException When no provider is set up.
     * @throws OcrProviderException When the provider fails.
     * @throws \InvalidArgumentException When the upload itself is unusable.
     */
    public function extract(string $userId, array $uploadedFile): array {
        if (!$this->settings->isConfigured()) {
            throw new OcrNotConfiguredException('No OCR provider is configured');
        }

        [$imageBytes, $mime] = $this->readUpload($uploadedFile);

        $raw = match ($this->settings->getProvider()) {
            // The parser also returns subtotal/tax, which buildDraft() uses
            // to reconcile the line items but never puts on the wire.
            OcrSettingsService::PROVIDER_NEXTCLOUD => $this->parser->parse(
                $this->nextcloudBackend->extractText($imageBytes, $mime, $userId)
            ) + ['currency' => null],
            default => $this->openAiExtract($imageBytes, $mime),
        };

        return $this->buildDraft($userId, $raw);
    }

    // ── upload handling ─────────────────────────────────────────────

    /** @return array{0: string, 1: string} bytes and detected mime */
    private function readUpload(array $uploadedFile): array {
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed');
        }
        if (($uploadedFile['size'] ?? 0) > AttachmentService::MAX_SIZE) {
            throw new \InvalidArgumentException('File exceeds the 25 MB limit');
        }

        $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');
        $bytes = $tmpPath !== '' ? (string)@file_get_contents($tmpPath) : '';
        if ($bytes === '') {
            throw new \InvalidArgumentException('Upload failed');
        }

        // Detect the type from the bytes; the client-declared type is not
        // evidence. finfo is part of PHP's standard build on Nextcloud.
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::EXTRACT_MIMES, true)) {
            throw new \InvalidArgumentException(
                'Receipt extraction accepts JPEG, PNG or WebP images'
            );
        }

        return [$bytes, $mime];
    }

    // ── the OpenAI-compatible path (custom endpoint and relay) ──────

    private function openAiExtract(string $imageBytes, string $mime): array {
        $isRelay = $this->settings->getProvider() === OcrSettingsService::PROVIDER_RELAY;
        $endpoint = rtrim($this->settings->getEndpoint(), '/') . '/chat/completions';
        $model = $this->settings->getModel();
        if ($model === '' && $isRelay) {
            $model = self::RELAY_MODEL;
        }

        $headers = ['Content-Type' => 'application/json'];
        $apiKey = $this->settings->getApiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $body = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 1500,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::PROMPT],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $mime . ';base64,' . base64_encode($imageBytes),
                    ]],
                ],
            ]],
        ];

        try {
            $response = $this->clientService->newClient()->post($endpoint, [
                'headers' => $headers,
                'body' => json_encode($body),
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            ]);
            $payload = json_decode((string)$response->getBody(), true);
        } catch (\Throwable $e) {
            // A metered backend saying "quota spent" is its own condition,
            // not a failure — the capture app tells the user to top up, not
            // to retry. Guzzle-style client exceptions carry the response.
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null
                && method_exists($e->getResponse(), 'getStatusCode')
                && $e->getResponse()->getStatusCode() === 429) {
                throw new OcrQuotaExhaustedException('The OCR provider reported its quota exhausted', 0, $e);
            }

            // The key must never end up in a log line; the exception message
            // from an HTTP client can embed request headers.
            $this->logger->warning('Receipt OCR provider request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);

            throw new OcrProviderException('The OCR provider could not be reached', 0, $e);
        }

        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new OcrProviderException('The OCR provider returned no content');
        }

        $fields = $this->decodeModelJson($content);
        if ($fields === null) {
            throw new OcrProviderException('The OCR provider returned an unusable response');
        }

        return $fields;
    }

    /**
     * Models answer "JSON only" prompts with JSON — usually. Code fences and
     * a sentence of preamble are the two failure modes worth tolerating;
     * anything less JSON-shaped than that is the provider's problem.
     */
    private function decodeModelJson(string $content): ?array {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content) ?? $content;

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            // Salvage the outermost object from surrounding prose.
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    // ── normalisation and the local category suggestion ─────────────

    private function buildDraft(string $userId, array $raw): array {
        $merchant = $this->cleanString($raw['merchant'] ?? null, 80);
        $date = $this->cleanDate($raw['date'] ?? null);
        $currency = $this->cleanCurrency($raw['currency'] ?? null);
        $total = $this->cleanAmount($raw['total'] ?? null);
        if ($total !== null && (float)$total <= 0) {
            // A refund receipt or a misread sign. The v1 contract says total
            // is "the amount actually paid" and pins it non-negative — and a
            // missing total the user types beats a wrong one they trust.
            $total = null;
        }

        $lineItems = [];
        foreach (is_array($raw['lineItems'] ?? null) ? $raw['lineItems'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $description = $this->cleanString($item['description'] ?? null, 100);
            $amount = $this->cleanAmount($item['amount'] ?? null);
            if ($description === null || $amount === null || (float)$amount <= 0) {
                continue;
            }
            $lineItems[] = ['description' => $description, 'amount' => $amount];
            if (count($lineItems) === 50) {
                break;
            }
        }

        $subtotal = $this->positiveAmount($raw['subtotal'] ?? null);
        $tax = $this->positiveAmount($raw['tax'] ?? null);
        // Money taken off — loyalty savings, coupons, multibuy — as a positive
        // figure a consumer subtracts, corrected up when the model under-read a
        // supermarket's savings (see reconcilingDiscount). Without it those
        // receipts never reconcile: their items legitimately sum higher than
        // what was paid.
        $discount = $total !== null && $lineItems !== []
            ? $this->reconcilingDiscount($lineItems, $total, $subtotal, $tax, $raw)
            : $this->discountAmount($raw);

        $warnings = [];
        if ($total === null) {
            $warnings[] = 'no-total';
        }
        if ($date === null) {
            $warnings[] = 'no-date';
        }
        if ($total !== null && $lineItems !== [] && !$this->itemsReconcile($lineItems, $total, $discount, $subtotal, $tax)) {
            // The printed total wins — a till adds better than an OCR
            // reads — but the client is told the arithmetic is off.
            $warnings[] = 'line-items-sum-mismatch';
        }

        [$categoryId, $categoryName] = $this->suggestCategory($userId, $merchant, $total);

        return [
            'merchant' => $merchant,
            'date' => $date,
            'currency' => $currency,
            'total' => $total,
            // Reported, not just used internally for the reconciliation check:
            // a consumer turning the items into per-item splits needs the tax
            // to make them sum to the total, since splits must reconcile
            // exactly and most receipts print tax as its own line.
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'lineItems' => $lineItems,
            'suggestedCategoryId' => $categoryId,
            'suggestedCategoryName' => $categoryName,
            'warnings' => $warnings,
        ];
    }

    /**
     * Do the line items account for the total?
     *
     * Naively that is sum(items) === total, and on a UK high-street receipt
     * it is: shelf prices include VAT, and the VAT line is only ever stated
     * "of which". But plenty of receipts add tax ON TOP — US sales tax, an
     * ex-VAT trade invoice, a service charge — and there the items correctly
     * sum to the SUBTOTAL while the total is larger. Warning on those cries
     * wolf on a perfectly-read receipt, and a warning that fires constantly
     * is one the user learns to ignore, which costs us the misread items it
     * exists to catch.
     *
     * So the printed subtotal and tax are used when the receipt states them,
     * and only an otherwise-unexplained gap is reported.
     *
     * @param list<array{description: string, amount: string}> $lineItems
     * @param array $raw The provider's own fields, for subtotal/tax.
     */
    /**
     * Do the line items account for the total?
     *
     * Four shapes reconcile, and a real receipt is usually one of them:
     *
     *   items                    = total   tax-inclusive prices, no offers
     *   items + tax              = total   tax printed separately
     *   items − discount         = total   loyalty savings, coupons, multibuy
     *   items − discount + tax   = total   both
     *
     * The discount case is why a supermarket receipt used to be declined: a
     * Clubcard or coupon line means the items sum HIGHER than what was paid,
     * which is not a misread and should not be reported as one.
     *
     * (See discountAmount() below for the sign normalisation.)
     */
    /**
     * The discount, always as positive money taken off.
     *
     * Models and receipts both write it either way — "3.50" and "-3.50" mean
     * the same money off — so the sign is normalised once, here. Both the
     * reconciliation check and the reported draft read through this, or they
     * could disagree about whether a receipt adds up.
     */
    private function discountAmount(array $raw): ?string {
        $discount = $this->cleanAmount($raw['discount'] ?? null);
        if ($discount === null) {
            return null;
        }

        $positive = ltrim($discount, '-');
        // A stated zero is not a discount; treat it as absent so a consumer
        // does not render a pointless "0.00 savings" line.
        return $positive === '' || MoneyCalculator::equals($positive, '0') ? null : $positive;
    }

    /**
     * The discount to report and reconcile against — the model's stated saving,
     * corrected up when it plainly under-read it.
     *
     * A vision model routinely reads all but one of a supermarket's savings
     * lines: it returns *a* discount, yet the items still sum higher than the
     * total by more than it. When that happens — a stated discount, no shape
     * reconciling, and the items (plus any tax) genuinely exceeding the total —
     * the true total saving on a tax-inclusive receipt is exactly
     * items (+ tax) − total, so it is adopted and the receipt splits instead of
     * being refused.
     *
     * Gated on a stated discount on purpose: a receipt with no saving line whose
     * items merely overshoot the total is a misread, not a silent saving, and
     * must keep warning rather than grow a phantom discount. And because the
     * user confirms every draft before saving, an over-read item that inflates
     * the closed gap is caught at review, not recorded blind.
     */
    private function reconcilingDiscount(array $lineItems, string $total, ?string $subtotal, ?string $tax, array $raw): ?string {
        $stated = $this->discountAmount($raw);
        if ($stated === null || $lineItems === []) {
            return $stated;
        }
        if ($this->itemsReconcile($lineItems, $total, $stated, $subtotal, $tax)) {
            return $stated;
        }

        $sum = MoneyCalculator::sum(array_column($lineItems, 'amount'));
        $gross = $tax !== null ? MoneyCalculator::add($sum, $tax) : $sum;
        // Items don't exceed the total: a genuine shortfall, not a saving. Keep
        // the stated discount so the mismatch still warns.
        if (MoneyCalculator::compare($gross, $total) <= 0) {
            return $stated;
        }

        return MoneyCalculator::subtract($gross, $total);
    }

    /**
     * Do the line items account for the total? Four shapes reconcile:
     *
     *   items                    = total   tax-inclusive prices, no offers
     *   items + tax              = total   tax printed separately
     *   items − discount         = total   loyalty savings, coupons, multibuy
     *   items − discount + tax   = total   both
     *
     * The discount/subtotal/tax passed in are already normalised — positive, or
     * null when absent (see positiveAmount() and reconcilingDiscount()).
     */
    private function itemsReconcile(array $lineItems, string $total, ?string $discount, ?string $subtotal, ?string $tax): bool {
        $sum = MoneyCalculator::sum(array_column($lineItems, 'amount'));

        // Tax-inclusive pricing: the items already are the total.
        if (MoneyCalculator::equals($sum, $total)) {
            return true;
        }

        // Money taken off comes out of the items before anything else.
        $net = $discount !== null ? MoneyCalculator::subtract($sum, $discount) : $sum;

        if ($discount !== null && MoneyCalculator::equals($net, $total)) {
            return true;
        }

        // Items make up the stated subtotal, and subtotal + tax is the total.
        if ($subtotal !== null && MoneyCalculator::equals($sum, $subtotal)) {
            if ($tax === null) {
                // No tax line to check against, but the items demonstrably
                // account for the subtotal — the gap is the receipt's own.
                return true;
            }
            if (MoneyCalculator::equals(MoneyCalculator::add($subtotal, $tax), $total)) {
                return true;
            }
        }

        // The tax line closes the gap, with or without a discount first.
        if ($tax !== null && MoneyCalculator::equals(MoneyCalculator::add($net, $tax), $total)) {
            return true;
        }

        return false;
    }

    /**
     * The user's own categorisation rules, run against the extracted fields.
     * Local by design — see the class doc. The first (highest-priority)
     * matching rule that still points at a real category wins.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function suggestCategory(string $userId, ?string $merchant, ?string $total): array {
        if ($merchant === null) {
            return [null, null];
        }

        try {
            $matches = $this->ruleService->testRules($userId, [
                'description' => $merchant,
                'vendor' => $merchant,
                'amount' => $total !== null ? (float)$total : 0.0,
                'type' => 'debit',
            ]);

            foreach ($matches as $match) {
                $categoryId = $match['categoryId'] ?? null;
                if ($categoryId === null) {
                    continue;
                }
                try {
                    $category = $this->categoryService->find((int)$categoryId, $userId);

                    return [(int)$categoryId, $category->getName()];
                } catch (\Throwable $e) {
                    // Rule points at a deleted category — try the next match.
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Receipt category suggestion skipped: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }

        return [null, null];
    }

    private function cleanString(mixed $value, int $maxLength): ?string {
        if (!is_string($value)) {
            return null;
        }
        // Unicode format characters (\p{Cf}) are removed outright: a
        // right-to-left override renders spoofed text on the user's own
        // confirmation screen, and zero-width characters defeat comparisons
        // while displaying as nothing — so nothing is what they become.
        // Control characters become spaces (they usually separate words in
        // OCR text), then runs of whitespace collapse.
        $value = preg_replace('/\p{Cf}/u', '', $value) ?? $value;
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
        $value = preg_replace('/\s{2,}/', ' ', $value) ?? $value;

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function cleanDate(mixed $value): ?string {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return null;
        }
        $year = (int)$m[1];
        if ($year < 2000 || $year > (int)date('Y') + 1 || !checkdate((int)$m[2], (int)$m[3], $year)) {
            return null;
        }

        return trim($value);
    }

    private function cleanCurrency(mixed $value): ?string {
        if (!is_string($value) || !preg_match('/^[A-Za-z]{3}$/', trim($value))) {
            return null;
        }

        return strtoupper(trim($value));
    }

    /** Accepts what a model or the parser produces; emits a v1 money string. */
    private function cleanAmount(mixed $value): ?string {
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value) || !is_numeric(trim($value))) {
            return null;
        }
        $number = (float)trim($value);
        if (!is_finite($number) || abs($number) >= 1e13) {
            return null;
        }

        return number_format($number, 2, '.', '');
    }

    /**
     * A subtotal or tax figure, or null. Unlike a discount — normalised to its
     * absolute value, because "-3.50" and "3.50" are the same money off — a
     * negative or zero subtotal or tax is meaningless: a model that emits one
     * (a hallucinated "tax": -16.80 was seen in the field) has miscomputed, and
     * passing it on both misreports it to the client and breaks the split
     * reconciliation. Treat it as absent, exactly as a missing line would be.
     */
    private function positiveAmount(mixed $value): ?string {
        $amount = $this->cleanAmount($value);
        if ($amount === null || (float)$amount <= 0) {
            return null;
        }

        return $amount;
    }
}

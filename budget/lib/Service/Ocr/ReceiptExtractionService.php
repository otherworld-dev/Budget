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
        . '"total": number|null, "lineItems": [{"description": string, "amount": number}]}. '
        . 'Use null for anything unreadable rather than guessing. "total" is the amount actually paid. '
        . 'Amounts are plain positive numbers without currency symbols. List purchased items only — '
        . 'not subtotals, tax lines, change, or payment rows.';

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
            OcrSettingsService::PROVIDER_NEXTCLOUD => $this->parser->parse(
                $this->nextcloudBackend->extractText(base64_encode($imageBytes), $userId)
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

        $warnings = [];
        if ($total === null) {
            $warnings[] = 'no-total';
        }
        if ($date === null) {
            $warnings[] = 'no-date';
        }
        if ($total !== null && $lineItems !== []) {
            $sum = MoneyCalculator::sum(array_column($lineItems, 'amount'));
            if (!MoneyCalculator::equals($sum, $total)) {
                // The printed total wins — a till adds better than an OCR
                // reads — but the client is told the arithmetic is off.
                $warnings[] = 'line-items-sum-mismatch';
            }
        }

        [$categoryId, $categoryName] = $this->suggestCategory($userId, $merchant, $total);

        return [
            'merchant' => $merchant,
            'date' => $date,
            'currency' => $currency,
            'total' => $total,
            'lineItems' => $lineItems,
            'suggestedCategoryId' => $categoryId,
            'suggestedCategoryName' => $categoryName,
            'warnings' => $warnings,
        ];
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
        // Strip control characters; OCR text and model output both carry them.
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');

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
}

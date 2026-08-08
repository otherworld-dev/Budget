<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Ocr;

use OCA\Budget\Db\Category;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Service\Ocr\NextcloudOcrBackend;
use OCA\Budget\Service\Ocr\OcrNotConfiguredException;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCA\Budget\Service\Ocr\ReceiptParser;
use OCA\Budget\Service\OcrSettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReceiptExtractionServiceTest extends TestCase {
	/** 1×1 transparent PNG — real bytes, because the service sniffs them. */
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	private ReceiptExtractionService $service;
	private OcrSettingsService $settings;
	private IClientService $clientService;
	private IClient $client;
	private NextcloudOcrBackend $backend;
	private ImportRuleService $ruleService;
	private CategoryService $categoryService;

	/** Captured options of the last HTTP POST the service made. */
	private ?array $lastRequest = null;

	/** @var string[] temp files to unlink */
	private array $tmpFiles = [];

	protected function setUp(): void {
		$this->settings = $this->createMock(OcrSettingsService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->backend = $this->createMock(NextcloudOcrBackend::class);
		$this->ruleService = $this->createMock(ImportRuleService::class);
		$this->categoryService = $this->createMock(CategoryService::class);

		$this->service = new ReceiptExtractionService(
			$this->settings,
			$this->clientService,
			$this->backend,
			new ReceiptParser(),
			$this->ruleService,
			$this->categoryService,
			$this->createMock(LoggerInterface::class)
		);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $file) {
			@unlink($file);
		}
	}

	// ── gates ───────────────────────────────────────────────────────

	public function testThrowsWhenNoProviderIsConfigured(): void {
		$this->settings->method('isConfigured')->willReturn(false);

		$this->expectException(OcrNotConfiguredException::class);

		$this->service->extract('user1', $this->pngUpload());
	}

	public function testRejectsAFailedUpload(): void {
		$this->configureCustom();

		$this->expectException(\InvalidArgumentException::class);

		$this->service->extract('user1', ['error' => UPLOAD_ERR_PARTIAL]);
	}

	public function testRejectsNonImageBytesWhateverTheClientClaims(): void {
		$this->configureCustom();
		$upload = $this->upload('%PDF-1.4 not an image', 'image/png');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('JPEG, PNG or WebP');

		$this->service->extract('user1', $upload);
	}

	// ── the OpenAI-compatible path ──────────────────────────────────

	public function testExtractsADraftFromAModelResponse(): void {
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Tesco Express',
			'date' => '2026-08-01',
			'currency' => 'gbp',
			'total' => 9.75,
			'lineItems' => [
				['description' => 'Milk 2L', 'amount' => 1.65],
				['description' => 'Bread', 'amount' => '8.10'],
			],
		]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertSame('Tesco Express', $draft['merchant']);
		$this->assertSame('2026-08-01', $draft['date']);
		$this->assertSame('GBP', $draft['currency']);
		$this->assertSame('9.75', $draft['total']);
		$this->assertSame([
			['description' => 'Milk 2L', 'amount' => '1.65'],
			['description' => 'Bread', 'amount' => '8.10'],
		], $draft['lineItems']);
		$this->assertSame([], $draft['warnings']);
	}

	public function testStripsCodeFencesFromTheModelAnswer(): void {
		$this->configureCustom();
		$this->modelAnswers("```json\n{\"merchant\": \"Shop\", \"total\": 5}\n```");

		$this->assertSame('5.00', $this->service->extract('user1', $this->pngUpload())['total']);
	}

	public function testUnusableFieldsBecomeNullNotGuesses(): void {
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => '',
			'date' => '31/12/2026',      // not the requested format
			'currency' => 'pounds',       // not ISO 4217
			'total' => 'about nine',
			'lineItems' => 'n/a',
		]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertNull($draft['merchant']);
		$this->assertNull($draft['date']);
		$this->assertNull($draft['currency']);
		$this->assertNull($draft['total']);
		$this->assertSame([], $draft['lineItems']);
		$this->assertContains('no-total', $draft['warnings']);
		$this->assertContains('no-date', $draft['warnings']);
	}

	public function testANegativeTotalBecomesNullNotARefundGuess(): void {
		// The v1 contract pins total non-negative ("the amount actually
		// paid"); a refund receipt or misread sign must not violate it.
		$this->configureCustom();
		$this->modelAnswers(json_encode(['merchant' => 'Shop', 'date' => '2026-08-01', 'total' => -5.0]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertNull($draft['total']);
		$this->assertContains('no-total', $draft['warnings']);
	}

	public function testFormatCharactersAreStrippedFromStrings(): void {
		// U+202E flips rendering direction — spoofed text on the user's own
		// confirmation screen. Zero-width characters defeat comparisons.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => "PAY\u{202E}dnufer\u{202C}",
			'total' => 5,
			'lineItems' => [['description' => "Mi\u{200B}lk", 'amount' => 1.65]],
		]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertSame('PAYdnufer', $draft['merchant']);
		$this->assertSame('Milk', $draft['lineItems'][0]['description']);
	}

	public function testLineItemsAreCappedAtFifty(): void {
		$items = [];
		for ($i = 1; $i <= 60; $i++) {
			$items[] = ['description' => "Item $i", 'amount' => 1.00];
		}
		$this->configureCustom();
		$this->modelAnswers(json_encode(['merchant' => 'Shop', 'lineItems' => $items]));

		$this->assertCount(50, $this->service->extract('user1', $this->pngUpload())['lineItems']);
	}

	public function testAmountsBeyondTheDecimalColumnAreDropped(): void {
		// DECIMAL(15,2) holds 13 integer digits; an OCR-misread card number
		// must not survive as a total.
		$this->configureCustom();
		$this->modelAnswers(json_encode(['merchant' => 'Shop', 'total' => 12345678901234.00]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertNull($draft['total']);
	}

	public function testRejectsAnOversizedUpload(): void {
		$this->configureCustom();
		$upload = $this->pngUpload();
		$upload['size'] = 26214400 + 1;

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('25 MB');

		$this->service->extract('user1', $upload);
	}

	// ── reconciling items against the total ─────────────────────────

	public function testTaxAddedOnTopIsNotAMismatch(): void {
		// THE FALSE POSITIVE. Items sum to the SUBTOTAL and tax is added on
		// top — a US receipt, an ex-VAT trade invoice. The items are read
		// perfectly; warning here trains the user to ignore the warning.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'The Corner Deli',
			'date' => '2026-08-05',
			'total' => 23.77,
			'subtotal' => 22.35,
			'tax' => 1.42,
			'lineItems' => [
				['description' => 'Flat White', 'amount' => 3.40],
				['description' => 'Sourdough Loaf', 'amount' => 18.95],
			],
		]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertSame('23.77', $draft['total']);
		$this->assertSame([], $draft['warnings']);
	}

	public function testTaxClosingTheGapWithoutAStatedSubtotalIsNotAMismatch(): void {
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop', 'date' => '2026-08-05',
			'total' => 11.00, 'tax' => 1.00,
			'lineItems' => [['description' => 'Thing', 'amount' => 10.00]],
		]));

		$this->assertSame([], $this->service->extract('user1', $this->pngUpload())['warnings']);
	}

	public function testTaxInclusivePricingIsStillTheSimpleCase(): void {
		// UK high street: shelf prices include VAT, items == total.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop', 'date' => '2026-08-05',
			'total' => 10.00, 'tax' => 1.67,
			'lineItems' => [['description' => 'Thing', 'amount' => 10.00]],
		]));

		$this->assertSame([], $this->service->extract('user1', $this->pngUpload())['warnings']);
	}

	public function testAMissedItemStillWarnsEvenWithATaxLine(): void {
		// The warning must survive the fix: subtotal is stated, the items do
		// NOT add up to it, so something was misread or dropped.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop', 'date' => '2026-08-05',
			'total' => 23.77, 'subtotal' => 22.35, 'tax' => 1.42,
			'lineItems' => [['description' => 'Only one item', 'amount' => 3.40]],
		]));

		$this->assertSame(['line-items-sum-mismatch'], $this->service->extract('user1', $this->pngUpload())['warnings']);
	}

	public function testAnUnexplainedGapStillWarns(): void {
		// No subtotal, no tax, and the items do not reach the total.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop', 'date' => '2026-08-05', 'total' => 50.00,
			'lineItems' => [['description' => 'Thing', 'amount' => 3.00]],
		]));

		$this->assertSame(['line-items-sum-mismatch'], $this->service->extract('user1', $this->pngUpload())['warnings']);
	}

	public function testAStatedSubtotalThatDoesNotAddUpToTheTotalStillWarns(): void {
		// Items match the subtotal, but subtotal + tax != total: the receipt
		// itself was misread somewhere, so say so.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop', 'date' => '2026-08-05',
			'total' => 99.00, 'subtotal' => 10.00, 'tax' => 1.00,
			'lineItems' => [['description' => 'Thing', 'amount' => 10.00]],
		]));

		$this->assertSame(['line-items-sum-mismatch'], $this->service->extract('user1', $this->pngUpload())['warnings']);
	}

	public function testSubtotalAndTaxAreNotPutOnTheWire(): void {
		// They are reconciliation inputs, not part of the v1 draft contract.
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop', 'date' => '2026-08-05',
			'total' => 11.00, 'subtotal' => 10.00, 'tax' => 1.00,
			'lineItems' => [['description' => 'Thing', 'amount' => 10.00]],
		]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertArrayNotHasKey('subtotal', $draft);
		$this->assertArrayNotHasKey('tax', $draft);
	}

	public function testWarnsWhenLineItemsDoNotSumToTheTotal(): void {
		$this->configureCustom();
		$this->modelAnswers(json_encode([
			'merchant' => 'Shop',
			'date' => '2026-08-01',
			'total' => 10.00,
			'lineItems' => [['description' => 'Thing', 'amount' => 3.00]],
		]));

		$draft = $this->service->extract('user1', $this->pngUpload());

		// The printed total is kept — the warning tells the client to look.
		$this->assertSame('10.00', $draft['total']);
		$this->assertSame(['line-items-sum-mismatch'], $draft['warnings']);
	}

	public function testProviderHttpFailureBecomesAProviderException(): void {
		$this->configureCustom();
		$this->client->method('post')->willThrowException(new \RuntimeException('cURL error 7'));

		$this->expectException(OcrProviderException::class);

		$this->service->extract('user1', $this->pngUpload());
	}

	public function testAProvider429BecomesAQuotaException(): void {
		// A metered backend saying "quota spent" is its own condition — the
		// app tells the user to top up, not to retry.
		$this->configureCustom();
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(429);
		$this->client->method('post')->willThrowException(
			new class('rate limited', $response) extends \RuntimeException {
				public function __construct(string $message, private object $response) {
					parent::__construct($message);
				}

				public function getResponse(): object {
					return $this->response;
				}
			}
		);

		$this->expectException(\OCA\Budget\Service\Ocr\OcrQuotaExhaustedException::class);

		$this->service->extract('user1', $this->pngUpload());
	}

	public function testProviderProseBecomesAProviderException(): void {
		$this->configureCustom();
		$this->modelAnswers('I am sorry, I cannot read this image.');

		$this->expectException(OcrProviderException::class);

		$this->service->extract('user1', $this->pngUpload());
	}

	public function testCustomEndpointWithoutAKeySendsNoAuthorizationHeader(): void {
		$this->configureCustom(apiKey: '');
		$this->modelAnswers('{"merchant": "Shop"}');

		$this->service->extract('user1', $this->pngUpload());

		$this->assertArrayNotHasKey('Authorization', $this->lastRequest['options']['headers']);
	}

	public function testRelaySendsTheLicenseKeyAndADefaultModel(): void {
		$this->settings->method('isConfigured')->willReturn(true);
		$this->settings->method('getProvider')->willReturn(OcrSettingsService::PROVIDER_RELAY);
		$this->settings->method('getEndpoint')->willReturn(OcrSettingsService::RELAY_ENDPOINT);
		$this->settings->method('getModel')->willReturn('');
		$this->settings->method('getApiKey')->willReturn('lic_123');
		$this->modelAnswers('{"merchant": "Shop"}');

		$this->service->extract('user1', $this->pngUpload());

		$this->assertSame('Bearer lic_123', $this->lastRequest['options']['headers']['Authorization']);
		$body = json_decode($this->lastRequest['options']['body'], true);
		$this->assertSame('receipt-v1', $body['model']);
		$this->assertStringStartsWith(OcrSettingsService::RELAY_ENDPOINT, $this->lastRequest['url']);
	}

	public function testOnlyTheImageIsSentToTheProvider(): void {
		// The privacy contract: no categories, no accounts, no ledger.
		$this->configureCustom();
		$this->modelAnswers('{"merchant": "Shop"}');

		$this->service->extract('user1', $this->pngUpload());

		$body = $this->lastRequest['options']['body'];
		$this->assertStringNotContainsString('categor', strtolower($body));
		$this->assertStringNotContainsString('account', strtolower($body));
		$this->assertStringContainsString(self::PNG_BASE64, $body);
	}

	// ── the Nextcloud TaskProcessing path ───────────────────────────

	public function testNextcloudProviderParsesTheRawText(): void {
		$this->settings->method('isConfigured')->willReturn(true);
		$this->settings->method('getProvider')->willReturn(OcrSettingsService::PROVIDER_NEXTCLOUD);
		$this->backend->method('extractText')->willReturn("CORNER SHOP\n2026-08-02\nApples 2.00\nTOTAL 2.00");

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertSame('Corner Shop', $draft['merchant']);
		$this->assertSame('2026-08-02', $draft['date']);
		$this->assertSame('2.00', $draft['total']);
		$this->assertNull($draft['currency']);
		$this->assertSame([['description' => 'Apples', 'amount' => '2.00']], $draft['lineItems']);
	}

	// ── the local category suggestion ───────────────────────────────

	public function testSuggestsACategoryFromTheUsersOwnRules(): void {
		$this->configureCustom();
		$this->modelAnswers('{"merchant": "Tesco Express", "total": 9.75, "date": "2026-08-01"}');

		$this->ruleService->method('testRules')->willReturn([
			['ruleId' => 1, 'categoryId' => 42, 'priority' => 10],
		]);
		$category = new Category();
		$category->setId(42);
		$category->setName('Groceries');
		$this->categoryService->method('find')->with(42, 'user1')->willReturn($category);

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertSame(42, $draft['suggestedCategoryId']);
		$this->assertSame('Groceries', $draft['suggestedCategoryName']);
	}

	public function testARuleForADeletedCategorySuggestsNothing(): void {
		$this->configureCustom();
		$this->modelAnswers('{"merchant": "Shop"}');

		$this->ruleService->method('testRules')->willReturn([
			['ruleId' => 1, 'categoryId' => 99, 'priority' => 10],
		]);
		$this->categoryService->method('find')->willThrowException(new DoesNotExistException('gone'));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertNull($draft['suggestedCategoryId']);
		$this->assertNull($draft['suggestedCategoryName']);
	}

	public function testARuleEngineFailureDoesNotSinkTheExtraction(): void {
		$this->configureCustom();
		$this->modelAnswers('{"merchant": "Shop", "total": 5, "date": "2026-08-01"}');
		$this->ruleService->method('testRules')->willThrowException(new \RuntimeException('boom'));

		$draft = $this->service->extract('user1', $this->pngUpload());

		$this->assertSame('5.00', $draft['total']);
		$this->assertNull($draft['suggestedCategoryId']);
	}

	// ── helpers ─────────────────────────────────────────────────────

	private function configureCustom(string $apiKey = 'sk-test'): void {
		$this->settings->method('isConfigured')->willReturn(true);
		$this->settings->method('getProvider')->willReturn(OcrSettingsService::PROVIDER_CUSTOM);
		$this->settings->method('getEndpoint')->willReturn('http://ollama.lan:11434/v1');
		$this->settings->method('getModel')->willReturn('qwen2.5vl');
		$this->settings->method('getApiKey')->willReturn($apiKey);
	}

	/** Makes the mocked endpoint answer with the given assistant content. */
	private function modelAnswers(string $content): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
		]));
		$this->client->method('post')->willReturnCallback(function (string $url, array $options) use ($response) {
			$this->lastRequest = ['url' => $url, 'options' => $options];

			return $response;
		});
	}

	private function pngUpload(): array {
		return $this->upload(base64_decode(self::PNG_BASE64), 'image/png');
	}

	private function upload(string $bytes, string $claimedType): array {
		$path = tempnam(sys_get_temp_dir(), 'ocr-test-');
		file_put_contents($path, $bytes);
		$this->tmpFiles[] = $path;

		return [
			'name' => 'receipt.png',
			'type' => $claimedType,
			'tmp_name' => $path,
			'error' => UPLOAD_ERR_OK,
			'size' => strlen($bytes),
		];
	}
}

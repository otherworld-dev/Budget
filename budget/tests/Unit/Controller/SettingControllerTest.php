<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\SettingController;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ImportFolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingControllerTest extends TestCase {
	private SettingController $controller;
	private SettingMapper $mapper;
	private IRequest $request;
	private LoggerInterface $logger;
	/** @var ImportFolderService&\PHPUnit\Framework\MockObject\MockObject */
	private $importFolders;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(SettingMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);

		$this->importFolders = $this->createMock(ImportFolderService::class);
		$this->importFolders->method('mainFolderFor')->willReturn('Budget/Import');

		$this->controller = new SettingController(
			$this->request,
			$this->mapper,
			$granularShareService,
			$l,
			'user1',
			$this->logger,
			$this->importFolders
		);
	}

	private function makeSetting(string $key, string $value): Setting {
		$s = new Setting();
		$s->setKey($key);
		$s->setValue($value);
		$s->setUserId('user1');
		return $s;
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsSettingsMergedWithDefaults(): void {
		$settings = [
			$this->makeSetting('default_currency', 'USD'),
			$this->makeSetting('date_format', 'm/d/Y'),
		];
		$this->mapper->method('findAll')->with('user1')->willReturn($settings);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		// User overrides
		$this->assertSame('USD', $data['default_currency']);
		$this->assertSame('m/d/Y', $data['date_format']);
		// Defaults for missing keys
		$this->assertSame('0', $data['first_day_of_week']);
		$this->assertSame('2', $data['number_format_decimals']);
	}

	public function testIndexReturnsAllDefaultsWhenNoUserSettings(): void {
		$this->mapper->method('findAll')->willReturn([]);

		$response = $this->controller->index();

		$data = $response->getData();
		$this->assertSame('GBP', $data['default_currency']);
		$this->assertSame('Y-m-d', $data['date_format']);
		$this->assertSame('true', $data['notification_budget_alert']);
	}

	/**
	 * The "Skip Duplicate Transactions" switch did nothing: duplicates are
	 * skipped unless the import screen's own box says otherwise. The switch
	 * is gone and the app no longer offers the setting.
	 */
	public function testTheSkipDuplicatesSettingIsNoLongerOffered(): void {
		$this->mapper->method('findAll')->willReturn([]);

		$this->assertArrayNotHasKey('import_skip_duplicates', $this->controller->index()->getData());
	}

	/**
	 * The Enable Banking keys are an encrypted private key. The settings UI
	 * only learns whether they exist, from its own endpoint; this API must
	 * not hand the ciphertext to the browser, nor write or delete it.
	 */
	public function testTheEnableBankingKeysNeverLeaveThroughTheSettingsApi(): void {
		$this->mapper->method('findAll')->with('user1')->willReturn([
			$this->makeSetting('enablebanking_keys', 'CIPHERTEXT'),
			$this->makeSetting('default_currency', 'EUR'),
		]);
		$this->mapper->method('findByKey')->willReturn($this->makeSetting('enablebanking_keys', 'CIPHERTEXT'));
		$this->mapper->expects($this->never())->method('deleteByKey');
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$index = $this->controller->index();
		$this->assertArrayNotHasKey('enablebanking_keys', $index->getData());
		$this->assertSame('EUR', $index->getData()['default_currency']);

		$show = $this->controller->show('enablebanking_keys');
		$this->assertSame(Http::STATUS_NOT_FOUND, $show->getStatus());
		$this->assertStringNotContainsString('CIPHERTEXT', json_encode($show->getData()));

		$this->request->method('getParam')->with('value')->willReturn('x');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateKey('enablebanking_keys')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->destroy('enablebanking_keys')->getStatus());
	}

	public function testIndexHandlesException(): void {
		$this->mapper->method('findAll')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to retrieve settings', $response->getData()['error']);
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsExistingSetting(): void {
		$setting = $this->makeSetting('default_currency', 'EUR');
		$this->mapper->method('findByKey')->with('user1', 'default_currency')->willReturn($setting);

		$response = $this->controller->show('default_currency');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('default_currency', $data['key']);
		$this->assertSame('EUR', $data['value']);
	}

	public function testShowReturnsDefaultWhenSettingMissing(): void {
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->show('default_currency');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('default_currency', $data['key']);
		$this->assertSame('GBP', $data['value']);
	}

	public function testShowReturns404ForUnknownKey(): void {
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->show('nonexistent_key');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Setting not found', $response->getData()['error']);
	}

	public function testShowHandlesUnexpectedException(): void {
		$this->mapper->method('findByKey')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->show('some_key');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateExistingSetting(): void {
		$setting = $this->makeSetting('default_currency', 'GBP');

		$this->request->method('getParams')->willReturn([
			'default_currency' => 'USD',
		]);
		$this->mapper->method('findByKey')
			->with('user1', 'default_currency')
			->willReturn($setting);
		$this->mapper->expects($this->once())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Settings updated successfully', $data['message']);
		$this->assertSame('USD', $data['settings']['default_currency']);
	}

	public function testUpdateCreatesNewSetting(): void {
		$this->request->method('getParams')->willReturn([
			'transaction_columns_visible' => '{"vendor":false}',
		]);
		$this->mapper->method('findByKey')
			->willThrowException(new DoesNotExistException(''));
		$this->mapper->expects($this->once())->method('insert');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('{"vendor":false}', $data['settings']['transaction_columns_visible']);
	}

	/**
	 * Settings the app keeps for itself can't be written by the client. The
	 * calendar feed token was settable: a chosen value hijacked the public
	 * feed URL, and one already used by someone else broke their feed.
	 */
	public function testUpdateRefusesASettingTheAppKeepsForItself(): void {
		$this->request->method('getParams')->willReturn([
			'date_format' => 'd/m/Y',
			'bills_feed_token' => str_repeat('a', 64),
		]);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame("This setting can't be changed: bills_feed_token", $response->getData()['error']);
	}

	public function testUpdateKeyRefusesASettingTheAppKeepsForItself(): void {
		$this->request->method('getParam')->with('value')->willReturn('1');
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->updateKey('sample_data_loaded');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/** Every key the web app saves, so none of them stops saving */
	#[\PHPUnit\Framework\Attributes\DataProvider('keysTheAppSaves')]
	public function testUpdateAcceptsEveryKeyTheAppSaves(string $key): void {
		$this->request->method('getParams')->willReturn([$key => 'x']);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
		$this->mapper->expects($this->once())->method('insert');

		$this->assertSame(Http::STATUS_OK, $this->controller->update()->getStatus());
	}

	public static function keysTheAppSaves(): array {
		$keys = [
			// The Settings page
			'anomaly_alerts_enabled', 'budget_alert_threshold', 'budget_period', 'budget_start_day', 'date_format',
			'default_currency', 'digest_email_enabled', 'digest_enabled', 'digest_frequency', 'export_default_format',
			'first_day_of_week', 'import_auto_apply_rules', 'import_folder', 'notification_budget_alert',
			'notification_forecast_warning', 'number_format_decimal_sep', 'number_format_decimals',
			'number_format_thousands_sep', 'receipt_folder', 'report_email_enabled', 'report_files_enabled',
			// The dashboard
			'budget_alert_scope', 'budget_alert_muted_categories', 'dashboard_hero_config', 'dashboard_widgets_config',
			'dashboard_grid_columns', 'dashboard_locked',
			// The transaction list's columns and the What's new dialog
			'transaction_columns_visible', 'whats_new_seen',
			// A Settings page loaded before the switch was removed
			'import_skip_duplicates',
		];
		return array_combine($keys, array_map(static fn (string $key) => [$key], $keys));
	}

	public function testUpdateUpdatesRowAddedByAnOverlappingSave(): void {
		// Two saves of a never-stored setting: both find nothing, the other
		// one inserts first, and this insert hits the unique index.
		$this->request->method('getParams')->willReturn(['date_format' => 'd/m/Y']);
		$added = $this->makeSetting('date_format', 'Y-m-d');
		$this->mapper->method('findByKey')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new DoesNotExistException('')),
				$added
			);
		$duplicate = $this->createMock(\OCP\DB\Exception::class);
		$duplicate->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->mapper->method('insert')->willThrowException($duplicate);
		$this->mapper->expects($this->once())->method('update')->with($added)->willReturnArgument(0);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('d/m/Y', $added->getValue());
		$this->assertSame('d/m/Y', $response->getData()['settings']['date_format']);
	}

	public function testUpdateStillFailsOnOtherDatabaseErrors(): void {
		$this->request->method('getParams')->willReturn(['date_format' => 'd/m/Y']);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
		$other = $this->createMock(\OCP\DB\Exception::class);
		$other->method('getReason')->willReturn(\OCP\DB\Exception::REASON_NOT_NULL_CONSTRAINT_VIOLATION);
		$this->mapper->method('insert')->willThrowException($other);
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testUpdateKeyUpdatesRowAddedByAnOverlappingSave(): void {
		$this->request->method('getParam')->with('value')->willReturn('EUR');
		$added = $this->makeSetting('default_currency', 'GBP');
		$this->mapper->method('findByKey')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new DoesNotExistException('')),
				$added
			);
		$duplicate = $this->createMock(\OCP\DB\Exception::class);
		$duplicate->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->mapper->method('insert')->willThrowException($duplicate);
		$this->mapper->expects($this->once())->method('update')->with($added)->willReturnArgument(0);

		$response = $this->controller->updateKey('default_currency');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('EUR', $added->getValue());
	}

	public function testUpdateSkipsInternalParameters(): void {
		$this->request->method('getParams')->willReturn([
			'_route' => 'some_route',
			'controller' => 'setting',
			'action' => 'update',
			'date_format' => 'd/m/Y',
		]);
		$existingSetting = $this->makeSetting('date_format', 'Y-m-d');
		$this->mapper->method('findByKey')->willReturn($existingSetting);

		// Only one update for date_format, not for _route/controller/action
		$this->mapper->expects($this->once())->method('update');

		$response = $this->controller->update();

		$data = $response->getData();
		$this->assertArrayHasKey('date_format', $data['settings']);
		$this->assertArrayNotHasKey('_route', $data['settings']);
	}

	public function testUpdateHandlesException(): void {
		$this->request->method('getParams')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── updateKey ───────────────────────────────────────────────────

	public function testUpdateKeyUpdatesExistingSetting(): void {
		$setting = $this->makeSetting('default_currency', 'GBP');
		$this->request->method('getParam')->with('value')->willReturn('EUR');
		$this->mapper->method('findByKey')->willReturn($setting);
		$this->mapper->expects($this->once())->method('update');

		$response = $this->controller->updateKey('default_currency');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Setting updated successfully', $data['message']);
		$this->assertSame('default_currency', $data['key']);
		$this->assertSame('EUR', $data['value']);
	}

	public function testUpdateKeyCreatesNewSetting(): void {
		$this->request->method('getParam')->with('value')->willReturn('some_val');
		$this->mapper->method('findByKey')
			->willThrowException(new DoesNotExistException(''));
		$this->mapper->expects($this->once())->method('insert');

		$response = $this->controller->updateKey('whats_new_seen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateKeyRejectsMissingValue(): void {
		$this->request->method('getParam')->with('value')->willReturn(null);

		$response = $this->controller->updateKey('some_key');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Value parameter is required', $response->getData()['error']);
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesSetting(): void {
		$this->mapper->method('deleteByKey')->with('user1', 'date_format')->willReturn(1);

		$response = $this->controller->destroy('date_format');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Setting reset to default', $data['message']);
		$this->assertSame('date_format', $data['key']);
		// Should return the default value
		$this->assertSame('Y-m-d', $data['default_value']);
	}

	public function testDestroyReturns404WhenNotFound(): void {
		$this->mapper->method('deleteByKey')->willReturn(0);

		$response = $this->controller->destroy('nonexistent');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsNullDefaultForUnknownKey(): void {
		$this->mapper->method('deleteByKey')->with('user1', 'custom_key')->willReturn(1);

		$response = $this->controller->destroy('custom_key');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()['default_value']);
	}

	// ── reset ───────────────────────────────────────────────────────

	public function testResetDeletesAllSettings(): void {
		$this->mapper->method('deleteAll')->with('user1')->willReturn(5);

		$response = $this->controller->reset();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('All settings reset to defaults', $data['message']);
		$this->assertSame(5, $data['deleted_count']);
		$this->assertIsArray($data['defaults']);
		$this->assertSame('GBP', $data['defaults']['default_currency']);
	}

	public function testResetHandlesException(): void {
		$this->mapper->method('deleteAll')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->reset();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── options ─────────────────────────────────────────────────────

	public function testOptionsReturnsCurrenciesAndFormats(): void {
		$response = $this->controller->options();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();

		// Should include currencies
		$this->assertArrayHasKey('currencies', $data);
		$this->assertNotEmpty($data['currencies']);
		$firstCurrency = $data['currencies'][0];
		$this->assertArrayHasKey('code', $firstCurrency);
		$this->assertArrayHasKey('name', $firstCurrency);
		$this->assertArrayHasKey('symbol', $firstCurrency);

		// Should include date formats
		$this->assertArrayHasKey('date_formats', $data);
		$this->assertNotEmpty($data['date_formats']);

		// Should include other option arrays
		$this->assertArrayHasKey('first_day_of_week', $data);
		$this->assertArrayHasKey('budget_periods', $data);
		$this->assertArrayHasKey('export_formats', $data);
	}

	public function testOptionsContainsExpectedDateFormats(): void {
		$response = $this->controller->options();
		$data = $response->getData();

		$dateFormatValues = array_column($data['date_formats'], 'value');
		$this->assertContains('Y-m-d', $dateFormatValues);
		$this->assertContains('m/d/Y', $dateFormatValues);
		$this->assertContains('d/m/Y', $dateFormatValues);
	}

	public function testOptionsContainsExpectedExportFormats(): void {
		$response = $this->controller->options();
		$data = $response->getData();

		$exportValues = array_column($data['export_formats'], 'value');
		$this->assertContains('csv', $exportValues);
		$this->assertContains('json', $exportValues);
		$this->assertContains('pdf', $exportValues);
	}

	// ── receipt_folder validation (#352) ────────────────────────────

	public function testUpdateRejectsReceiptFolderThatEscapesFiles(): void {
		$this->request->method('getParams')->willReturn(['receipt_folder' => '../../etc']);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateStoresNormalizedReceiptFolder(): void {
		$this->request->method('getParams')->willReturn(['receipt_folder' => chr(92) . 'Applications' . chr(92) . 'Budget' . chr(92)]);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$stored = null;
		$this->mapper->method('insert')->willReturnCallback(function (Setting $s) use (&$stored) {
			$stored = $s->getValue();
			return $s;
		});

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Applications/Budget', $stored);
	}

	public function testUpdateAcceptsBlankReceiptFolderAsDefault(): void {
		$this->request->method('getParams')->willReturn(['receipt_folder' => '   ']);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── import_folder validation (#425) ─────────────────────────────

	public function testTheImportFolderIsNormalisedLikeTheReceiptsFolder(): void {
		$this->request->method('getParams')->willReturn(['import_folder' => chr(92) . 'Bank' . chr(92) . 'Statements' . chr(92)]);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$stored = null;
		$this->mapper->method('insert')->willReturnCallback(function (Setting $s) use (&$stored) {
			$stored = $s->getValue();
			return $s;
		});

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Bank/Statements', $stored);
	}

	public function testAnImportFolderOutsideFilesIsRefused(): void {
		$this->request->method('getParams')->willReturn(['import_folder' => '../elsewhere']);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid import folder', $response->getData()['error']);
	}

	// ── moving the import folder leaves what is there alone ─────────

	public function testMovingTheImportFolderLeavesTheFilesAlreadyThereAlone(): void {
		$this->request->method('getParams')->willReturn(['import_folder' => '/Bank/']);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->importFolders->expects($this->once())->method('leaveExistingAlone')->with('user1', 'Bank')->willReturn(['Conto' => 2]);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['Conto' => 2], $response->getData()['importFolderLeftAlone']);
	}

	public function testSavingTheSameImportFolderAgainLooksAtNothing(): void {
		$this->request->method('getParams')->willReturn(['import_folder' => '']);
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->importFolders->expects($this->never())->method('leaveExistingAlone');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['importFolderLeftAlone']);
	}

	public function testUpdateKeyNormalisesTheImportFolderAndLeavesWhatIsThereAlone(): void {
		$this->request->method('getParam')->with('value')->willReturn('Bank//Statements/');
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$stored = null;
		$this->mapper->method('insert')->willReturnCallback(function (Setting $s) use (&$stored) {
			$stored = $s->getValue();
			return $s;
		});
		$this->importFolders->expects($this->once())->method('leaveExistingAlone')->with('user1', 'Bank/Statements')->willReturn([]);

		$response = $this->controller->updateKey('import_folder');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Bank/Statements', $stored);
	}

	public function testUpdateKeyRefusesAnImportFolderOutsideFiles(): void {
		$this->request->method('getParam')->with('value')->willReturn('../elsewhere');
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateKey('import_folder')->getStatus());
	}

	public function testResettingTheImportFolderLeavesWhatTheDefaultOneHoldsAlone(): void {
		$importFolders = $this->createMock(ImportFolderService::class);
		$importFolders->method('mainFolderFor')->willReturn('Bank');
		$importFolders->expects($this->exactly(2))->method('leaveExistingAlone')->with('user1', 'Budget/Import')->willReturn([]);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$granular = $this->createMock(GranularShareService::class);
		$controller = new SettingController($this->request, $this->mapper, $granular, $l, 'user1', $this->logger, $importFolders);
		$this->mapper->method('deleteByKey')->willReturn(1);
		$this->mapper->method('deleteAll')->willReturn(3);

		$controller->destroy('import_folder');
		$controller->reset();
	}

	// ── colour scheme (#432) ────────────────────────────────────────

	/** @return array<string, string> key => value actually stored, over the whole request */
	private function captureStoredValues(): \ArrayObject {
		$stored = new \ArrayObject();
		$this->mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
		$this->mapper->method('insert')->willReturnCallback(function (Setting $s) use ($stored) {
			$stored[$s->getKey()] = $s->getValue();
			return $s;
		});
		return $stored;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('colourSchemes')]
	public function testUpdateAcceptsColourScheme(string $scheme): void {
		$this->request->method('getParams')->willReturn(['amount_color_scheme' => $scheme]);
		$stored = $this->captureStoredValues();

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($scheme, $stored['amount_color_scheme']);
	}

	public static function colourSchemes(): array {
		return ['default' => ['default'], 'colorblind' => ['colorblind'], 'custom' => ['custom']];
	}

	public function testUpdateRefusesUnknownScheme(): void {
		// date_format comes first and is valid: all or nothing
		$this->request->method('getParams')->willReturn([
			'date_format' => 'd/m/Y',
			'amount_color_scheme' => 'pink',
		]);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid colour setting', $response->getData()['error']);
	}

	public function testUpdateCanonicalisesCustomColours(): void {
		$this->request->method('getParams')->willReturn([
			'amount_color_custom' => '{"darkNegative":"#F5A35C","lightPositive":"#0B62A4","lightNegative":"#A84800","darkPositive":"#6CB4EE"}',
		]);
		$stored = $this->captureStoredValues();
		$canonical = '{"lightPositive":"#0b62a4","lightNegative":"#a84800","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}';

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($canonical, $stored['amount_color_custom']);
		$this->assertSame($canonical, $response->getData()['settings']['amount_color_custom']);
	}

	public function testUpdateRefusesMalformedCustomColours(): void {
		$this->request->method('getParams')->willReturn([
			'date_format' => 'd/m/Y',
			'amount_color_custom' => '{"lightPositive":"red"}',
		]);
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid colour setting', $response->getData()['error']);
	}

	public function testUpdateKeyValidatesColourKeys(): void {
		$canonical = '{"lightPositive":"#0b62a4","lightNegative":"#a84800","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}';
		$values = [
			['amount_color_scheme', 'pink', false],
			['amount_color_scheme', 'colorblind', true],
			['amount_color_custom', 'not json', false],
			['amount_color_custom', preg_replace_callback('/#[0-9a-f]{6}/', static fn (array $m) => strtoupper($m[0]), $canonical), true],
		];
		foreach ($values as [$key, $value, $ok]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->with('value')->willReturn($value);
			$mapper = $this->createMock(SettingMapper::class);
			$mapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
			$stored = null;
			$mapper->expects($ok ? $this->once() : $this->never())->method('insert')
				->willReturnCallback(function (Setting $s) use (&$stored) {
					$stored = $s->getValue();
					return $s;
				});
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnArgument(0);
			$controller = new SettingController($request, $mapper, $this->createMock(GranularShareService::class), $l, 'user1', $this->logger, $this->importFolders);

			$response = $controller->updateKey($key);

			if ($ok) {
				$this->assertSame(Http::STATUS_OK, $response->getStatus(), "$key=$value");
				$expected = $key === 'amount_color_custom' ? $canonical : $value;
				$this->assertSame($expected, $stored);
				$this->assertSame($expected, $response->getData()['value']);
			} else {
				$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), "$key=$value");
				$this->assertSame('Invalid colour setting', $response->getData()['error']);
			}
		}
	}

	public function testIndexIncludesColourDefaults(): void {
		$this->mapper->method('findAll')->willReturn([]);

		$data = $this->controller->index()->getData();

		$this->assertSame('default', $data['amount_color_scheme']);
		$this->assertSame(\OCA\Budget\Service\AmountColours::DEFAULT_CUSTOM_JSON, $data['amount_color_custom']);
	}
}

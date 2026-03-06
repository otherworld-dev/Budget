<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\SettingController;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingControllerTest extends TestCase {
	private SettingController $controller;
	private SettingMapper $mapper;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(SettingMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new SettingController(
			$this->request,
			$this->mapper,
			'user1',
			$this->logger
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
			'custom_key' => 'custom_value',
		]);
		$this->mapper->method('findByKey')
			->willThrowException(new DoesNotExistException(''));
		$this->mapper->expects($this->once())->method('insert');

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('custom_value', $data['settings']['custom_key']);
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

		$response = $this->controller->updateKey('new_key');

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
}

<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ExchangeRateController;
use OCA\Budget\Db\ExchangeRate;
use OCA\Budget\Db\ExchangeRateMapper;
use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\ManualExchangeRateService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExchangeRateControllerTest extends TestCase {
	private ExchangeRateController $controller;
	private ExchangeRateService $exchangeRateService;
	private CurrencyConversionService $conversionService;
	private ManualExchangeRateService $manualRateService;
	private ExchangeRateMapper $exchangeRateMapper;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->exchangeRateService = $this->createMock(ExchangeRateService::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);
		$this->manualRateService = $this->createMock(ManualExchangeRateService::class);
		$this->exchangeRateMapper = $this->createMock(ExchangeRateMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ExchangeRateController(
			$this->request,
			$this->exchangeRateService,
			$this->conversionService,
			$this->manualRateService,
			$this->exchangeRateMapper,
			'user1',
			$logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsRatesAndCurrencies(): void {
		$this->conversionService->method('getBaseCurrency')
			->with('user1')->willReturn('USD');

		$autoRate = new ExchangeRate();
		$autoRate->setCurrency('USD');
		$autoRate->setRatePerEur('1.08');
		$autoRate->setSource('ecb');
		$autoRate->setDate('2025-01-15');

		$this->exchangeRateMapper->method('findAllLatest')
			->willReturn([$autoRate]);

		$manualRate = new ManualExchangeRate();
		$manualRate->setCurrency('GBP');
		$manualRate->setRatePerEur('0.86');
		$manualRate->setUpdatedAt('2025-01-15 10:00:00');

		$this->manualRateService->method('getAllForUser')
			->with('user1')->willReturn([$manualRate]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('USD', $data['baseCurrency']);
		$this->assertArrayHasKey('autoRates', $data);
		$this->assertArrayHasKey('manualRates', $data);
		$this->assertArrayHasKey('currencies', $data);
		$this->assertArrayHasKey('USD', $data['autoRates']);
		$this->assertArrayHasKey('GBP', $data['manualRates']);
	}

	public function testIndexHandlesException(): void {
		$this->conversionService->method('getBaseCurrency')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── latest ──────────────────────────────────────────────────────

	public function testLatestReturnsBaseCurrency(): void {
		$this->conversionService->method('getBaseCurrency')
			->with('user1')->willReturn('EUR');

		$response = $this->controller->latest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('EUR', $response->getData()['baseCurrency']);
	}

	public function testLatestHandlesException(): void {
		$this->conversionService->method('getBaseCurrency')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->latest();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── refresh ─────────────────────────────────────────────────────

	public function testRefreshSuccess(): void {
		$this->exchangeRateService->expects($this->once())
			->method('fetchLatestRates');

		$response = $this->controller->refresh();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ok', $response->getData()['status']);
	}

	public function testRefreshHandlesException(): void {
		$this->exchangeRateService->method('fetchLatestRates')
			->willThrowException(new \RuntimeException('API error'));

		$response = $this->controller->refresh();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── setManualRate ───────────────────────────────────────────────

	public function testSetManualRateSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['currency', null, 'GBP'],
				['rate', null, '0.86'],
			]);

		$entity = new ManualExchangeRate();
		$entity->setCurrency('GBP');
		$entity->setRatePerEur('0.86');
		$this->manualRateService->expects($this->once())
			->method('setRate')
			->with('user1', 'GBP', '0.86')
			->willReturn($entity);

		$response = $this->controller->setManualRate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetManualRateRejectsEmptyCurrency(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['currency', null, ''],
				['rate', null, '1.5'],
			]);

		$response = $this->controller->setManualRate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testSetManualRateRejectsEmptyRate(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['currency', null, 'GBP'],
				['rate', null, ''],
			]);

		$response = $this->controller->setManualRate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetManualRateRejectsNullParams(): void {
		$this->request->method('getParam')
			->willReturn(null);

		$response = $this->controller->setManualRate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetManualRateHandlesInvalidArgument(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['currency', null, 'INVALID'],
				['rate', null, '1.5'],
			]);

		$this->manualRateService->method('setRate')
			->willThrowException(new \InvalidArgumentException('Invalid currency'));

		$response = $this->controller->setManualRate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid currency', $response->getData()['error']);
	}

	public function testSetManualRateHandlesGeneralException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['currency', null, 'GBP'],
				['rate', null, '0.86'],
			]);

		$this->manualRateService->method('setRate')
			->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->setManualRate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── removeManualRate ────────────────────────────────────────────

	public function testRemoveManualRateSuccess(): void {
		$this->manualRateService->expects($this->once())
			->method('removeRate')
			->with('user1', 'GBP');

		$response = $this->controller->removeManualRate('GBP');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ok', $response->getData()['status']);
	}

	public function testRemoveManualRateHandlesException(): void {
		$this->manualRateService->method('removeRate')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->removeManualRate('GBP');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}

<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\CalendarFeedController;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Service\Bill\BillIcsService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalendarFeedControllerTest extends TestCase {
	private CalendarFeedController $controller;
	private BillIcsService $icsService;
	private SettingService $settingService;
	private SettingMapper $settingMapper;
	private ISecureRandom $random;

	private const VALID_TOKEN = 'aaaaaaaaaabbbbbbbbbbccccccccccddddddddddeeeeeeeeeeffffffffffgggg';

	protected function setUp(): void {
		$this->icsService = $this->createMock(BillIcsService::class);
		$this->settingService = $this->createMock(SettingService::class);
		$this->settingMapper = $this->createMock(SettingMapper::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('budget.calendarFeed.billsIcs')
			->willReturn('https://nc.test/apps/budget/feed/bills.ics');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->controller = new CalendarFeedController(
			$this->createMock(IRequest::class),
			$this->icsService,
			$this->settingService,
			$this->settingMapper,
			$this->random,
			$urlGenerator,
			$l,
			'alice',
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testWrongLengthTokenIsThrottled404(): void {
		$this->settingMapper->expects($this->never())->method('findByKeyValue');

		$response = $this->controller->billsIcs('short');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testUnknownTokenIsThrottled404(): void {
		$this->settingMapper->method('findByKeyValue')
			->willThrowException(new DoesNotExistException('no such token'));

		$response = $this->controller->billsIcs(self::VALID_TOKEN);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testValidTokenServesOwnersFeed(): void {
		// Response::cacheFor() resolves ITimeFactory through the server container
		$timeFactory = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1765000000);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($timeFactory);
		\OC::$server = $container;

		$setting = new Setting();
		$setting->setUserId('bob');
		$this->settingMapper->method('findByKeyValue')
			->with('bills_feed_token', self::VALID_TOKEN)
			->willReturn($setting);
		$this->icsService->expects($this->once())
			->method('generateBillsFeed')
			->with('bob', 12)
			->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$response = $this->controller->billsIcs(self::VALID_TOKEN);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		\OC::$server = null;
	}

	public function testInfoCreatesTokenWhenMissing(): void {
		$this->settingService->method('get')->with('alice', 'bills_feed_token')->willReturn(null);
		$this->random->expects($this->once())
			->method('generate')
			->with(64, ISecureRandom::CHAR_ALPHANUMERIC)
			->willReturn(self::VALID_TOKEN);
		$this->settingService->expects($this->once())
			->method('set')
			->with('alice', 'bills_feed_token', self::VALID_TOKEN);

		$response = $this->controller->info();
		$data = $response->getData();

		$this->assertStringContainsString('?token=' . self::VALID_TOKEN, $data['url']);
		$this->assertStringStartsWith('webcal://', $data['webcalUrl']);
	}

	public function testInfoReusesExistingToken(): void {
		$this->settingService->method('get')->willReturn(self::VALID_TOKEN);
		$this->random->expects($this->never())->method('generate');
		$this->settingService->expects($this->never())->method('set');

		$response = $this->controller->info();

		$this->assertStringContainsString('?token=' . self::VALID_TOKEN, $response->getData()['url']);
	}

	public function testRegenerateAlwaysRotatesToken(): void {
		$newToken = str_repeat('z', 64);
		$this->random->expects($this->once())->method('generate')->willReturn($newToken);
		$this->settingService->expects($this->once())
			->method('set')
			->with('alice', 'bills_feed_token', $newToken);

		$response = $this->controller->regenerate();

		$this->assertStringContainsString('?token=' . $newToken, $response->getData()['url']);
	}
}

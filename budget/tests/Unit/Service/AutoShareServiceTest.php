<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareAutoConfig;
use OCA\Budget\Db\ShareAutoConfigMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Service\AutoShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AutoShareServiceTest extends TestCase {
	private ShareAutoConfigMapper $autoConfigMapper;
	private ShareItemMapper $shareItemMapper;
	private ShareMapper $shareMapper;
	private LoggerInterface $logger;
	private AutoShareService $service;

	protected function setUp(): void {
		$this->autoConfigMapper = $this->createMock(ShareAutoConfigMapper::class);
		$this->shareItemMapper = $this->createMock(ShareItemMapper::class);
		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AutoShareService(
			$this->autoConfigMapper,
			$this->shareItemMapper,
			$this->shareMapper,
			$this->logger
		);
	}

	private function makeShare(int $id, string $owner, string $status = Share::STATUS_ACCEPTED): Share {
		$share = new Share();
		$share->setId($id);
		$share->setOwnerUserId($owner);
		$share->setSharedWithUserId('recipient');
		$share->setStatus($status);
		return $share;
	}

	private function givenShare(int $id, string $owner): void {
		$this->shareMapper->method('findById')->with($id)->willReturn($this->makeShare($id, $owner));
	}

	// ── autoShareNewEntity ──────────────────────────────────────────

	public function testNewEntityIsSharedWithEveryOptedInShareAtItsPermission(): void {
		$this->autoConfigMapper->method('findActiveForOwnerAndType')
			->with('alice', ShareItem::TYPE_ACCOUNT)
			->willReturn([
				['shareId' => 3, 'permission' => ShareItem::PERMISSION_READ],
				['shareId' => 4, 'permission' => ShareItem::PERMISSION_WRITE],
			]);

		$calls = [];
		$this->shareItemMapper->expects($this->exactly(2))
			->method('shareEntity')
			->willReturnCallback(function (...$args) use (&$calls) {
				$calls[] = $args;
				return true;
			});

		$this->service->autoShareNewEntity('alice', ShareItem::TYPE_ACCOUNT, 42);

		$this->assertSame([
			[3, ShareItem::TYPE_ACCOUNT, 42, ShareItem::PERMISSION_READ],
			[4, ShareItem::TYPE_ACCOUNT, 42, ShareItem::PERMISSION_WRITE],
		], $calls);
	}

	public function testNothingIsSharedWhenNoShareOptedIn(): void {
		$this->autoConfigMapper->method('findActiveForOwnerAndType')->willReturn([]);
		$this->shareItemMapper->expects($this->never())->method('shareEntity');
		$this->logger->expects($this->never())->method('warning');

		$this->service->autoShareNewEntity('alice', ShareItem::TYPE_BILL, 1);
	}

	/**
	 * Auto-share runs as a side effect of creating something. If it breaks,
	 * the create must still succeed, so the failure is logged and swallowed.
	 */
	public function testALookupFailureIsLoggedAndSwallowed(): void {
		$this->autoConfigMapper->method('findActiveForOwnerAndType')
			->willThrowException(new \RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->logicalAnd(
					$this->stringContains('bill #7'),
					$this->stringContains('db down')
				),
				$this->callback(fn(array $ctx) => ($ctx['app'] ?? null) === 'budget' && $ctx['exception'] instanceof \RuntimeException)
			);

		$this->service->autoShareNewEntity('alice', ShareItem::TYPE_BILL, 7);
	}

	public function testAShareFailureStopsFurtherSharesButNeverThrows(): void {
		$this->autoConfigMapper->method('findActiveForOwnerAndType')->willReturn([
			['shareId' => 3, 'permission' => ShareItem::PERMISSION_READ],
			['shareId' => 4, 'permission' => ShareItem::PERMISSION_READ],
		]);
		$this->shareItemMapper->expects($this->once())
			->method('shareEntity')
			->willThrowException(new \Error('type error'));
		$this->logger->expects($this->once())->method('warning');

		$this->service->autoShareNewEntity('alice', ShareItem::TYPE_CATEGORY, 9);
	}

	// ── getConfigs ──────────────────────────────────────────────────

	public function testOwnerGetsTheConfigsForTheirShare(): void {
		$this->givenShare(5, 'alice');
		$config = new ShareAutoConfig();
		$this->autoConfigMapper->method('findByShareId')->with(5)->willReturn([$config]);

		$this->assertSame([$config], $this->service->getConfigs('alice', 5));
	}

	public function testGetConfigsRefusesSomeoneElsesShare(): void {
		$this->givenShare(5, 'bob');
		$this->autoConfigMapper->expects($this->never())->method('findByShareId');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Not your share');

		$this->service->getConfigs('alice', 5);
	}

	public function testGetConfigsOnAMissingShareSaysNotFound(): void {
		$this->shareMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Share not found');

		$this->service->getConfigs('alice', 404);
	}

	// ── setConfig ───────────────────────────────────────────────────

	public function testEnablingStoresTheRuleAtTheChosenPermission(): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->once())
			->method('setConfig')
			->with(5, ShareItem::TYPE_ACCOUNT, ShareItem::PERMISSION_WRITE);
		$this->autoConfigMapper->expects($this->never())->method('removeConfig');

		$this->service->setConfig('alice', 5, ShareItem::TYPE_ACCOUNT, true, ShareItem::PERMISSION_WRITE);
	}

	public function testEnablingAtReadPermissionIsAccepted(): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->once())
			->method('setConfig')
			->with(5, ShareItem::TYPE_PROJECT, ShareItem::PERMISSION_READ);

		$this->service->setConfig('alice', 5, ShareItem::TYPE_PROJECT, true, ShareItem::PERMISSION_READ);
	}

	public function testDisablingRemovesTheRule(): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->once())
			->method('removeConfig')
			->with(5, ShareItem::TYPE_SAVINGS_GOAL);
		$this->autoConfigMapper->expects($this->never())->method('setConfig');

		$this->service->setConfig('alice', 5, ShareItem::TYPE_SAVINGS_GOAL, false, ShareItem::PERMISSION_READ);
	}

	public function testDisablingIgnoresThePermissionArgument(): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->once())->method('removeConfig');

		$this->service->setConfig('alice', 5, ShareItem::TYPE_BILL, false, 'nonsense');
	}

	public function testEnablingWithAnUnknownPermissionIsRejected(): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->never())->method('setConfig');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid permission');

		$this->service->setConfig('alice', 5, ShareItem::TYPE_ACCOUNT, true, 'admin');
	}

	public function testAnUnknownEntityTypeIsRejected(): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->never())->method('setConfig');
		$this->autoConfigMapper->expects($this->never())->method('removeConfig');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid entity type');

		$this->service->setConfig('alice', 5, 'transaction', true, ShareItem::PERMISSION_READ);
	}

	public function testEntityTypeMatchIsCaseSensitive(): void {
		$this->givenShare(5, 'alice');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->setConfig('alice', 5, 'Account', true, ShareItem::PERMISSION_READ);
	}

	#[DataProvider('validTypes')]
	public function testEveryShareableTypeCanBeConfigured(string $type): void {
		$this->givenShare(5, 'alice');
		$this->autoConfigMapper->expects($this->once())->method('setConfig')->with(5, $type, ShareItem::PERMISSION_READ);

		$this->service->setConfig('alice', 5, $type, true, ShareItem::PERMISSION_READ);
	}

	public static function validTypes(): array {
		return array_combine(ShareItem::VALID_TYPES, array_map(fn($t) => [$t], ShareItem::VALID_TYPES));
	}

	public function testSetConfigRefusesSomeoneElsesShareBeforeValidatingAnything(): void {
		$this->givenShare(5, 'bob');
		$this->autoConfigMapper->expects($this->never())->method('setConfig');
		$this->autoConfigMapper->expects($this->never())->method('removeConfig');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Not your share');

		$this->service->setConfig('alice', 5, 'bogus-type', false, 'bogus');
	}

	public function testSetConfigOnAMissingShareSaysNotFound(): void {
		$this->shareMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$this->autoConfigMapper->expects($this->never())->method('removeConfig');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Share not found');

		$this->service->setConfig('alice', 404, ShareItem::TYPE_ACCOUNT, false, ShareItem::PERMISSION_READ);
	}
}

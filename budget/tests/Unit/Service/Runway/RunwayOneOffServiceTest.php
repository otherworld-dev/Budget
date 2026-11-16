<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Runway;

use OCA\Budget\Db\RunwayOneOff;
use OCA\Budget\Db\RunwayOneOffMapper;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Service\Runway\RunwayOneOffService;
use OCA\Budget\Service\UserTableCleaner;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunwayOneOffServiceTest extends TestCase {
	private RunwayOneOffMapper&MockObject $mapper;
	private RunwayOneOffService $service;
	/** @var array<int, RunwayOneOff> */
	private array $rows = [];
	private int $nextId = 1;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RunwayOneOffMapper::class);
		$this->mapper->method('insert')->willReturnCallback(function (RunwayOneOff $row): RunwayOneOff {
			$row->setId($this->nextId++);
			$this->rows[$row->getId()] = $row;
			return $row;
		});
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('find')->willReturnCallback(function (int $id, string $userId): RunwayOneOff {
			$row = $this->rows[$id] ?? null;
			if ($row === null || $row->getUserId() !== $userId) {
				throw new DoesNotExistException('no such one-off');
			}
			return $row;
		});
		$this->mapper->method('delete')->willReturnCallback(function (RunwayOneOff $row): RunwayOneOff {
			unset($this->rows[$row->getId()]);
			return $row;
		});

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793000000);

		$this->service = new RunwayOneOffService($this->mapper, new ValidationService(), $time, $l);
	}

	public function testCreateStoresSignedAmount(): void {
		$row = $this->service->create('alice', '  Car repair ', '-864.27', '2026-12-15');

		$this->assertSame('-864.27', $row->getAmount());
		$this->assertSame('Car repair', $row->getDescription());
		$this->assertSame('2026-12-15', $row->getDate());
		$this->assertSame('alice', $row->getUserId());
		$this->assertSame(
			['id' => 1, 'description' => 'Car repair', 'amount' => '-864.27', 'date' => '2026-12-15'],
			$row->jsonSerialize()
		);
	}

	public function testCreateRejectsZeroAmount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', 'Nothing', '0.00', '2026-12-15');
	}

	public function testCreateRejectsThreeDecimals(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', 'Fuzzy', '-10.001', '2026-12-15');
	}

	public function testCreateRejectsBadDate(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', 'Bad date', '-10', '2026-02-30');
	}

	public function testCreateRejectsEmptyDescription(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', '   ', '-10', '2026-12-15');
	}

	public function testCreateRejectsOverlongDescription(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('alice', str_repeat('x', 256), '-10', '2026-12-15');
	}

	public function testUpdateChangesOnlyGivenFields(): void {
		$row = $this->service->create('alice', 'Car repair', '-864.27', '2026-12-15');

		$updated = $this->service->update('alice', $row->getId(), ['amount' => '-900']);

		$this->assertSame('-900.00', $updated->getAmount());
		$this->assertSame('Car repair', $updated->getDescription());
		$this->assertSame('2026-12-15', $updated->getDate());
	}

	public function testUpdateValidatesWhatItChanges(): void {
		$row = $this->service->create('alice', 'Car repair', '-864.27', '2026-12-15');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update('alice', $row->getId(), ['date' => 'next week']);
	}

	public function testCannotTouchAnotherUsersOneOff(): void {
		$row = $this->service->create('alice', 'Car repair', '-864.27', '2026-12-15');

		foreach ([
			fn () => $this->service->update('bob', $row->getId(), ['description' => 'Mine now']),
			fn () => $this->service->delete('bob', $row->getId()),
		] as $attempt) {
			try {
				$attempt();
				$this->fail('Expected DoesNotExistException');
			} catch (DoesNotExistException $e) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertSame('Car repair', $this->rows[$row->getId()]->getDescription());
	}

	public function testDeleteRemovesOwnOneOff(): void {
		$row = $this->service->create('alice', 'Car repair', '-864.27', '2026-12-15');

		$this->service->delete('alice', $row->getId());

		$this->assertArrayNotHasKey($row->getId(), $this->rows);
	}

	/**
	 * The shared validator only takes plain numbers, so a decimal comma is
	 * refused; what must never happen is "864,27" being stored as 86427.
	 */
	public function testDecimalCommaAmountParsesOrIsRefused(): void {
		try {
			$row = $this->service->create('alice', 'Comma', '864,27', '2026-12-15');
		} catch (\InvalidArgumentException $e) {
			$this->addToAssertionCount(1);
			return;
		}
		$this->assertSame('864.27', $row->getAmount());
	}

	/**
	 * Registering the table is what makes restore, factory reset and user
	 * deletion wipe it: they all clear the registry through UserTableCleaner.
	 */
	public function testOneOffsAreInTheBackupRegistryAndWipedPerUser(): void {
		$spec = MigrationService::EXTRA_TABLES_POST['runway_oneoffs'] ?? null;

		$this->assertNotNull($spec);
		$this->assertSame('budget_runway_oneoffs', $spec['table']);
		$this->assertSame('user', $spec['scope']);
		$this->assertArrayHasKey('runway_oneoffs', UserTableCleaner::clearOrder());
	}
}

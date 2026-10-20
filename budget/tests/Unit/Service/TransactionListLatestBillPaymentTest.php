<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\UndoSnapshotSkips;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

/**
 * The transactions list says which rows Unlink from bill refuses (#399).
 *
 * The row menu offered Unlink from bill on a bill's latest payment, asked
 * the user to confirm, and only then did the server refuse it: Mark Unpaid
 * runs from that payment's snapshot. Each row a bill still holds now says
 * whether it is that payment (billLatestPayment), by the same rule the
 * server refuses it by, so the menu can say why instead of asking.
 */
class TransactionListLatestBillPaymentTest extends TestCase {
	/** @var Bill[] */
	private array $bills = [];
	/** @var list<array<string, mixed>> */
	private array $rows = [];
	private int $billQueries = 0;

	private function service(bool $withBills = true): TransactionService {
		$mapper = $this->createMock(TransactionMapper::class);
		$mapper->method('findWithFilters')->willReturnCallback(fn () => ['transactions' => $this->rows, 'total' => count($this->rows)]);
		$billMapper = $this->createMock(BillMapper::class);
		$billMapper->method('findByIds')->willReturnCallback(function (array $ids) {
			$this->billQueries++;
			return array_values(array_filter($this->bills, fn (Bill $bill) => in_array($bill->getId(), array_map('intval', $ids), true)));
		});

		return new TransactionService(
			$mapper,
			$this->createMock(AccountMapper::class),
			$this->createMock(TransactionTagMapper::class),
			$this->createMock(\OCA\Budget\Db\TransactionSplitMapper::class),
			$this->createMock(\OCA\Budget\Db\ExpenseShareMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(\OCA\Budget\Service\AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new UserClock($this->createMock(\OCP\IConfig::class)),
			billMapper: $withBills ? $billMapper : null,
		);
	}

	private function bill(int $id, ?array $snapshot): void {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setPaidUndoState($snapshot === null ? null : json_encode($snapshot));
		$this->bills[] = $bill;
	}

	/** @param array<string, mixed> $fields */
	private function row(int $id, ?int $billId, array $fields = []): void {
		$this->rows[] = $fields + ['id' => $id, 'billId' => $billId, 'billDeleted' => false, 'linkedTransactionId' => null, 'status' => 'cleared'];
	}

	/** @return array<int, mixed> id => the row's flag, or 'absent' */
	private function flags(TransactionService $service): array {
		$flags = [];
		foreach ($service->findWithFilters('terje', [], 50, 0)['transactions'] as $row) {
			$flags[$row['id']] = array_key_exists('billLatestPayment', $row) ? $row['billLatestPayment'] : 'absent';
		}
		return $flags;
	}

	public function testTheRowsOfTheLatestPaymentAreFlagged(): void {
		// SBB GA Leon: Mark Paid booked 1786794, linked 1786790, used 1786791
		$this->bill(17046, ['previousState' => [], 'createdTransactionIds' => [1786794], 'linkedTransactionId' => 1786790, 'usedTransactionId' => 1786791]);
		$this->row(1786794, 17046);
		$this->row(1786790, 17046);
		$this->row(1786791, 17046);
		$this->row(1786000, 17046);

		$this->assertSame([1786794 => true, 1786790 => true, 1786791 => true, 1786000 => false], $this->flags($this->service()));
	}

	public function testATransferRowIsFlaggedThroughItsOtherSide(): void {
		$this->bill(47, ['previousState' => [], 'createdTransactionIds' => [500]]);
		$this->row(501, 47, ['linkedTransactionId' => 500]);

		$this->assertSame([501 => true], $this->flags($this->service()));
	}

	public function testRowsWithoutALiveBillAreLeftAsTheyWere(): void {
		$this->bill(47, ['previousState' => [], 'createdTransactionIds' => [500]]);
		$this->row(10, null);
		$this->row(11, 99, ['billDeleted' => true]);
		$this->row(12, 47, ['status' => 'scheduled']);

		$this->assertSame([10 => 'absent', 11 => 'absent', 12 => false], $this->flags($this->service()));
	}

	public function testABillWithNoSnapshotFlagsNothing(): void {
		$this->bill(47, null);
		$this->row(500, 47);

		$this->assertSame([500 => false], $this->flags($this->service()));
	}

	public function testASnapshotASkipSetAsideFlagsNothing(): void {
		// Unlink is allowed then: Mark Unpaid can't run from it
		$snapshot = json_decode((string)UndoSnapshotSkips::skip(json_encode(['previousState' => [], 'createdTransactionIds' => [500]]), '2026-10-24'), true);
		$this->bill(47, $snapshot);
		$this->row(500, 47);

		$this->assertSame([500 => false], $this->flags($this->service()));
	}

	public function testOneQueryForThePageAndNoneWithoutBillRows(): void {
		$this->bill(47, ['previousState' => [], 'createdTransactionIds' => [500]]);
		$this->bill(48, ['previousState' => [], 'createdTransactionIds' => [600]]);
		$this->row(500, 47);
		$this->row(600, 48);
		$service = $this->service();
		$this->flags($service);
		$this->assertSame(1, $this->billQueries);

		$this->rows = [];
		$this->billQueries = 0;
		$this->row(10, null);
		$this->flags($service);
		$this->assertSame(0, $this->billQueries);
	}

	public function testAServiceBuiltWithoutBillsLeavesRowsAlone(): void {
		$this->row(500, 47);

		$this->assertSame([500 => 'absent'], $this->flags($this->service(false)));
	}
}

<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mark Unpaid and Mark Unreceived while a skip stands (#399, browser
 * round 3).
 *
 * A skip after a payment sets the payment's undo snapshot aside until the
 * skip is undone. The page hides Mark Unpaid meanwhile, but the API still
 * answered POST /bills/{id}/unpaid with 'This bill has no recorded payment
 * to undo' (and /recurring-income/{id}/unreceived with 'no recorded
 * receipt'), though the payment is there. The refusal now says what is in
 * the way. Nothing changes either way.
 */
class UndoPaymentAfterSkipMessageTest extends TestCase {
	private const BILL_SKIPPED = 'Undo the skip first: a later date has been skipped since this payment.';
	private const INCOME_SKIPPED = 'Undo the skip first: a later date has been skipped since this receipt.';

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return $l;
	}

	private function clock(): UserClock {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-10-20');
		return $clock;
	}

	/** Paid on 29 October, then 29 November skipped */
	private const BILL_SNAPSHOT = [
		'previousState' => ['nextDueDate' => '2026-10-29', 'lastPaidDate' => '2026-09-27', 'isActive' => true],
		'createdTransactionIds' => [900],
		'scheduledTransactionIds' => [],
		'hadScheduledTransaction' => false,
	];

	private function bills(?array $snapshot, ?Bill &$bill = null): BillService {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('KPT Prämienrechnung');
		$bill->setAmount(484.75);
		$bill->setFrequency('monthly');
		$bill->setDueDay(29);
		$bill->setIsActive(true);
		$bill->setAccountId(3);
		$bill->setNextDueDate('2026-12-29');
		$bill->setLastPaidDate('2026-10-29');
		$bill->setPaidUndoState($snapshot === null ? null : (string)json_encode($snapshot));
		$bill->resetUpdatedFields();
		$stored = $bill;

		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $stored);
		$mapper->expects($this->never())->method('update');

		$transactions = $this->createMock(TransactionService::class);
		$transactions->expects($this->never())->method('deleteAsAccountOwner');

		return new BillService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringBillDetector::class),
			$transactions,
			$this->l10n(),
			$this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
			null,
			$this->clock(),
		);
	}

	private function incomes(?array $snapshot): RecurringIncomeService {
		$income = new RecurringIncome();
		$income->setId(1);
		$income->setUserId('user1');
		$income->setName('Gutschrift SBB Abo Leon');
		$income->setAmount(100.0);
		$income->setFrequency('monthly');
		$income->setExpectedDay(29);
		$income->setIsActive(true);
		$income->setAutoCreateEnabled(false);
		$income->setAccountId(7);
		$income->setLastReceivedDate('2026-09-29');
		$income->setNextExpectedDate('2026-11-29');
		$income->setReceivedUndoState($snapshot === null ? null : (string)json_encode($snapshot));
		$income->resetUpdatedFields();

		$mapper = $this->createMock(RecurringIncomeMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $income);
		$mapper->expects($this->never())->method('update');

		$transactions = $this->createMock(TransactionService::class);
		$transactions->expects($this->never())->method('deleteAsAccountOwner');

		return new RecurringIncomeService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringIncomeDetector::class),
			$transactions,
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			null,
			$this->clock(),
		);
	}

	private function refusal(callable $call): string {
		try {
			$call();
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}
		$this->fail('the undo went through');
	}

	public function testMarkUnpaidWhileASkipStandsSaysToUndoTheSkip(): void {
		$service = $this->bills(self::BILL_SNAPSHOT + ['skippedDates' => ['2026-11-29']]);

		$this->assertSame(self::BILL_SKIPPED, $this->refusal(fn () => $service->markUnpaid(1, 'user1')));
	}

	public function testMarkUnpaidWithNothingRecordedStillSaysSo(): void {
		$service = $this->bills(null);

		$this->assertSame('This bill has no recorded payment to undo', $this->refusal(fn () => $service->markUnpaid(1, 'user1')));
	}

	public function testASkipWithNoPaymentBeforeItIsNotCalledAPayment(): void {
		// A skip with nothing to set aside leaves a snapshot holding only its dates
		$service = $this->bills(['skippedDates' => ['2026-11-29']]);

		$this->assertSame('This bill has no recorded payment to undo', $this->refusal(fn () => $service->markUnpaid(1, 'user1')));
	}

	public function testMarkUnreceivedWhileASkipStandsSaysToUndoTheSkip(): void {
		$service = $this->incomes([
			'nextExpectedDate' => '2026-09-29',
			'lastReceivedDate' => '2026-08-29',
			'transactionIds' => [700],
			'skippedDates' => ['2026-10-29'],
		]);

		$this->assertSame(self::INCOME_SKIPPED, $this->refusal(fn () => $service->markUnreceived(1, 'user1')));
	}

	public function testMarkUnreceivedWithNothingRecordedStillSaysSo(): void {
		$this->assertSame('This income has no recorded receipt to undo',
			$this->refusal(fn () => $this->incomes(null)->markUnreceived(1, 'user1')));
		$this->assertSame('This income has no recorded receipt to undo',
			$this->refusal(fn () => $this->incomes(['skippedDates' => ['2026-10-29']])->markUnreceived(1, 'user1')));
	}
}

<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionContributionMapper;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\PensionSnapshot;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\TransactionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class PensionServiceTest extends TestCase {
	private PensionService $service;
	private PensionAccountMapper $pensionMapper;
	private PensionSnapshotMapper $snapshotMapper;
	private PensionContributionMapper $contributionMapper;
	private CurrencyConversionService $conversionService;
	/** @var TransactionService&\PHPUnit\Framework\MockObject\MockObject */
	private $transactionService;
	/** @var AccountMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $accountMapper;
	/** @var PensionRecurringContributionMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $recurringMapper;
	/** @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject */
	private $db;
	/** @var \OCA\Budget\Service\GranularShareService&\PHPUnit\Framework\MockObject\MockObject */
	private $shares;
	/** @var \OCA\Budget\Db\TransactionMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $transactionMapper;
	/** @var \OCA\Budget\Db\PensionLegQueries&\PHPUnit\Framework\MockObject\MockObject */
	private $legQueries;

	protected function setUp(): void {
		$this->pensionMapper = $this->createMock(PensionAccountMapper::class);
		$this->snapshotMapper = $this->createMock(PensionSnapshotMapper::class);
		$this->contributionMapper = $this->createMock(PensionContributionMapper::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);
		$this->transactionService = $this->createMock(TransactionService::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->recurringMapper = $this->createMock(PensionRecurringContributionMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->shares = $this->createMock(\OCA\Budget\Service\GranularShareService::class);
		$this->transactionMapper = $this->createMock(\OCA\Budget\Db\TransactionMapper::class);
		$this->legQueries = $this->createMock(\OCA\Budget\Db\PensionLegQueries::class);

		$this->service = new PensionService(
			$this->pensionMapper,
			$this->snapshotMapper,
			$this->contributionMapper,
			$this->conversionService,
			$this->transactionService,
			$this->accountMapper,
			$this->recurringMapper,
			$this->db,
			$this->l10n(),
			$this->shares,
			$this->transactionMapper,
			$this->legQueries
		);
	}

	private function l10n(): \OCP\IL10N {
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));
		return $l;
	}

	private function makePension(array $overrides = []): PensionAccount {
		$pension = new PensionAccount();
		$defaults = [
			'id' => 1,
			'userId' => 'user1',
			'name' => 'Work Pension',
			'type' => 'workplace',
			'currency' => 'GBP',
			'currentBalance' => 50000.0,
			'monthlyContribution' => 500.0,
			'expectedReturnRate' => 0.05,
			'retirementAge' => 65,
			'annualIncome' => null,
			'transferValue' => null,
		];
		$data = array_merge($defaults, $overrides);

		$pension->setId($data['id']);
		$pension->setUserId($data['userId']);
		$pension->setName($data['name']);
		$pension->setType($data['type']);
		$pension->setCurrency($data['currency']);
		$pension->setCurrentBalance($data['currentBalance']);
		$pension->setMonthlyContribution($data['monthlyContribution']);
		$pension->setExpectedReturnRate($data['expectedReturnRate']);
		$pension->setRetirementAge($data['retirementAge']);
		$pension->setAnnualIncome($data['annualIncome']);
		$pension->setTransferValue($data['transferValue']);

		return $pension;
	}

	// ===== findAll / find =====

	public function testFindAllDelegatesToMapper(): void {
		$pensions = [$this->makePension()];
		$this->pensionMapper->expects($this->once())->method('findAll')
			->with('user1')->willReturn($pensions);

		$result = $this->service->findAll('user1');
		$this->assertSame($pensions, $result);
	}

	public function testFindDelegatesToMapper(): void {
		$pension = $this->makePension();
		$this->pensionMapper->expects($this->once())->method('find')
			->with(1, 'user1')->willReturn($pension);

		$result = $this->service->find(1, 'user1');
		$this->assertSame($pension, $result);
	}

	// ===== create =====

	public function testCreateInsertsPensionAccount(): void {
		$this->pensionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PensionAccount $p) {
				$this->assertEquals('user1', $p->getUserId());
				$this->assertEquals('My Pension', $p->getName());
				$this->assertEquals('personal', $p->getType());
				$this->assertEquals('USD', $p->getCurrency());
				$p->setId(10);
				return $p;
			});

		// personal is DC type, so snapshot should be created
		$this->pensionMapper->expects($this->once())->method('find')
			->willReturnCallback(function () {
				return $this->makePension(['id' => 10, 'type' => 'personal', 'currentBalance' => 10000.0]);
			});
		$this->snapshotMapper->expects($this->once())->method('insert')
			->willReturnCallback(fn ($s) => $s);
		$this->pensionMapper->expects($this->once())->method('update')
			->willReturnCallback(fn ($p) => $p);

		$result = $this->service->create('user1', 'My Pension', 'personal', null, 'USD', 10000.0);
		$this->assertEquals('My Pension', $result->getName());
	}

	public function testCreateWithDefaultCurrency(): void {
		$this->pensionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PensionAccount $p) {
				$this->assertEquals('GBP', $p->getCurrency());
				$p->setId(1);
				// state pension, no snapshot
				$p->setType('state');
				return $p;
			});

		$this->service->create('user1', 'State Pension', 'state');
	}

	// ===== update =====

	public function testUpdateAppliesOnlyNonNullFields(): void {
		$pension = $this->makePension();
		$this->pensionMapper->method('find')->willReturn($pension);
		$this->pensionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (PensionAccount $p) {
				$this->assertEquals('Updated Name', $p->getName());
				$this->assertEquals('workplace', $p->getType()); // unchanged
				return $p;
			});

		$this->service->update(1, 'user1', 'Updated Name');
	}

	/**
	 * A defined benefit or state pension has no pot to pay into, and its page
	 * hides scheduled contributions, so a schedule left on it went on moving
	 * money from the bank every month where the user could no longer see it.
	 */
	public function testChangingToAPensionWithNoPotIsRefusedWhileItHasSchedules(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['type' => 'workplace']));
		$this->recurringMapper->method('findByPension')->willReturn([new \OCA\Budget\Db\PensionRecurringContribution()]);
		$this->pensionMapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(1, 'user1', null, 'defined_benefit');
	}

	public function testChangingToAPensionWithNoPotIsAllowedWithoutSchedules(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['type' => 'workplace']));
		$this->recurringMapper->method('findByPension')->willReturn([]);
		$this->pensionMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$pension = $this->service->update(1, 'user1', null, 'state');

		$this->assertSame('state', $pension->getType());
	}

	// ===== the pension's balance between balance updates =====

	private function latestSnapshot(string $date, float $balance): void {
		$this->snapshotMapper->method('findLatest')->willReturn($this->makeSnapshot($date, $balance));
	}

	/** Every pensionMapper->update() call's balance, in order */
	private function balancesWritten(): \ArrayObject {
		$written = new \ArrayObject();
		$this->pensionMapper->method('update')->willReturnCallback(function (PensionAccount $p) use ($written) {
			$written[] = $p->getCurrentBalance();
			return $p;
		});
		return $written;
	}

	/**
	 * A contribution paid from the bank takes the money out of the account
	 * and puts it into the pension, so net worth stays where it was. The
	 * pension's balance used to wait for the next balance update, so net
	 * worth fell by every contribution until then.
	 */
	public function testABankFundedContributionRaisesThePensionBalance(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 5000.0]));
		$this->latestSnapshot('2026-09-30', 5000.0);
		$account = new \OCA\Budget\Db\Account();
		$account->setUserId('user1');
		$account->setCurrency('GBP');
		$this->accountMapper->method('findById')->willReturn($account);
		$this->conversionService->method('convertLocal')->willReturnCallback(fn ($amt) => (string)$amt);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(555);
		$this->transactionService->method('create')->willReturn($tx);
		$this->contributionMapper->method('insert')->willReturnArgument(0);
		$written = $this->balancesWritten();

		$this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 10);

		$this->assertSame([5200.0], $written->getArrayCopy());
	}

	public function testAWithdrawalLowersThePensionBalance(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 5000.0]));
		$this->latestSnapshot('2026-09-30', 5000.0);
		$this->contributionMapper->method('insert')->willReturnArgument(0);
		$written = $this->balancesWritten();

		$this->service->createWithdrawal(1, 'user1', 1000.0, '2026-10-01');

		$this->assertSame([4000.0], $written->getArrayCopy());
	}

	/**
	 * A balance update dated on or after a contribution already counts it,
	 * so a contribution entered late is not counted twice.
	 */
	public function testAContributionTheLatestBalanceUpdateCountsLeavesTheBalance(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 5000.0]));
		$this->latestSnapshot('2026-09-30', 5000.0);
		$this->contributionMapper->method('insert')->willReturnArgument(0);
		$this->pensionMapper->expects($this->never())->method('update');

		$this->service->createContribution(1, 'user1', 200.0, '2026-09-30');
	}

	public function testAPensionWithNoPotKeepsItsFigures(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['type' => 'defined_benefit', 'currentBalance' => null]));
		$this->contributionMapper->method('insert')->willReturnArgument(0);
		$this->pensionMapper->expects($this->never())->method('update');

		$this->service->createContribution(1, 'user1', 200.0, '2026-10-01');
	}

	public function testDeletingAContributionTakesItBackOffTheBalance(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 5200.0]));
		$this->latestSnapshot('2026-09-30', 5000.0);
		$contribution = $this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, null, null);
		$contribution->setId(77);
		$contribution->setPensionId(1);
		$this->contributionMapper->method('find')->willReturn($contribution);
		$written = $this->balancesWritten();

		$this->service->deleteContribution(77, 'user1');

		$this->assertSame([5000.0], $written->getArrayCopy());
	}

	/**
	 * A balance update is the pension's value on its date; what was paid in
	 * or taken out after that date is added to it.
	 */
	public function testABalanceUpdateCountsWhatMovedAfterItsDate(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 4000.0]));
		$this->latestSnapshot('2026-06-30', 4000.0);
		$this->snapshotMapper->method('insert')->willReturnArgument(0);
		$this->contributionMapper->method('findByPension')->willReturn([
			$this->makeContribution('2026-09-15', 300.0, PensionContribution::KIND_CONTRIBUTION, null, null),
			$this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, null, null),
			$this->makeContribution('2026-10-02', 50.0, PensionContribution::KIND_WITHDRAWAL, null, null),
		]);
		$written = $this->balancesWritten();

		$this->service->createSnapshot(1, 'user1', 5000.0, '2026-09-30');

		$this->assertSame([5150.0], $written->getArrayCopy());
	}

	public function testAnOlderBalanceUpdateLeavesTheCurrentBalance(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 5200.0]));
		$this->latestSnapshot('2026-09-30', 5000.0);
		$this->snapshotMapper->method('insert')->willReturnArgument(0);
		$this->pensionMapper->expects($this->never())->method('update');

		$this->service->createSnapshot(1, 'user1', 3000.0, '2026-01-31');
	}

	public function testDeletingTheLatestBalanceUpdateFallsBackToTheOneBefore(): void {
		$deleted = $this->makeSnapshot('2026-09-30', 5000.0);
		$deleted->setPensionId(1);
		$this->snapshotMapper->method('find')->willReturn($deleted);
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currentBalance' => 5200.0]));
		$this->latestSnapshot('2026-06-30', 4000.0);
		$this->contributionMapper->method('findByPension')->willReturn([
			$this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, null, null),
		]);
		$written = $this->balancesWritten();

		$this->service->deleteSnapshot(9, 'user1');

		$this->assertSame([4200.0], $written->getArrayCopy());
	}

	// ===== the bank's own record of a pension payment =====

	private function ownAccount(): void {
		$account = new \OCA\Budget\Db\Account();
		$account->setId(10);
		$account->setUserId('user1');
		$account->setCurrency('GBP');
		$this->accountMapper->method('findById')->willReturn($account);
		$this->conversionService->method('convertLocal')->willReturnCallback(fn ($amt) => (string)$amt);
		$this->contributionMapper->method('insert')->willReturnCallback(function (PensionContribution $c) {
			$c->setId(78);
			return $c;
		});
	}

	private function candidate(int $id, string $date, ?string $description = 'DIRECT DEBIT'): array {
		return ['id' => $id, 'date' => $date, 'description' => $description, 'vendor' => null];
	}

	/**
	 * The statement came first: the contribution takes the imported row as
	 * its bank leg instead of booking the same money a second time.
	 */
	public function testAContributionTakesTheBanksOwnRecordOfIt(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->ownAccount();
		$this->legQueries->method('findImportedCandidates')
			->with(10, 'debit', 200.0, '2026-09-26', '2026-10-06')
			->willReturn([$this->candidate(700, '2026-10-02')]);
		$this->transactionService->expects($this->never())->method('create');
		$this->transactionService->expects($this->once())->method('markPensionContribLink')->with(700, 'user1', 78);

		$contribution = $this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 10);

		$this->assertSame(700, $contribution->getTransactionId());
	}

	public function testTheCandidateNamingThePensionWins(): void {
		$pension = $this->makePension();
		$pension->setProvider('Nest');
		$this->pensionMapper->method('find')->willReturn($pension);
		$this->ownAccount();
		$this->legQueries->method('findImportedCandidates')->willReturn([
			$this->candidate(700, '2026-10-01', 'RENT'),
			$this->candidate(701, '2026-10-03', 'NEST PENSIONS DD'),
		]);
		$this->transactionService->expects($this->never())->method('create');
		$this->transactionService->expects($this->once())->method('markPensionContribLink')->with(701, 'user1', 78);

		$this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 10);
	}

	public function testCandidatesThatCannotBeToldApartAreLeftAlone(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->ownAccount();
		$this->legQueries->method('findImportedCandidates')->willReturn([
			$this->candidate(700, '2026-09-30'),
			$this->candidate(701, '2026-10-02'),
		]);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(555);
		$this->transactionService->expects($this->once())->method('create')->willReturn($tx);

		$contribution = $this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 10);

		$this->assertSame(555, $contribution->getTransactionId());
	}

	private function importedRow(int $id, string $date, float $amount = 200.0, string $type = 'debit'): \OCA\Budget\Db\Transaction {
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId($id);
		$tx->setAccountId(10);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType($type);
		$tx->setStatus('cleared');
		$tx->setImportId('csv-' . $id);
		$tx->setDescription('NEST PENSIONS');
		return $tx;
	}

	private function appLeg(int $id, string $date, int $contribId, bool $reconciled = false): array {
		return ['id' => $id, 'date' => $date, 'type' => 'debit', 'amount' => 200.0, 'pensionContribId' => $contribId, 'reconciled' => $reconciled];
	}

	/**
	 * The contribution came first: the statement's row of the same payment
	 * replaces the leg the app booked, so the money is counted once and a
	 * re-import of the statement recognises it.
	 */
	public function testAnImportedRowReplacesTheLegTheAppBooked(): void {
		$this->ownAccount();
		$imported = $this->importedRow(900, '2026-10-02');
		$appLeg = $this->leg(555, 10);
		$this->transactionMapper->method('findById')->willReturnCallback(fn (int $id) => [900 => $imported, 555 => $appLeg][$id] ?? null);
		$this->legQueries->method('findAppCreatedLegs')
			->with(10, '2026-09-27', '2026-10-07')
			->willReturn([$this->appLeg(555, '2026-10-01', 77)]);
		$contribution = $this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, 555, 10);
		$contribution->setId(77);
		$contribution->setUserId('user1');
		$contribution->setPensionId(1);
		$this->contributionMapper->method('findById')->with(77)->willReturn($contribution);
		$this->pensionMapper->method('find')->willReturn($this->makePension());

		// What the user added to the app's leg goes to the bank's row
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($appLeg, $imported);
		$this->contributionMapper->expects($this->once())->method('update')
			->with($this->callback(fn (PensionContribution $c) => $c->getTransactionId() === 900));
		$this->transactionService->expects($this->once())->method('markPensionContribLink')->with(900, 'user1', 77);

		$this->assertSame(1, $this->service->adoptImportedDuplicates('user1', [$imported]));
	}

	private function adoptingSetup(\OCA\Budget\Db\Transaction $fresh, string $provider = ''): void {
		$this->ownAccount();
		$this->transactionMapper->method('findById')->willReturn($fresh);
		$this->legQueries->method('findAppCreatedLegs')->willReturn([$this->appLeg(555, '2026-10-01', 77)]);
		$contribution = $this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, 555, 10);
		$contribution->setId(77);
		$contribution->setUserId('user1');
		$contribution->setPensionId(1);
		$this->contributionMapper->method('findById')->willReturn($contribution);
		$pension = $this->makePension();
		$pension->setProvider($provider);
		$this->pensionMapper->method('find')->willReturn($pension);
	}

	/**
	 * Years of another app's history can hold an unrelated payment of the
	 * same amount near a pension date. One already filed as something else
	 * is not taken for the pension's leg.
	 */
	public function testAnImportedRowAlreadyCategorisedIsLeftAloneUnlessItNamesThePension(): void {
		$imported = $this->importedRow(900, '2026-10-02');
		$imported->setDescription('CAR INSURANCE');
		$imported->setCategoryId(4);
		$this->adoptingSetup($imported);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->assertSame(0, $this->service->adoptImportedDuplicates('user1', [$imported]));
	}

	public function testAnUnnamedRowFurtherAwayThanABanksDelayIsLeftAlone(): void {
		$imported = $this->importedRow(900, '2026-10-05');
		$imported->setDescription('DIRECT DEBIT');
		$this->adoptingSetup($imported);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->assertSame(0, $this->service->adoptImportedDuplicates('user1', [$imported]));
	}

	public function testARowNamingTheProviderIsTakenEvenWhenCategorisedAndLate(): void {
		$imported = $this->importedRow(900, '2026-10-05');
		$imported->setDescription('NEST PENSIONS DD');
		$imported->setCategoryId(4);
		$this->adoptingSetup($imported, 'Nest');
		$this->transactionService->expects($this->once())->method('replaceBookedRow');

		$this->assertSame(1, $this->service->adoptImportedDuplicates('user1', [$imported]));
	}

	public function testAReconciledLegIsLeftAlone(): void {
		$this->ownAccount();
		$imported = $this->importedRow(900, '2026-10-02');
		$this->transactionMapper->method('findById')->willReturn($imported);
		$this->legQueries->method('findAppCreatedLegs')->willReturn([$this->appLeg(555, '2026-10-01', 77, true)]);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->assertSame(0, $this->service->adoptImportedDuplicates('user1', [$imported]));
	}

	public function testAnImportedRowABillAlreadyClaimedIsLeftAlone(): void {
		$this->ownAccount();
		$imported = $this->importedRow(900, '2026-10-02');
		$claimed = $this->importedRow(900, '2026-10-02');
		$claimed->setBillId(12);
		$this->transactionMapper->method('findById')->willReturn($claimed);
		$this->legQueries->method('findAppCreatedLegs')->willReturn([$this->appLeg(555, '2026-10-01', 77)]);
		$contribution = $this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, 555, 10);
		$contribution->setId(77);
		$contribution->setUserId('user1');
		$contribution->setPensionId(1);
		$this->contributionMapper->method('findById')->willReturn($contribution);
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->assertSame(0, $this->service->adoptImportedDuplicates('user1', [$imported]));
	}

	public function testRowsTypedInByHandAreNotTakenForTheBanks(): void {
		$manual = $this->importedRow(900, '2026-10-02');
		$manual->setImportId(null);
		$this->legQueries->expects($this->never())->method('findAppCreatedLegs');

		$this->assertSame(0, $this->service->adoptImportedDuplicates('user1', [$manual]));
	}

	// ===== delete =====

	public function testDeleteRemovesRelatedData(): void {
		$pension = $this->makePension();
		$this->pensionMapper->method('find')->willReturn($pension);

		$this->snapshotMapper->expects($this->once())->method('deleteByPension')->with(1, 'user1');
		$this->contributionMapper->expects($this->once())->method('deleteByPension')->with(1, 'user1');
		$this->pensionMapper->expects($this->once())->method('delete')->with($pension);

		$this->service->delete(1, 'user1');
	}

	// ===== snapshots =====

	public function testGetSnapshotsVerifiesPensionOwnership(): void {
		$pension = $this->makePension();
		$this->pensionMapper->expects($this->once())->method('find')->with(1, 'user1')
			->willReturn($pension);
		$this->snapshotMapper->expects($this->once())->method('findByPension')
			->with(1, 'user1')->willReturn([]);

		$this->service->getSnapshots(1, 'user1');
	}

	public function testCreateSnapshotUpdatesBalance(): void {
		$pension = $this->makePension(['currentBalance' => 40000.0]);
		$this->pensionMapper->method('find')->willReturn($pension);

		$this->snapshotMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PensionSnapshot $s) {
				$this->assertEquals(55000.0, $s->getBalance());
				$this->assertEquals('2026-03-01', $s->getDate());
				return $s;
			});

		$this->pensionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (PensionAccount $p) {
				$this->assertEquals(55000.0, $p->getCurrentBalance());
				return $p;
			});

		$this->service->createSnapshot(1, 'user1', 55000.0, '2026-03-01');
	}

	// ===== contributions =====

	public function testCreateContributionVerifiesPension(): void {
		$pension = $this->makePension();
		$this->pensionMapper->expects($this->once())->method('find')->with(1, 'user1')
			->willReturn($pension);

		$this->contributionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PensionContribution $c) {
				$this->assertEquals(500.0, $c->getAmount());
				$this->assertEquals('2026-03-01', $c->getDate());
				return $c;
			});

		$this->service->createContribution(1, 'user1', 500.0, '2026-03-01');
	}

	public function testGetTotalContributions(): void {
		$pension = $this->makePension();
		$this->pensionMapper->method('find')->willReturn($pension);
		$this->contributionMapper->expects($this->once())->method('getTotalByPension')
			->with(1, 'user1')->willReturn(6000.0);

		$result = $this->service->getTotalContributions(1, 'user1');
		$this->assertEquals(6000.0, $result);
	}

	// ===== getSummary =====

	public function testGetSummaryCategorizesAllPensionTypes(): void {
		$dc = $this->makePension(['id' => 1, 'type' => 'workplace', 'currentBalance' => 50000.0, 'currency' => 'GBP']);
		$db = $this->makePension(['id' => 2, 'type' => 'defined_benefit', 'transferValue' => 100000.0, 'annualIncome' => 10000.0, 'currency' => 'GBP']);
		$state = $this->makePension(['id' => 3, 'type' => 'state', 'annualIncome' => 11500.0, 'currency' => 'GBP']);

		$this->pensionMapper->method('findAll')->willReturn([$dc, $db, $state]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');

		$result = $this->service->getSummary('user1');

		$this->assertEquals(50000.0, $result['totalDCBalance']);
		$this->assertEquals(100000.0, $result['totalDBTransferValue']);
		$this->assertEquals(10000.0, $result['totalDBIncome']);
		$this->assertEquals(11500.0, $result['stateIncome']);
		$this->assertEquals(150000.0, $result['totalPensionWorth']); // DC + DB transfer
		$this->assertEquals(21500.0, $result['totalProjectedIncome']); // DB + state
		$this->assertEquals(3, $result['pensionCount']);
		$this->assertEquals(1, $result['dcCount']);
		$this->assertEquals(1, $result['dbCount']);
		$this->assertEquals(1, $result['stateCount']);
	}

	public function testGetSummaryConvertsCurrencies(): void {
		$dc = $this->makePension(['id' => 1, 'type' => 'workplace', 'currentBalance' => 50000.0, 'currency' => 'USD']);

		$this->pensionMapper->method('findAll')->willReturn([$dc]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('convertToBaseFloat')->willReturn(40000.0);

		$result = $this->service->getSummary('user1');

		$this->assertEquals(40000.0, $result['totalDCBalance']);
	}

	public function testGetSummaryWithNoPensions(): void {
		$this->pensionMapper->method('findAll')->willReturn([]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');

		$result = $this->service->getSummary('user1');

		$this->assertEquals(0.0, $result['totalPensionWorth']);
		$this->assertEquals(0, $result['pensionCount']);
	}

	// ===== Charts & Activity (#251 panel fix) =====

	public function testGetBalanceHistorySynthesizesPointWhenNoSnapshots(): void {
		$pension = $this->makePension(['currentBalance' => 12345.0, 'currency' => 'GBP']);
		$this->pensionMapper->method('find')->willReturn($pension);
		$this->snapshotMapper->method('findByPension')->willReturn([]);

		$result = $this->service->getBalanceHistory(1, 'user1');

		$this->assertCount(1, $result['values']);
		$this->assertSame(12345.0, $result['values'][0]);
		$this->assertSame('GBP', $result['currency']);
	}

	public function testGetBalanceHistoryReturnsAscendingSeries(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['currency' => 'GBP']));
		// findByPension returns DESC; the service reverses to chronological order
		$this->snapshotMapper->method('findByPension')->willReturn([
			$this->makeSnapshot('2026-03-01', 2000.0),
			$this->makeSnapshot('2026-02-01', 1000.0),
		]);

		$result = $this->service->getBalanceHistory(1, 'user1');

		$this->assertSame(['2026-02-01', '2026-03-01'], $result['labels']);
		$this->assertSame([1000.0, 2000.0], $result['values']);
	}

	public function testGetActivityMergesAndSortsDesc(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->contributionMapper->method('findByPension')->willReturn([
			$this->makeContribution('2026-03-10', 500.0, PensionContribution::KIND_CONTRIBUTION, 99, 7), // linked -> transfer_in
			$this->makeContribution('2026-03-05', 300.0, PensionContribution::KIND_WITHDRAWAL, null, null),
		]);
		$this->snapshotMapper->method('findByPension')->willReturn([
			$this->makeSnapshot('2026-03-08', 9000.0),
		]);

		$result = $this->service->getActivity(1, 'user1');

		$this->assertCount(3, $result);
		$this->assertSame('2026-03-10', $result[0]['date']);
		$this->assertSame('transfer_in', $result[0]['type']);
		$this->assertSame('2026-03-08', $result[1]['date']);
		$this->assertSame('snapshot', $result[1]['type']);
		$this->assertSame('2026-03-05', $result[2]['date']);
		$this->assertSame('withdrawal', $result[2]['type']);
	}

	/**
	 * Deleting an entry deletes its bank leg, so the list says when that leg
	 * was reconciled and the confirm can warn that past reconciliations will
	 * stop matching, as the Transactions page does.
	 */
	public function testActivitySaysWhenABankLegIsReconciled(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->contributionMapper->method('findByPension')->willReturn([
			$this->makeContribution('2026-03-10', 500.0, PensionContribution::KIND_CONTRIBUTION, 99, 7),
			$this->makeContribution('2026-02-10', 500.0, PensionContribution::KIND_CONTRIBUTION, 98, 7),
			$this->makeContribution('2026-01-10', 500.0, PensionContribution::KIND_CONTRIBUTION, null, null),
		]);
		$this->snapshotMapper->method('findByPension')->willReturn([]);
		$reconciled = $this->leg(99, 7);
		$reconciled->setReconciled(true);
		$open = $this->leg(98, 7);
		$open->setReconciled(false);
		$this->transactionMapper->method('findById')->willReturnMap([[99, $reconciled], [98, $open]]);

		$result = $this->service->getActivity(1, 'user1');

		$this->assertTrue($result[0]['reconciled']);
		$this->assertFalse($result[1]['reconciled']);
		$this->assertFalse($result[2]['reconciled']);
	}

	// ===== #304 contribution funded by a bank transfer =====

	public function testCreateContributionWithTransferCreatesLinkedBankLeg(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension(['id' => 1, 'name' => 'Work', 'currency' => 'GBP']));

		$account = new \OCA\Budget\Db\Account();
		$account->setId(10);
		$account->setUserId('user1');
		$account->setCurrency('GBP');
		$this->accountMapper->method('findById')->willReturn($account);
		$this->shares->method('canAccess')->willReturn(true);
		$this->shares->method('canWrite')->willReturn(true);
		$this->conversionService->method('convertLocal')->willReturnCallback(fn ($amt) => (string)$amt);

		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(555);
		$this->transactionService->expects($this->once())->method('create')
			->willReturnCallback(function (...$args) use ($tx) {
				$this->assertSame('user1', $args[0]);
				$this->assertSame('debit', $args[5]);   // bank leg is a debit
				$this->assertNull($args[6]);            // no category
				return $tx;
			});

		$this->contributionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PensionContribution $c) {
				$this->assertSame(555, $c->getTransactionId());
				$this->assertSame(10, $c->getSourceAccountId());
				$this->assertSame(PensionContribution::KIND_CONTRIBUTION, $c->getKind());
				$c->setId(77);
				return $c;
			});

		$this->transactionService->expects($this->once())->method('markPensionContribLink')
			->with(555, 'user1', 77);

		$result = $this->service->createContributionWithTransfer(1, 'user1', 500.0, '2026-03-01', 10, 'note');
		$this->assertSame(500.0, $result->getAmount());
	}

	public function testDeleteContributionRemovesLinkedTransaction(): void {
		$contribution = $this->makeContribution('2026-03-01', 500.0, PensionContribution::KIND_CONTRIBUTION, 555, 10);
		$contribution->setId(77);
		$this->contributionMapper->method('find')->willReturn($contribution);
		$this->transactionMapper->method('findById')->with(555)->willReturn($this->leg(555, 10));
		$this->shares->method('canWrite')->willReturn(true);

		$this->transactionService->expects($this->once())->method('deleteAsAccountOwner')->with(555);
		$this->contributionMapper->expects($this->once())->method('delete')->with($contribution);

		$this->service->deleteContribution(77, 'user1');
	}

	private function leg(int $id, int $accountId): \OCA\Budget\Db\Transaction {
		$leg = new \OCA\Budget\Db\Transaction();
		$leg->setId($id);
		$leg->setAccountId($accountId);
		return $leg;
	}

	private function sharedAccount(int $id, string $owner): \OCA\Budget\Db\Account {
		$account = new \OCA\Budget\Db\Account();
		$account->setId($id);
		$account->setUserId($owner);
		$account->setName('Joint');
		$account->setCurrency('GBP');
		return $account;
	}

	/**
	 * A contribution from an account another user shares with this one at
	 * write access is booked in that account as its owner, as bills and
	 * transactions are (#334). It was looked up as the acting user's own
	 * account and never found, so it never posted.
	 */
	public function testAContributionFromASharedAccountIsBookedAsItsOwner(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->accountMapper->method('findById')->with(9)->willReturn($this->sharedAccount(9, 'alice'));
		$this->shares->method('canAccess')->with('user1', 'account', 9)->willReturn(true);
		$this->shares->method('canWrite')->with('user1', 'account', 9)->willReturn(true);
		$this->conversionService->method('convertLocal')->willReturnCallback(fn ($amt) => (string)$amt);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(556);
		$this->transactionService->expects($this->once())->method('create')
			->with('alice', 9)->willReturn($tx);
		$this->contributionMapper->method('insert')->willReturnCallback(function (PensionContribution $c) {
			$c->setId(78);
			return $c;
		});
		$this->transactionService->expects($this->once())->method('markPensionContribLink')->with(556, 'alice', 78);

		$this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 9);
	}

	public function testAReadOnlySharedAccountIsRefused(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->accountMapper->method('findById')->willReturn($this->sharedAccount(9, 'alice'));
		$this->shares->method('canAccess')->willReturn(true);
		$this->shares->method('canWrite')->willReturn(false);
		$this->transactionService->expects($this->never())->method('create');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 9);
	}

	public function testAnAccountThatIsGoneIsRefusedWithAReason(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->accountMapper->method('findById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->transactionService->expects($this->never())->method('create');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 9);
	}

	public function testAnotherUsersAccountThatIsNotSharedIsRefused(): void {
		$this->pensionMapper->method('find')->willReturn($this->makePension());
		$this->accountMapper->method('findById')->willReturn($this->sharedAccount(9, 'alice'));
		$this->shares->method('canAccess')->willReturn(false);
		$this->transactionService->expects($this->never())->method('create');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->createContributionWithTransfer(1, 'user1', 200.0, '2026-10-01', 9);
	}

	/**
	 * A contribution that took the bank's own imported row as its leg leaves
	 * that row in the account when it goes: it is the bank's record of real
	 * money, not something the app booked.
	 */
	public function testDeletingAContributionKeepsAnImportedBankRow(): void {
		$contribution = $this->makeContribution('2026-10-01', 200.0, PensionContribution::KIND_CONTRIBUTION, 700, 10);
		$contribution->setId(77);
		$this->contributionMapper->method('find')->willReturn($contribution);
		$leg = $this->leg(700, 10);
		$leg->setImportId('csv-700');
		$this->transactionMapper->method('findById')->willReturn($leg);
		$this->shares->method('canWrite')->willReturn(true);
		$account = new \OCA\Budget\Db\Account();
		$account->setUserId('user1');
		$this->accountMapper->method('findById')->willReturn($account);

		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');
		$this->transactionService->expects($this->once())->method('markPensionContribLink')->with(700, 'user1', null);

		$this->service->deleteContribution(77, 'user1');
	}

	public function testDeletingAContributionLeavesTheLegInAnAccountNoLongerShared(): void {
		// The other user's ledger isn't this user's to change any more
		$contribution = $this->makeContribution('2026-03-01', 500.0, PensionContribution::KIND_CONTRIBUTION, 555, 9);
		$contribution->setId(77);
		$this->contributionMapper->method('find')->willReturn($contribution);
		$this->transactionMapper->method('findById')->willReturn($this->leg(555, 9));
		$this->shares->method('canWrite')->willReturn(false);

		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');
		$this->contributionMapper->expects($this->once())->method('delete')->with($contribution);

		$this->service->deleteContribution(77, 'user1');
	}

	private function scheduleLastPosted(int $contributionId, string $date, float $amount): \OCA\Budget\Db\PensionRecurringContribution {
		$recur = new \OCA\Budget\Db\PensionRecurringContribution();
		$recur->setId(5);
		$recur->setPensionId(1);
		$recur->setNextDueDate('2026-11-01');
		$recur->setLastPostedDate($date);
		$recur->setIsActive(true);
		$recur->setPostUndoState(json_encode([
			'nextDueDate' => '2026-10-01',
			'lastPostedDate' => '2026-09-01',
			'isActive' => true,
			'contributionId' => $contributionId,
			'contributionDate' => $date,
			'amount' => $amount,
		]));
		return $recur;
	}

	public function testDeletingTheContributionPostNowRecordedPutsTheScheduleBack(): void {
		// The extra contribution of a double post: it is still owed
		$contribution = $this->makeContribution('2026-10-02', 200.0, PensionContribution::KIND_CONTRIBUTION, null, null);
		$contribution->setId(77);
		$contribution->setPensionId(1);
		$this->contributionMapper->method('find')->willReturn($contribution);
		$recur = $this->scheduleLastPosted(77, '2026-10-02', 200.0);
		$this->recurringMapper->method('findByPension')->willReturn([$recur]);
		$this->recurringMapper->expects($this->once())->method('update')->with($recur);

		$this->service->deleteContribution(77, 'user1');

		$this->assertSame('2026-10-01', $recur->getNextDueDate());
		$this->assertSame('2026-09-01', $recur->getLastPostedDate());
		$this->assertNull($recur->getPostUndoState());
	}

	public function testDeletingAnotherContributionLeavesTheScheduleAlone(): void {
		$contribution = $this->makeContribution('2026-09-02', 200.0, PensionContribution::KIND_CONTRIBUTION, null, null);
		$contribution->setId(76);
		$contribution->setPensionId(1);
		$this->contributionMapper->method('find')->willReturn($contribution);
		$recur = $this->scheduleLastPosted(77, '2026-10-02', 200.0);
		$this->recurringMapper->method('findByPension')->willReturn([$recur]);
		$this->recurringMapper->expects($this->never())->method('update');

		$this->service->deleteContribution(76, 'user1');

		$this->assertSame('2026-11-01', $recur->getNextDueDate());
	}

	/**
	 * The bank legs that paid into or out of a deleted pension keep their
	 * pension marker, which is what keeps them out of spending and income.
	 * Clearing it turned every past contribution into uncategorised spending
	 * (and every withdrawal into income) in every report.
	 */
	public function testDeletingAPensionKeepsItsBankLegsOutOfSpending(): void {
		$pension = $this->makePension();
		$this->pensionMapper->method('find')->willReturn($pension);
		$linked = $this->makeContribution('2026-03-01', 500.0, PensionContribution::KIND_CONTRIBUTION, 555, 10);
		$linked->setId(77);
		$this->contributionMapper->method('findLinkedByPension')->willReturn([$linked]);

		$this->transactionService->expects($this->never())->method('clearPensionContribMarkers');
		$this->transactionService->expects($this->never())->method('delete');
		$this->contributionMapper->expects($this->once())->method('deleteByPension')->with(1, 'user1');
		$this->recurringMapper->expects($this->once())->method('deleteByPension')->with(1, 'user1');
		$this->pensionMapper->expects($this->once())->method('delete')->with($pension);

		$this->service->delete(1, 'user1');
	}

	private function makeSnapshot(string $date, float $balance): PensionSnapshot {
		$s = new PensionSnapshot();
		$s->setDate($date);
		$s->setBalance($balance);
		return $s;
	}

	private function makeContribution(string $date, float $amount, string $kind, ?int $txId, ?int $accountId): PensionContribution {
		$c = new PensionContribution();
		$c->setDate($date);
		$c->setAmount($amount);
		$c->setKind($kind);
		$c->setTransactionId($txId);
		$c->setSourceAccountId($accountId);
		return $c;
	}
}

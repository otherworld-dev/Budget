<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionRecurringContribution;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\PensionRecurringService;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class PensionRecurringServiceTest extends TestCase {
	private PensionRecurringService $service;
	/** @var PensionRecurringContributionMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $recurringMapper;
	/** @var PensionAccountMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $pensionMapper;
	/** @var PensionService&\PHPUnit\Framework\MockObject\MockObject */
	private $pensionService;
	/** @var UserClock&\PHPUnit\Framework\MockObject\MockObject */
	private $userClock;
	private string $today = '2026-10-02';
	private int $nextContributionId = 100;
	/** @var array<int, PensionContribution> */
	private array $contributions = [];

	protected function setUp(): void {
		$this->recurringMapper = $this->createMock(PensionRecurringContributionMapper::class);
		$this->pensionMapper = $this->createMock(PensionAccountMapper::class);
		$this->pensionService = $this->createMock(PensionService::class);
		$this->userClock = $this->createMock(UserClock::class);
		$this->userClock->method('today')->willReturnCallback(fn () => $this->today);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));

		$this->pensionMapper->method('find')->willReturnCallback(fn ($id) => $this->makePension((int)$id));
		$this->recurringMapper->method('update')->willReturnCallback(fn ($r) => $r);
		$this->recurringMapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(9);
			return $r;
		});
		$made = function (int $pensionId, string $userId, float $amount, string $date) {
			$c = new PensionContribution();
			$c->setId($this->nextContributionId++);
			$c->setPensionId($pensionId);
			$c->setAmount($amount);
			$c->setDate($date);
			$this->contributions[$c->getId()] = $c;
			return $c;
		};
		$this->pensionService->method('createContribution')->willReturnCallback($made);
		$this->pensionService->method('createContributionWithTransfer')->willReturnCallback($made);
		$this->pensionService->method('findContribution')->willReturnCallback(function (int $id) {
			if (!isset($this->contributions[$id])) {
				throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
			}
			return $this->contributions[$id];
		});

		// FrequencyCalculator is pure logic — use the real one.
		$this->service = new PensionRecurringService(
			$this->recurringMapper,
			$this->pensionMapper,
			$this->pensionService,
			new FrequencyCalculator(),
			$this->userClock,
			$l
		);
	}

	private function makePension(int $id, string $type = 'workplace'): PensionAccount {
		$pension = new PensionAccount();
		$pension->setId($id);
		$pension->setUserId('user1');
		$pension->setName('Work');
		$pension->setType($type);
		return $pension;
	}

	private function makeRecur(array $overrides = []): PensionRecurringContribution {
		$recur = new PensionRecurringContribution();
		$defaults = [
			'id' => 5,
			'userId' => 'user1',
			'pensionId' => 1,
			'amount' => 200.0,
			'frequency' => 'monthly',
			'sourceAccountId' => null,
			'nextDueDate' => '2026-03-01',
			'anchorDate' => null,
			'autoPostEnabled' => true,
			'isActive' => true,
		];
		$data = array_merge($defaults, $overrides);
		$recur->setId($data['id']);
		$recur->setUserId($data['userId']);
		$recur->setPensionId($data['pensionId']);
		$recur->setAmount($data['amount']);
		$recur->setFrequency($data['frequency']);
		$recur->setSourceAccountId($data['sourceAccountId']);
		$recur->setNextDueDate($data['nextDueDate']);
		$recur->setAnchorDate($data['anchorDate']);
		$recur->setAutoPostEnabled($data['autoPostEnabled']);
		$recur->setIsActive($data['isActive']);
		return $recur;
	}

	/** Post the schedule by hand $times times and list the next date after each */
	private function postRepeatedly(PensionRecurringContribution $recur, int $times): array {
		$this->recurringMapper->method('find')->willReturn($recur);
		$dates = [];
		for ($i = 0; $i < $times; $i++) {
			$this->service->postNow(5, 'user1');
			$dates[] = $recur->getNextDueDate();
		}
		return $dates;
	}

	// ── The schedule keeps its own day ──────────────────────────────

	public function testMonthlyOnThe31stComesBackToThe31stAfterAShortMonth(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2027-01-31']);

		$this->assertSame(['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], $this->postRepeatedly($recur, 4));
	}

	public function testMonthlyOnThe30thDoesNotSkipFebruary(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2027-01-30']);

		$this->assertSame(['2027-02-28', '2027-03-30'], $this->postRepeatedly($recur, 2));
	}

	public function testQuarterlyOnDecember31stStaysQuarterly(): void {
		$recur = $this->makeRecur(['frequency' => 'quarterly', 'nextDueDate' => '2026-12-31']);

		$this->assertSame(['2027-03-31', '2027-06-30', '2027-09-30', '2027-12-31'], $this->postRepeatedly($recur, 4));
	}

	public function testQuarterlyOnNovember30thFallsInFebruary(): void {
		$recur = $this->makeRecur(['frequency' => 'quarterly', 'nextDueDate' => '2026-11-30']);

		$this->assertSame(['2027-02-28', '2027-05-30'], $this->postRepeatedly($recur, 2));
	}

	public function testYearlyOnFebruary29thReturnsInLeapYears(): void {
		$recur = $this->makeRecur(['frequency' => 'yearly', 'nextDueDate' => '2028-02-29']);

		$this->assertSame(['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], $this->postRepeatedly($recur, 4));
	}

	public function testAnOlderScheduleKeepsTheDayItWasPostedFromFirst(): void {
		// Rows from before the anchor existed take theirs from the first post
		$recur = $this->makeRecur(['nextDueDate' => '2027-01-31', 'anchorDate' => null]);

		$this->postRepeatedly($recur, 1);

		$this->assertSame('2027-01-31', $recur->getAnchorDate());
	}

	public function testCreateKeepsTheFirstDateAsTheAnchor(): void {
		$recur = $this->service->create(1, 'user1', 350.0, 'quarterly', null, true, '2026-12-31', 'note');

		$this->assertSame('2026-12-31', $recur->getAnchorDate());
		$this->assertSame('2026-12-31', $recur->getNextDueDate());
	}

	// ── Frequencies ─────────────────────────────────────────────────

	public function testCreateRefusesAnUnknownFrequency(): void {
		$this->recurringMapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(1, 'user1', 200.0, 'monthy', null, true, '2026-11-01');
	}

	public function testCreateRefusesAOneTimeSchedule(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(1, 'user1', 200.0, 'one-time', null, true, '2026-11-01');
	}

	public function testUpdateRefusesAnUnknownFrequency(): void {
		$this->recurringMapper->method('find')->willReturn($this->makeRecur());
		$this->recurringMapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(5, 'user1', ['frequency' => 'fortnightly']);
	}

	public function testANewNextDateBecomesTheSchedulesDay(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-10-31', 'anchorDate' => '2026-01-31']);
		$this->recurringMapper->method('find')->willReturn($recur);

		$this->service->update(5, 'user1', ['nextDueDate' => '2026-11-15']);

		$this->assertSame('2026-11-15', $recur->getNextDueDate());
		$this->assertSame('2026-11-15', $recur->getAnchorDate());
	}

	// ── Posting ─────────────────────────────────────────────────────

	public function testPostNowDatesTheContributionWithTheUsersToday(): void {
		$this->today = '2026-09-27';
		$recur = $this->makeRecur(['nextDueDate' => '2026-09-15']);
		$this->recurringMapper->method('find')->willReturn($recur);

		$this->pensionService->expects($this->once())->method('createContribution')
			->with(1, 'user1', 200.0, '2026-09-27', null);

		$result = $this->service->postNow(5, 'user1');

		$this->assertSame('2026-09-27', $result->getLastPostedDate());
		$this->assertSame('2026-10-15', $result->getNextDueDate());
	}

	public function testPostNowSettlesOneOverdueOccurrenceAtATime(): void {
		$this->today = '2026-09-28';
		$recur = $this->makeRecur(['nextDueDate' => '2026-08-15']);
		$this->recurringMapper->method('find')->willReturn($recur);

		$this->service->postNow(5, 'user1');

		// September is still owed, not silently dropped
		$this->assertSame('2026-09-15', $recur->getNextDueDate());
	}

	public function testPostNowWithSourceAccountUsesTransfer(): void {
		$recur = $this->makeRecur(['sourceAccountId' => 10, 'nextDueDate' => '2026-10-01']);
		$this->recurringMapper->method('find')->willReturn($recur);

		$this->pensionService->expects($this->once())->method('createContributionWithTransfer')
			->with(1, 'user1', 200.0, '2026-10-02', 10, null);
		$this->pensionService->expects($this->never())->method('createContribution');

		$this->service->postNow(5, 'user1');
	}

	public function testPostNowRefusesAnOccurrenceAlreadyPosted(): void {
		// A second click, or a tab still showing the date just posted
		$recur = $this->makeRecur(['nextDueDate' => '2026-11-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->pensionService->expects($this->never())->method('createContribution');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->postNow(5, 'user1', '2026-10-01');
	}

	public function testPostNowRefusesAPausedSchedule(): void {
		$recur = $this->makeRecur(['isActive' => false]);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->pensionService->expects($this->never())->method('createContribution');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->postNow(5, 'user1');
	}

	public function testPostNowKeepsWhatItChangedForUndo(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-10-01']);
		$recur->setLastPostedDate('2026-09-01');
		$this->recurringMapper->method('find')->willReturn($recur);

		$this->service->postNow(5, 'user1', '2026-10-01');

		$undo = json_decode((string)$recur->getPostUndoState(), true);
		$this->assertSame('2026-10-01', $undo['nextDueDate']);
		$this->assertSame('2026-09-01', $undo['lastPostedDate']);
		$this->assertSame(100, $undo['contributionId']);
	}

	public function testUndoPostRemovesTheContributionAndPutsTheDateBack(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-10-01']);
		$recur->setLastPostedDate('2026-09-01');
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->service->postNow(5, 'user1', '2026-10-01');

		$this->pensionService->expects($this->once())->method('deleteContribution')->with(100, 'user1');

		$this->service->undoPost(5, 'user1');

		$this->assertSame('2026-10-01', $recur->getNextDueDate());
		$this->assertSame('2026-09-01', $recur->getLastPostedDate());
		$this->assertNull($recur->getPostUndoState());
	}

	/**
	 * A restored backup's undo state named a contribution id from before the
	 * restore. Putting the dates back anyway posted the occurrence a second
	 * time while the first contribution and its bank debit stayed.
	 */
	public function testUndoPostRefusesWhenItsContributionIsGoneAndKeepsTheDates(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-11-01']);
		$recur->setLastPostedDate('2026-10-02');
		$recur->setPostUndoState(json_encode([
			'nextDueDate' => '2026-10-01', 'lastPostedDate' => '2026-09-01', 'isActive' => true,
			'contributionId' => 4242, 'contributionDate' => '2026-10-02', 'amount' => 200.0,
		]));
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->pensionService->expects($this->never())->method('deleteContribution');

		try {
			$this->service->undoPost(5, 'user1');
			$this->fail('The undo must be refused');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertSame('2026-11-01', $recur->getNextDueDate());
		$this->assertSame('2026-10-02', $recur->getLastPostedDate());
		$this->assertNull($recur->getPostUndoState(), 'The stale undo state is dropped');
	}

	public function testUndoPostRefusesWhenTheIdNowHoldsAnotherContribution(): void {
		$recur = $this->makeRecur(['nextDueDate' => '2026-10-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->service->postNow(5, 'user1', '2026-10-01');
		// Same id, but not what that post recorded
		$this->contributions[100]->setAmount(999.0);
		$this->pensionService->expects($this->never())->method('deleteContribution');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->undoPost(5, 'user1');
	}

	public function testUndoPostRefusesWhenThereIsNothingToUndo(): void {
		$this->recurringMapper->method('find')->willReturn($this->makeRecur());
		$this->expectException(\InvalidArgumentException::class);

		$this->service->undoPost(5, 'user1');
	}

	// ── Auto-post ───────────────────────────────────────────────────

	public function testAutoPostBooksEveryDueOccurrenceOnItsOwnDate(): void {
		$this->today = '2026-10-02';
		$recur = $this->makeRecur(['nextDueDate' => '2026-08-15']);
		$this->recurringMapper->method('find')->willReturn($recur);

		$result = $this->service->processAutoPost(5, 'user1');

		$this->assertTrue($result['success']);
		$this->assertSame(2, $result['count']);
		$dates = array_values(array_map(fn ($c) => $c->getDate(), $this->contributions));
		$this->assertSame(['2026-08-15', '2026-09-15'], $dates);
		$this->assertSame('2026-10-15', $recur->getNextDueDate());
		$this->assertSame('2026-09-15', $recur->getLastPostedDate());
		$this->assertNull($recur->getPostUndoState());
	}

	public function testAutoPostLeavesAScheduleNotYetDue(): void {
		$this->today = '2026-09-30';
		$recur = $this->makeRecur(['nextDueDate' => '2026-10-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$this->pensionService->expects($this->never())->method('createContribution');

		$result = $this->service->processAutoPost(5, 'user1');

		$this->assertFalse($result['success']);
		$this->assertFalse($result['disabled']);
	}

	public function testAutoPostThatCannotPostSwitchesItselfOff(): void {
		$this->today = '2026-10-02';
		$recur = $this->makeRecur(['sourceAccountId' => 10, 'nextDueDate' => '2026-10-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$pensionService = $this->createMock(PensionService::class);
		$pensionService->method('createContributionWithTransfer')
			->willThrowException(new \InvalidArgumentException('The account this contribution comes from no longer exists'));
		$service = new PensionRecurringService($this->recurringMapper, $this->pensionMapper, $pensionService,
			new FrequencyCalculator(), $this->userClock, $this->createMock(IL10N::class));

		$result = $service->processAutoPost(5, 'user1');

		$this->assertFalse($result['success']);
		$this->assertTrue($result['disabled']);
		$this->assertSame('The account this contribution comes from no longer exists', $result['message']);
		$this->assertFalse($recur->getAutoPostEnabled());
		$this->assertSame('2026-10-01', $recur->getNextDueDate(), 'Nothing posted, so nothing settled');
	}

	public function testAutoPostForAPensionThatNoLongerTakesContributionsSwitchesOff(): void {
		// Changed to a defined benefit pension before such changes were refused
		$recur = $this->makeRecur(['pensionId' => 3, 'nextDueDate' => '2026-10-01']);
		$this->recurringMapper->method('find')->willReturn($recur);
		$pensionMapper = $this->createMock(PensionAccountMapper::class);
		$pensionMapper->method('find')->willReturn($this->makePension(3, 'defined_benefit'));
		$service = new PensionRecurringService($this->recurringMapper, $pensionMapper, $this->pensionService,
			new FrequencyCalculator(), $this->userClock, $this->l10n());
		$this->pensionService->expects($this->never())->method('createContribution');

		$result = $service->processAutoPost(5, 'user1');

		$this->assertTrue($result['disabled']);
		$this->assertSame('Work', $result['pensionName']);
		$this->assertFalse($recur->getAutoPostEnabled());
	}

	public function testCreateRefusesAScheduleOnAPensionWithNoPot(): void {
		$pensionMapper = $this->createMock(PensionAccountMapper::class);
		$pensionMapper->method('find')->willReturn($this->makePension(3, 'state'));
		$service = new PensionRecurringService($this->recurringMapper, $pensionMapper, $this->pensionService,
			new FrequencyCalculator(), $this->userClock, $this->l10n());
		$this->recurringMapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);

		$service->create(3, 'user1', 200.0, 'monthly', null, true, '2026-11-01');
	}

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $params = []) => vsprintf($text, $params));
		return $l;
	}

	public function testCreateRefusesAnAccountTheUserCannotPayFrom(): void {
		$this->pensionService->method('requireUsableAccount')
			->willThrowException(new \InvalidArgumentException('Joint is shared with you read-only'));
		$this->recurringMapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(1, 'user1', 200.0, 'monthly', 9, true, '2026-11-01');
	}

	public function testUpdateRefusesAnAccountTheUserCannotPayFrom(): void {
		$this->recurringMapper->method('find')->willReturn($this->makeRecur());
		$this->pensionService->method('requireUsableAccount')
			->willThrowException(new \InvalidArgumentException('Joint is shared with you read-only'));
		$this->recurringMapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(5, 'user1', ['sourceAccountId' => 9]);
	}

	// ── Projection ──────────────────────────────────────────────────

	/**
	 * What the schedules funded from an account will take out of it for the
	 * rest of the year, for the Bills Calendar's projected balance. One
	 * still owed from before today comes out now, in the current month.
	 */
	public function testUpcomingDebitsByMonthCountsEveryContributionStillToBePosted(): void {
		$this->recurringMapper->method('findActiveBySourceAccount')->with(5)->willReturn([
			$this->makeRecur(['sourceAccountId' => 5, 'nextDueDate' => '2099-08-31']),
			$this->makeRecur(['id' => 6, 'pensionId' => 2, 'sourceAccountId' => 5, 'frequency' => 'quarterly', 'amount' => 50.0, 'nextDueDate' => '2099-11-15']),
		]);
		$this->pensionService->method('bankAmount')->willReturnCallback(fn ($pension, $accountId, $amount) => $amount);

		$byMonth = $this->service->upcomingDebitsByMonth(5, '2099-09-20');

		// August's and September's are both still owed: they come out now
		$this->assertEqualsWithDelta(400.0, $byMonth[9], 0.001);
		$this->assertEqualsWithDelta(200.0, $byMonth[10], 0.001);
		$this->assertEqualsWithDelta(250.0, $byMonth[11], 0.001);
		$this->assertEqualsWithDelta(200.0, $byMonth[12], 0.001);
		$this->assertArrayNotHasKey(8, $byMonth);
	}

	public function testUpcomingDebitsLeaveOutAPensionThatTakesNoContributions(): void {
		$this->recurringMapper->method('findActiveBySourceAccount')->willReturn([
			$this->makeRecur(['pensionId' => 3, 'sourceAccountId' => 5, 'nextDueDate' => '2099-10-01']),
		]);
		$pensionMapper = $this->createMock(PensionAccountMapper::class);
		$pensionMapper->method('find')->willReturn($this->makePension(3, 'state'));
		$service = new PensionRecurringService($this->recurringMapper, $pensionMapper, $this->pensionService,
			new FrequencyCalculator(), $this->userClock, $this->l10n());

		$this->assertSame([], $service->upcomingDebitsByMonth(5, '2099-09-20'));
	}

	public function testProcessAutoPostReturnsFailureWhenTheScheduleIsGone(): void {
		$this->recurringMapper->method('find')->willThrowException(new \RuntimeException('boom'));

		$result = $this->service->processAutoPost(5, 'user1');

		$this->assertFalse($result['success']);
		$this->assertSame('boom', $result['message']);
	}

	public function testCreateVerifiesPensionOwnership(): void {
		$this->pensionMapper->expects($this->once())->method('find')->with(1, 'user1')
			->willReturn($this->makePension(1));

		$recur = $this->service->create(1, 'user1', 350.0, 'quarterly', null, true, '2026-09-01', 'note');

		$this->assertSame(1, $recur->getPensionId());
		$this->assertSame('quarterly', $recur->getFrequency());
		$this->assertTrue($recur->getAutoPostEnabled());
	}
}

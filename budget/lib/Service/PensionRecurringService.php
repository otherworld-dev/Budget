<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionRecurringContribution;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Scheduled (recurring) pension contributions (#251). Creates the due
 * contribution — and, when a source account is set, the linked bank transfer
 * (#304) — then advances the schedule.
 *
 * next_due_date is the first occurrence not yet posted. A post settles
 * exactly that one and moves on to the next, so a schedule that fell behind
 * still owes the occurrences it missed. The schedule's day and month come
 * from its anchor date, never from the date it last posted, which a short
 * month has already moved.
 */
class PensionRecurringService {
	/** How often a schedule may run */
	public const FREQUENCIES = ['weekly', 'biweekly', 'monthly', 'quarterly', 'semi-annually', 'yearly'];

	/** Most occurrences one auto-post run books, so a years-old date can't flood the ledger */
	private const MAX_AUTO_POST_CATCH_UP = 60;

	public function __construct(
		private PensionRecurringContributionMapper $recurringMapper,
		private PensionAccountMapper $pensionMapper,
		private PensionService $pensionService,
		private FrequencyCalculator $frequencyCalculator,
		private UserClock $userClock,
		private IL10N $l,
	) {
	}

	/**
	 * @return PensionRecurringContribution[]
	 * @throws DoesNotExistException
	 */
	public function findByPension(int $pensionId, string $userId): array {
		$this->pensionMapper->find($pensionId, $userId); // verify ownership
		return $this->recurringMapper->findByPension($pensionId, $userId);
	}

	/**
	 * Schedules the background job should post for this user now, judged by
	 * the user's own date.
	 *
	 * @return PensionRecurringContribution[]
	 */
	public function findDueForAutoPost(string $userId): array {
		return $this->recurringMapper->findDueForAutoPost($userId, $this->userClock->today($userId));
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function create(
		int $pensionId,
		string $userId,
		float $amount,
		string $frequency,
		?int $sourceAccountId,
		bool $autoPostEnabled,
		string $nextDueDate,
		?string $note = null,
	): PensionRecurringContribution {
		$this->requireContributions($this->pensionMapper->find($pensionId, $userId)); // verifies ownership too
		$this->validateFrequency($frequency);
		if ($sourceAccountId !== null) {
			$this->pensionService->requireUsableAccount($sourceAccountId, $userId);
		}

		$recur = new PensionRecurringContribution();
		$recur->setUserId($userId);
		$recur->setPensionId($pensionId);
		$recur->setAmount($amount);
		$recur->setFrequency($frequency);
		$recur->setSourceAccountId($sourceAccountId);
		$recur->setAutoPostEnabled($autoPostEnabled);
		$recur->setNextDueDate($nextDueDate);
		$recur->setAnchorDate($nextDueDate);
		$recur->setIsActive(true);
		$recur->setNote($note);
		$now = date('Y-m-d H:i:s');
		$recur->setCreatedAt($now);
		$recur->setUpdatedAt($now);

		return $this->recurringMapper->insert($recur);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function update(int $recurId, string $userId, array $fields): PensionRecurringContribution {
		$recur = $this->recurringMapper->find($recurId, $userId);
		$datesChanged = false;

		if (array_key_exists('amount', $fields) && $fields['amount'] !== null) {
			$recur->setAmount((float)$fields['amount']);
		}
		if (array_key_exists('frequency', $fields) && $fields['frequency'] !== null
			&& (string)$fields['frequency'] !== $recur->getFrequency()) {
			$frequency = (string)$fields['frequency'];
			$this->validateFrequency($frequency);
			// The pending occurrence moves to the new schedule's within its
			// own period, so changing monthly to quarterly neither revives a
			// posted month nor skips the current one
			$pending = $recur->getNextDueDate();
			$recur->setFrequency($frequency);
			$recur->setNextDueDate($this->frequencyCalculator->occurrenceOnOrAfter(
				$frequency, null, null,
				$this->frequencyCalculator->periodStart($frequency, $pending),
				null, $this->anchorOf($recur)
			) ?? $pending);
			$datesChanged = true;
		}
		if (array_key_exists('sourceAccountId', $fields)) {
			$sourceAccountId = $fields['sourceAccountId'] !== null && $fields['sourceAccountId'] !== '' ? (int)$fields['sourceAccountId'] : null;
			if ($sourceAccountId !== null && $sourceAccountId !== $recur->getSourceAccountId()) {
				$this->pensionService->requireUsableAccount($sourceAccountId, $userId);
			}
			$recur->setSourceAccountId($sourceAccountId);
		}
		if (array_key_exists('autoPostEnabled', $fields) && $fields['autoPostEnabled'] !== null) {
			$recur->setAutoPostEnabled(filter_var($fields['autoPostEnabled'], FILTER_VALIDATE_BOOLEAN));
		}
		if (array_key_exists('nextDueDate', $fields) && $fields['nextDueDate'] !== null && $fields['nextDueDate'] !== '') {
			// A date the user sets is where the schedule runs from now on
			$recur->setNextDueDate((string)$fields['nextDueDate']);
			$recur->setAnchorDate((string)$fields['nextDueDate']);
			$datesChanged = true;
		}
		if (array_key_exists('isActive', $fields) && $fields['isActive'] !== null) {
			$recur->setIsActive(filter_var($fields['isActive'], FILTER_VALIDATE_BOOLEAN));
		}
		if (array_key_exists('note', $fields)) {
			$recur->setNote($fields['note'] !== null ? (string)$fields['note'] : null);
		}
		if ($datesChanged) {
			// Undoing an earlier post would bring back a date of the old schedule
			$recur->setPostUndoState(null);
		}

		$recur->setUpdatedAt(date('Y-m-d H:i:s'));
		return $this->recurringMapper->update($recur);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $recurId, string $userId): void {
		$recur = $this->recurringMapper->find($recurId, $userId);
		$this->recurringMapper->delete($recur);
	}

	/**
	 * Auto-post a schedule's due occurrences (called by the background job).
	 *
	 * Every occurrence due by the user's today is posted once, on its own
	 * date, so a run after a gap catches up rather than posting the oldest
	 * and jumping past the rest. When it can't post (the pension or account
	 * is gone, or the account can no longer be written to) auto-post switches
	 * itself off, so the job doesn't fail every six hours forever, and the
	 * result says so for the job to tell the user. Never throws.
	 *
	 * @return array{success: bool, count?: int, disabled?: bool, recurring?: PensionRecurringContribution, pensionName?: ?string, message?: string}
	 */
	public function processAutoPost(int $recurId, string $userId): array {
		try {
			$recur = $this->recurringMapper->find($recurId, $userId);
		} catch (\Throwable $e) {
			return ['success' => false, 'disabled' => false, 'message' => $e->getMessage()];
		}
		if (!$recur->getAutoPostEnabled() || !$recur->getIsActive()) {
			return ['success' => false, 'disabled' => false, 'recurring' => $recur, 'message' => 'Auto-post not enabled'];
		}

		$today = $this->userClock->today($userId);
		$posted = 0;
		$pensionName = null;
		try {
			$pension = $this->pensionMapper->find($recur->getPensionId(), $userId);
			$pensionName = $pension->getName();
			$this->requireContributions($pension);
			while ($posted < self::MAX_AUTO_POST_CATCH_UP
				&& $recur->getIsActive()
				&& $recur->getNextDueDate() <= $today) {
				$date = $recur->getNextDueDate();
				$this->record($recur, $userId, $date);
				$this->settle($recur);
				$recur->setLastPostedDate($date);
				// The job's posts aren't Post now's to undo
				$recur->setPostUndoState(null);
				$recur->setUpdatedAt(date('Y-m-d H:i:s'));
				$recur = $this->recurringMapper->update($recur);
				$posted++;
			}
		} catch (\Throwable $e) {
			// Whatever posted before the failure stays posted and settled
			$recur->setAutoPostEnabled(false);
			$recur->setUpdatedAt(date('Y-m-d H:i:s'));
			$this->recurringMapper->update($recur);
			return ['success' => false, 'disabled' => true, 'recurring' => $recur, 'pensionName' => $pensionName, 'message' => $e->getMessage()];
		}

		if ($posted === 0) {
			return ['success' => false, 'disabled' => false, 'recurring' => $recur, 'pensionName' => $pensionName, 'message' => 'Nothing due'];
		}
		return ['success' => true, 'count' => $posted, 'recurring' => $recur, 'pensionName' => $pensionName];
	}

	/**
	 * Post the schedule's next occurrence by hand (the #251 "by hand" path),
	 * dated the user's today, and move on to the one after it.
	 *
	 * @param string|null $expectedDate The occurrence the page showed. A
	 *                                  second click or a stale tab names one already posted and is refused,
	 *                                  instead of moving the money twice.
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function postNow(int $recurId, string $userId, ?string $expectedDate = null): PensionRecurringContribution {
		$recur = $this->recurringMapper->find($recurId, $userId);
		if (!$recur->getIsActive()) {
			throw new \InvalidArgumentException($this->l->t('This scheduled contribution is paused'));
		}
		if ($expectedDate !== null && $expectedDate !== '' && $expectedDate !== $recur->getNextDueDate()) {
			throw new \InvalidArgumentException($this->l->t('This contribution was already posted. Reload the page to see the next one.'));
		}

		$snapshot = [
			'nextDueDate' => $recur->getNextDueDate(),
			'lastPostedDate' => $recur->getLastPostedDate(),
			'isActive' => true,
		];
		$postDate = $this->userClock->today($userId);
		$contribution = $this->record($recur, $userId, $postDate);
		$snapshot['contributionId'] = $contribution->getId();
		$snapshot['contributionDate'] = $postDate;
		$snapshot['amount'] = (float)$recur->getAmount();

		$this->settle($recur);
		$recur->setLastPostedDate($postDate);
		$recur->setPostUndoState(json_encode($snapshot));
		$recur->setUpdatedAt(date('Y-m-d H:i:s'));
		return $this->recurringMapper->update($recur);
	}

	/**
	 * Revert the last Post now: the contribution it recorded (and its bank
	 * leg) is removed and the schedule's dates go back.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException when there is nothing to revert
	 */
	public function undoPost(int $recurId, string $userId): PensionRecurringContribution {
		$recur = $this->recurringMapper->find($recurId, $userId);
		$snapshot = $recur->postUndo();
		if ($snapshot === null) {
			throw new \InvalidArgumentException($this->l->t('This scheduled contribution has no post to undo'));
		}

		try {
			$contribution = $this->pensionService->findContribution((int)($snapshot['contributionId'] ?? 0), $userId);
			// Only ever the contribution that post recorded
			if ($recur->isLastPost($contribution)) {
				$this->pensionService->deleteContribution($contribution->getId(), $userId);
			}
		} catch (DoesNotExistException $e) {
			// Already deleted: only the dates are left to put back
		}

		$recur->revertPost();
		return $this->recurringMapper->update($recur);
	}

	/**
	 * What the schedules funded from an account will take out of it from
	 * today to the end of the year, by month, in the account's currency:
	 * every occurrence not yet posted. One still owed from before today
	 * comes out now, so it counts in the current month. Feeds the Bills
	 * Calendar's projected balance, which left them out.
	 *
	 * @return array<int, float> month => amount
	 */
	public function upcomingDebitsByMonth(int $accountId, string $today): array {
		$yearEnd = substr($today, 0, 4) . '-12-31';
		$currentMonth = (int)substr($today, 5, 2);
		$byMonth = [];
		foreach ($this->recurringMapper->findActiveBySourceAccount($accountId) as $recur) {
			try {
				$pension = $this->pensionMapper->find($recur->getPensionId(), $recur->getUserId());
			} catch (DoesNotExistException $e) {
				continue;
			}
			if (!$pension->isDefinedContribution()) {
				continue;
			}
			$dates = $this->frequencyCalculator->occurrencesBetween(
				$recur->getFrequency(), null, null, $recur->getNextDueDate(), $yearEnd, null, $this->anchorOf($recur)
			);
			foreach ($dates as $date) {
				try {
					$amount = $this->pensionService->bankAmount($pension, $accountId, (float)$recur->getAmount(), $date);
				} catch (DoesNotExistException $e) {
					continue 2;
				}
				$month = $date < $today ? $currentMonth : (int)substr($date, 5, 2);
				$byMonth[$month] = round(($byMonth[$month] ?? 0.0) + $amount, 2);
			}
		}
		ksort($byMonth);
		return $byMonth;
	}

	/**
	 * Record one contribution, with its bank leg when the schedule has a
	 * source account.
	 */
	private function record(PensionRecurringContribution $recur, string $userId, string $date): \OCA\Budget\Db\PensionContribution {
		$note = $recur->getNote();
		$amount = (float)$recur->getAmount();
		$pensionId = $recur->getPensionId();

		if ($recur->getSourceAccountId() !== null) {
			return $this->pensionService->createContributionWithTransfer($pensionId, $userId, $amount, $date, (int)$recur->getSourceAccountId(), $note);
		}
		return $this->pensionService->createContribution($pensionId, $userId, $amount, $date, $note);
	}

	/**
	 * Close the pending occurrence and move to the one after it. A schedule
	 * with no further occurrence (only an unknown frequency from before
	 * they were checked) stops rather than posting the same date again.
	 */
	private function settle(PensionRecurringContribution $recur): void {
		$occurrence = $recur->getNextDueDate();
		$anchor = $this->anchorOf($recur);
		// Older rows have no anchor: the occurrence they post first is
		// the last date nothing has moved yet
		if ($recur->getAnchorDate() === null || $recur->getAnchorDate() === '') {
			$recur->setAnchorDate($anchor);
		}
		$next = $this->frequencyCalculator->occurrenceAfter($recur->getFrequency(), null, null, $occurrence, null, $anchor);
		if ($next === null) {
			$recur->setIsActive(false);
			return;
		}
		$recur->setNextDueDate($next);
	}

	/** The date the schedule's day and month come from */
	private function anchorOf(PensionRecurringContribution $recur): string {
		$anchor = $recur->getAnchorDate();
		return ($anchor !== null && $anchor !== '') ? $anchor : $recur->getNextDueDate();
	}

	/**
	 * Only a pension with a pot (defined contribution) takes contributions;
	 * a defined benefit or state pension hides its schedules.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireContributions(PensionAccount $pension): void {
		if (!$pension->isDefinedContribution()) {
			throw new \InvalidArgumentException($this->l->t('%1$s is a defined benefit or state pension, which takes no contributions', [$pension->getName()]));
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function validateFrequency(string $frequency): void {
		if (!in_array($frequency, self::FREQUENCIES, true)) {
			throw new \InvalidArgumentException($this->l->t('Choose how often the contribution is made: weekly, every two weeks, monthly, quarterly, every six months or yearly'));
		}
	}
}

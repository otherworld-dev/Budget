<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BillService;
use OCP\IConfig;
use OCP\L10N\IFactory;

/**
 * Generates an iCalendar (RFC 5545) feed of the user's active bills, with
 * occurrences materialized for the coming months. Custom recurrence patterns
 * and semi-monthly schedules don't map cleanly onto RRULEs, so concrete
 * VEVENTs are emitted instead, driven by FrequencyCalculator.
 */
class BillIcsService {

	private const MAX_OCCURRENCES_PER_BILL = 400; // hard cap (daily bills, 12 months)

	public function __construct(
		private BillService $billService,
		private FrequencyCalculator $frequencyCalculator,
		private AmountFormatter $amountFormatter,
		private IFactory $l10nFactory,
		private IConfig $config,
		private ?BudgetCalendarEstimates $budgetEstimates = null,
	) {
	}

	public function generateBillsFeed(string $userId, int $monthsAhead = 12): string {
		$lang = $this->l10nFactory->getUserLanguage(null) ?? 'en';
		try {
			$lang = $this->config->getUserValue($userId, 'core', 'lang', $lang) ?: $lang;
		} catch (\Exception $e) {
			// keep default
		}
		$l = $this->l10nFactory->get('budget', $lang);

		$bills = $this->billService->findActive($userId);
		$bills = $this->billService->enrichBillsWithCurrency($bills, $userId);

		$horizon = (new \DateTime())->modify("+{$monthsAhead} months")->format('Y-m-d');
		$instanceId = $this->config->getSystemValueString('instanceid', 'budget');
		$now = gmdate('Ymd\THis\Z');

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud Budget//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			$this->fold('X-WR-CALNAME:' . $this->escape($l->t('Bills'))),
			'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
			'X-PUBLISHED-TTL:PT12H',
		];

		foreach ($bills as $bill) {
			foreach ($this->materializeOccurrences($bill, $horizon) as $date) {
				$lines = array_merge($lines, $this->buildEvent($bill, $date, $instanceId, $now, $l));
			}
		}

		foreach ($this->budgetEvents($userId, $horizon, $instanceId, $now, $l) as $event) {
			$lines = array_merge($lines, $event);
		}

		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Concrete occurrence dates (Y-m-d) for a bill up to the horizon.
	 *
	 * @return string[]
	 */
	private function materializeOccurrences(Bill $bill, string $horizon): array {
		$date = $bill->getNextDueDate();
		if ($date === null || $date === '') {
			return [];
		}

		// One-time bills (and anything that can't advance) emit a single event
		if ($bill->getFrequency() === 'one-time') {
			return $date <= $horizon ? [$date] : [];
		}

		$occurrences = [];
		$remaining = $bill->getRemainingPayments();
		$endDate = $bill->getEndDate();

		while ($date <= $horizon && count($occurrences) < self::MAX_OCCURRENCES_PER_BILL) {
			if ($endDate !== null && $date > $endDate) {
				break;
			}
			$occurrences[] = $date;
			if ($remaining !== null && count($occurrences) >= $remaining) {
				break;
			}

			$next = $this->frequencyCalculator->calculateNextDueDate(
				$bill->getFrequency(),
				$bill->getDueDay(),
				$bill->getDueMonth(),
				$date,
				$bill->getCustomRecurrencePattern(),
				true,
				$bill->getStartDate()
			);
			if ($next <= $date) {
				// Guard against non-advancing frequencies (would loop forever)
				break;
			}
			$date = $next;
		}

		return $occurrences;
	}

	/**
	 * @return string[] ICS lines for one VEVENT
	 */
	private function buildEvent(Bill $bill, string $date, string $instanceId, string $dtstamp, $l): array {
		$dateCompact = str_replace('-', '', $date);
		$amount = $this->amountFormatter->format((float)$bill->getAmount(), $bill->getCurrency() ?? 'USD');

		$lines = [
			'BEGIN:VEVENT',
			$this->fold("UID:budget-bill-{$bill->getId()}-{$dateCompact}@{$instanceId}"),
			"DTSTAMP:{$dtstamp}",
			"DTSTART;VALUE=DATE:{$dateCompact}",
			$this->fold('SUMMARY:' . $this->escape($bill->getName() . ' (' . $amount . ')')),
		];

		$notes = $bill->getNotes();
		if ($notes !== null && $notes !== '') {
			$lines[] = $this->fold('DESCRIPTION:' . $this->escape($notes));
		}

		$reminderDays = $bill->getReminderDays();
		if ($reminderDays !== null && $reminderDays > 0) {
			$lines[] = 'BEGIN:VALARM';
			$lines[] = 'ACTION:DISPLAY';
			$lines[] = $this->fold('DESCRIPTION:' . $this->escape($l->t('Bill due: %1$s', [$bill->getName()])));
			$lines[] = "TRIGGER:-P{$reminderDays}D";
			$lines[] = 'END:VALARM';
		}

		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * The budgets put in the Bills Calendar (#459), one all-day event on the
	 * first of each calendar month from this one to the horizon's, holding
	 * the month's share of the budget (BudgetCalendarEstimates). The UID is
	 * the category and the month, so a refresh updates the event in place:
	 * a changed budget changes its title, never adds a second one.
	 *
	 * @return list<string[]> ICS lines, one list per VEVENT
	 */
	private function budgetEvents(string $userId, string $horizon, string $instanceId, string $dtstamp, $l): array {
		if ($this->budgetEstimates === null) {
			return [];
		}
		$months = [];
		$month = new \DateTime('first day of this month');
		$last = substr($horizon, 0, 7);
		while ($month->format('Y-m') <= $last) {
			$months[] = $month->format('Y-m');
			$month->modify('first day of next month');
		}

		$estimates = $this->budgetEstimates->forMonths($userId, $months);
		$events = [];
		foreach ($estimates['rows'] as $row) {
			$category = $row['category'];
			foreach ($row['amounts'] as $ym => $amount) {
				$dateCompact = str_replace('-', '', $ym) . '01';
				$formatted = $this->amountFormatter->format((float)$amount, $estimates['currency']);
				$events[] = [
					'BEGIN:VEVENT',
					$this->fold("UID:budget-estimate-{$category->getId()}-{$dateCompact}@{$instanceId}"),
					"DTSTAMP:{$dtstamp}",
					"DTSTART;VALUE=DATE:{$dateCompact}",
					// TRANSLATORS: %1$s is a category name, %2$s the amount its budget plans to spend in the month. A calendar event title.
					$this->fold('SUMMARY:' . $this->escape($l->t('%1$s budget: %2$s', [$category->getName(), $formatted]))),
					$this->fold('DESCRIPTION:' . $this->escape($l->t('Estimated spending for the month, from your budget.'))),
					'TRANSP:TRANSPARENT',
					'END:VEVENT',
				];
			}
		}
		return $events;
	}

	/**
	 * RFC 5545 text escaping: backslash, semicolon, comma, newline.
	 */
	private function escape(string $text): string {
		return str_replace(
			['\\', ';', ',', "\r\n", "\n", "\r"],
			['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
			$text
		);
	}

	/**
	 * RFC 5545 line folding: lines longer than 75 octets are split with
	 * CRLF + single space continuation.
	 */
	private function fold(string $line): string {
		if (strlen($line) <= 75) {
			return $line;
		}
		$out = [];
		$first = true;
		while ($line !== '') {
			$max = $first ? 75 : 74;
			// Don't split inside a UTF-8 multi-byte sequence
			$chunk = substr($line, 0, $max);
			while ($chunk !== '' && (ord(substr($line, strlen($chunk), 1) ?: ' ') & 0xC0) === 0x80) {
				$chunk = substr($chunk, 0, -1);
			}
			$out[] = ($first ? '' : ' ') . $chunk;
			$line = substr($line, strlen($chunk));
			$first = false;
		}
		return implode("\r\n", $out);
	}
}

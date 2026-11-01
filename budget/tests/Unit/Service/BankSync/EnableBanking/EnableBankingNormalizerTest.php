<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync\EnableBanking;

use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingNormalizer;
use PHPUnit\Framework\TestCase;

class EnableBankingNormalizerTest extends TestCase {
	private EnableBankingNormalizer $normalizer;

	protected function setUp(): void {
		$this->normalizer = new EnableBankingNormalizer();
	}

	private function tx(array $overrides = []): array {
		return array_merge([
			'transaction_id' => 'tx-1',
			'entry_reference' => 'ref-1',
			'transaction_amount' => ['amount' => '12.30', 'currency' => 'EUR'],
			'credit_debit_indicator' => 'DBIT',
			'status' => 'BOOK',
			'booking_date' => '2026-10-20',
			'value_date' => '2026-10-21',
			'remittance_information' => ['Rewe Markt'],
			'creditor' => ['name' => 'REWE'],
			'debtor' => ['name' => 'Sascha'],
		], $overrides);
	}

	// ── transaction ─────────────────────────────────────────────────

	public function testDebitAmountIsNegated(): void {
		$this->assertSame('-12.30', $this->normalizer->transaction($this->tx())['amount']);
	}

	public function testCreditAmountStaysPositive(): void {
		$row = $this->normalizer->transaction($this->tx(['credit_debit_indicator' => 'CRDT']));
		$this->assertSame('12.30', $row['amount']);
	}

	public function testPendingStatusMarksTransactionPending(): void {
		$this->assertTrue($this->normalizer->transaction($this->tx(['status' => 'PDNG']))['pending']);
		$this->assertFalse($this->normalizer->transaction($this->tx())['pending']);
	}

	public function testIdFallsBackToEntryReference(): void {
		$row = $this->normalizer->transaction($this->tx(['transaction_id' => null]));
		$this->assertSame('ref-1', $row['id']);
	}

	public function testIdFallsBackToStableHashWhenNoProviderIds(): void {
		$input = $this->tx(['transaction_id' => null, 'entry_reference' => null]);

		$first = $this->normalizer->transaction($input)['id'];
		$second = $this->normalizer->transaction($input)['id'];
		$other = $this->normalizer->transaction(array_merge($input, ['booking_date' => '2026-10-22']))['id'];

		$this->assertStringStartsWith('h:', $first);
		$this->assertSame(34, strlen($first));
		$this->assertSame($first, $second);
		$this->assertNotSame($first, $other);
	}

	public function testRemittanceInformationIsJoined(): void {
		$row = $this->normalizer->transaction($this->tx(['remittance_information' => ['Miete', 'Oktober']]));
		$this->assertSame('Miete Oktober', $row['description']);
	}

	public function testDescriptionFallsBackToCounterpartyName(): void {
		$row = $this->normalizer->transaction($this->tx(['remittance_information' => []]));
		$this->assertSame('REWE', $row['description']);

		$row = $this->normalizer->transaction($this->tx(['remittance_information' => null, 'creditor' => null, 'debtor' => null]));
		$this->assertSame('', $row['description']);
	}

	public function testVendorIsCounterpartyByDirection(): void {
		$this->assertSame('REWE', $this->normalizer->transaction($this->tx())['vendor']);
		$this->assertSame('Sascha', $this->normalizer->transaction($this->tx(['credit_debit_indicator' => 'CRDT']))['vendor']);
	}

	public function testDateFallsBackToValueThenTransactionDate(): void {
		$this->assertSame('2026-10-20', $this->normalizer->transaction($this->tx())['date']);
		$this->assertSame('2026-10-21', $this->normalizer->transaction($this->tx(['booking_date' => null]))['date']);
		$this->assertSame('2026-10-19', $this->normalizer->transaction($this->tx([
			'booking_date' => null,
			'value_date' => null,
			'transaction_date' => '2026-10-19',
		]))['date']);
	}

	// ── balance ─────────────────────────────────────────────────────

	private function bal(string $type, string $amount): array {
		return ['balance_type' => $type, 'balance_amount' => ['amount' => $amount, 'currency' => 'EUR']];
	}

	public function testBalancePrefersBookedUnlessPendingIncluded(): void {
		$balances = [$this->bal('ITAV', '900'), $this->bal('CLBD', '1000'), $this->bal('XPCD', '950')];

		$this->assertSame('1000', $this->normalizer->balance($balances, false));
		$this->assertSame('950', $this->normalizer->balance($balances, true));
	}

	/**
	 * CLBD is usually yesterday's closing balance, while Budget compares
	 * against its balance as of today: the interim booked balance comes first.
	 */
	public function testBookedBalancePrefersInterimOverClosing(): void {
		$balances = [$this->bal('CLBD', '1000'), $this->bal('XPCD', '950'), $this->bal('ITBD', '980')];

		$this->assertSame('980', $this->normalizer->balance($balances, false));
		$this->assertSame('950', $this->normalizer->balance($balances, true));
		$this->assertSame('1000', $this->normalizer->balance([$this->bal('XPCD', '950'), $this->bal('CLBD', '1000')], false));
	}

	public function testBalanceFallsBackToFirstEntryWhenOnlyAvailableTypes(): void {
		$this->assertSame('900', $this->normalizer->balance([$this->bal('ITAV', '900')], false));
	}

	public function testBalanceIsNullWithoutEntries(): void {
		$this->assertNull($this->normalizer->balance([], false));
	}

	/**
	 * Budget compares the bank balance against its own as of the day the
	 * bank reports it for: comdirect's CLBD is the previous day's close.
	 */
	public function testBalanceWithDateReturnsTheChosenEntrysReferenceDate(): void {
		$balances = [
			$this->bal('CLBD', '1000') + ['reference_date' => '2026-10-26'],
			$this->bal('XPCD', '950') + ['reference_date' => '2026-10-27'],
		];

		$this->assertSame(['amount' => '1000', 'date' => '2026-10-26'], $this->normalizer->balanceWithDate($balances, false));
		$this->assertSame(['amount' => '950', 'date' => '2026-10-27'], $this->normalizer->balanceWithDate($balances, true));
	}

	public function testBalanceWithDateKeepsOnlyTheDayOfADateTime(): void {
		$balances = [$this->bal('ITBD', '5') + ['reference_date' => '2026-10-26T23:59:59.000Z']];

		$this->assertSame('2026-10-26', $this->normalizer->balanceWithDate($balances, false)['date']);
	}

	public static function unusableReferenceDates(): array {
		return [
			'missing' => [[]],
			'null' => [['reference_date' => null]],
			'empty' => [['reference_date' => '']],
			'not a date' => [['reference_date' => 'yesterday']],
			'impossible day' => [['reference_date' => '2026-02-30']],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unusableReferenceDates')]
	public function testBalanceWithDateHasNoDateWithoutAUsableReferenceDate(array $extra): void {
		$this->assertSame(['amount' => '1000', 'date' => null], $this->normalizer->balanceWithDate([$this->bal('CLBD', '1000') + $extra], false));
	}

	public function testBalanceWithDateWithoutEntries(): void {
		$this->assertSame(['amount' => null, 'date' => null], $this->normalizer->balanceWithDate([], false));
	}

	public function testFallbackBalanceCarriesItsReferenceDate(): void {
		$this->assertSame(
			['amount' => '900', 'date' => '2026-10-25'],
			$this->normalizer->balanceWithDate([$this->bal('ITAV', '900') + ['reference_date' => '2026-10-25']], false)
		);
	}

	// ── accountName ─────────────────────────────────────────────────

	public function testAccountNameUsesProductAndIbanEnding(): void {
		$this->assertSame('Girokonto (…3000)', $this->normalizer->accountName(['product' => 'Girokonto', 'account_id' => ['iban' => 'DE89370400440532013000']]));
		$this->assertSame('Sascha K (…1234)', $this->normalizer->accountName(['name' => 'Sascha K', 'account_id' => ['iban' => 'DE00 0000 0000 0000 1234']]));
		$this->assertSame('X', $this->normalizer->accountName(['name' => 'X']));
	}

	public function testAccountNamePrefersProductThenDetailsThenName(): void {
		$this->assertSame('Giro', $this->normalizer->accountName(['product' => 'Giro', 'details' => 'D', 'name' => 'N']));
		$this->assertSame('D', $this->normalizer->accountName(['details' => 'D', 'name' => 'N']));
		$this->assertSame('N', $this->normalizer->accountName(['name' => 'N']));
		$this->assertSame('Account (…3000)', $this->normalizer->accountName(['account_id' => ['iban' => 'DE89370400440532013000']]));
		$this->assertSame('Account', $this->normalizer->accountName([]));
	}

	public function testTwoAccountsOfOneHolderGetDifferentLabels(): void {
		$a = $this->normalizer->accountName(['name' => 'Sascha K', 'account_id' => ['iban' => 'DE89370400440532013000']]);
		$b = $this->normalizer->accountName(['name' => 'Sascha K', 'account_id' => ['iban' => 'DE02120300000000202051']]);
		$this->assertNotSame($a, $b);
	}
}

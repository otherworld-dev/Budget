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

	public function testBalanceFallsBackToFirstEntryWhenOnlyAvailableTypes(): void {
		$this->assertSame('900', $this->normalizer->balance([$this->bal('ITAV', '900')], false));
	}

	public function testBalanceIsNullWithoutEntries(): void {
		$this->assertNull($this->normalizer->balance([], false));
	}

	// ── accountName ─────────────────────────────────────────────────

	public function testAccountNameFallbacks(): void {
		$this->assertSame('Girokonto', $this->normalizer->accountName(['name' => 'Girokonto', 'product' => 'Giro']));
		$this->assertSame('Giro', $this->normalizer->accountName(['product' => 'Giro']));
		$this->assertSame('DE…3000', $this->normalizer->accountName(['account_id' => ['iban' => 'DE89370400440532013000']]));
		$this->assertSame('Account', $this->normalizer->accountName([]));
	}
}

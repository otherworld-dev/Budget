<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Bill;
use PHPUnit\Framework\TestCase;

/**
 * The bill says when its last payment was a row already in the ledger
 * (#399): Mark Unpaid keeps that row, and its question must not say a
 * linked transaction will be unlinked. Only the hint goes out; the snapshot
 * stays on the server.
 */
class BillLastPaymentUsedExistingTest extends TestCase {
	private static function bill(?array $snapshot): Bill {
		$bill = new Bill();
		$bill->setId(20890);
		$bill->setPaidUndoState($snapshot === null ? null : json_encode($snapshot));
		return $bill;
	}

	public function testAPaymentThatUsedARowSaysSo(): void {
		// Corner Card SA: Mark Paid, "Use that payment" on Monatsrechnung
		$json = self::bill(['previousState' => [], 'createdTransactionIds' => [], 'linkedTransactionId' => null, 'usedTransactionId' => 1786700])->jsonSerialize();

		$this->assertTrue($json['lastPaymentUsedExisting']);
		$this->assertTrue($json['canMarkUnpaid']);
		$this->assertArrayNotHasKey('paidUndoState', $json);
	}

	public function testAPaymentThatBookedOrLinkedARowDoesNot(): void {
		$this->assertFalse(self::bill(['previousState' => [], 'createdTransactionIds' => [5], 'usedTransactionId' => null])->jsonSerialize()['lastPaymentUsedExisting']);
		$this->assertFalse(self::bill(['previousState' => [], 'linkedTransactionId' => 6])->jsonSerialize()['lastPaymentUsedExisting']);
	}

	public function testABillWithNoPaymentToUndoDoesNot(): void {
		$this->assertFalse(self::bill(null)->jsonSerialize()['lastPaymentUsedExisting']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionSplit;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's Entity only writes the fields a setter marked, and a setter
 * marks nothing when the value equals the property's current one. With the
 * amount defaulting to '0', setAmount('0') on a new part was dropped from
 * the INSERT, and the NOT NULL amount column refused the row.
 */
class TransactionSplitTest extends TestCase {
	public function testAnExplicitZeroAmountIsWrittenOnInsert(): void {
		$split = new TransactionSplit();
		$split->setAmount('0');

		$this->assertArrayHasKey('amount', $split->getUpdatedFields());
		$this->assertSame('0', $split->getAmount());
	}

	public function testANewPartSerialisesWithoutAnAmountAsZero(): void {
		$this->assertSame(0.0, (new TransactionSplit())->jsonSerialize()['amount']);
	}
}

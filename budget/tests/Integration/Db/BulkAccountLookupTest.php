<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A bulk action groups the rows it names by their account's owner before it
 * runs, so a recipient's bulk delete or edit on a shared account runs as the
 * owner (T4-3). The lookup must only ever answer for rows in the accounts
 * the caller can see.
 */
class BulkAccountLookupTest extends IntegrationTestCase {
	public function testOnlyRowsInTheGivenAccountsAreAnswered(): void {
		$other = $this->newUserId();
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$private = $this->makeAccount(['name' => 'Private'])->getId();
		$theirs = $this->makeAccount(['name' => 'Theirs'], $other)->getId();

		$inJoint = $this->makeTransaction($joint);
		$inPrivate = $this->makeTransaction($private);
		$inTheirs = $this->makeTransaction($theirs);

		$found = $this->service(TransactionMapper::class)->findAccountIdsWithin(
			[$inJoint, $inPrivate, $inTheirs, 999999999],
			[$joint, $theirs]
		);
		ksort($found);

		$expected = [$inJoint => $joint, $inTheirs => $theirs];
		ksort($expected);
		$this->assertSame($expected, $found);
	}

	public function testMoreIdsThanOneChunkAreAllAnswered(): void {
		$account = $this->makeAccount()->getId();
		$ids = [];
		for ($i = 0; $i < 3; $i++) {
			$ids[] = $this->makeTransaction($account);
		}
		// Padded past the 500-id chunk with ids that don't exist
		$asked = array_merge(range(900000001, 900000600), $ids);

		$found = $this->service(TransactionMapper::class)->findAccountIdsWithin($asked, [$account]);

		$this->assertSame($ids, array_keys($found));
	}

	public function testNothingToLookUpAsksNothing(): void {
		$mapper = $this->service(TransactionMapper::class);

		$this->assertSame([], $mapper->findAccountIdsWithin([], [1]));
		$this->assertSame([], $mapper->findAccountIdsWithin([1], []));
	}
}

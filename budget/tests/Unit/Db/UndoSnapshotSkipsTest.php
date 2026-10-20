<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\UndoSnapshotSkips;
use PHPUnit\Framework\TestCase;

/**
 * A skip sets a payment's undo snapshot aside, and undoing the skip puts it
 * back in use (#399; see SkipKeepsMarkUnpaidTest)
 */
class UndoSnapshotSkipsTest extends TestCase {
	private const SNAPSHOT = '{"previousState":{"nextDueDate":"2026-09-24"},"createdTransactionIds":[5],"linkedTransactionId":null}';

	public function testASkipKeepsEverythingTheSnapshotHolds(): void {
		$skipped = json_decode((string)UndoSnapshotSkips::skip(self::SNAPSHOT, '2026-10-24'), true);

		$this->assertSame([5], $skipped['createdTransactionIds']);
		$this->assertSame('2026-09-24', $skipped['previousState']['nextDueDate']);
		$this->assertSame(['2026-10-24'], $skipped[UndoSnapshotSkips::KEY]);
		$this->assertTrue(UndoSnapshotSkips::setAside(json_encode($skipped)));
		$this->assertFalse(UndoSnapshotSkips::setAside(self::SNAPSHOT));
	}

	public function testThereIsNothingToSetAsideWithoutASnapshot(): void {
		$this->assertNull(UndoSnapshotSkips::skip(null, '2026-10-24'));
		$this->assertNull(UndoSnapshotSkips::skip('', '2026-10-24'));
		$this->assertNull(UndoSnapshotSkips::skip('not json', '2026-10-24'));
		$this->assertFalse(UndoSnapshotSkips::setAside(null));
	}

	public function testUndoingTheSkipPutsTheSnapshotBackAsItWas(): void {
		$skipped = UndoSnapshotSkips::skip(self::SNAPSHOT, '2026-10-24');

		$this->assertSame(json_decode(self::SNAPSHOT, true), json_decode((string)UndoSnapshotSkips::unskip($skipped, '2026-10-24'), true));
	}

	public function testOnlyTheLastSkipCanBeUndone(): void {
		$twice = UndoSnapshotSkips::skip(UndoSnapshotSkips::skip(self::SNAPSHOT, '2026-10-24'), '2026-11-24');

		$this->assertSame($twice, UndoSnapshotSkips::unskip($twice, '2026-10-24'), 'November is still skipped');
		$once = UndoSnapshotSkips::unskip($twice, '2026-11-24');
		$this->assertTrue(UndoSnapshotSkips::setAside($once));
		$this->assertFalse(UndoSnapshotSkips::setAside(UndoSnapshotSkips::unskip($once, '2026-10-24')));
	}

	public function testUndoingASkipLeavesASnapshotInUseAlone(): void {
		$this->assertSame(self::SNAPSHOT, UndoSnapshotSkips::unskip(self::SNAPSHOT, '2026-10-24'));
		$this->assertNull(UndoSnapshotSkips::unskip(null, '2026-10-24'));
	}
}

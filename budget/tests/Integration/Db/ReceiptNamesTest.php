<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The CSV export's Receipts column (#475) against the real database: each
 * transaction's file names oldest first, and only the caller's own receipts
 * on transactions in the caller's own accounts, as the receipts list shows.
 */
class ReceiptNamesTest extends IntegrationTestCase {
	/** (transaction_id, file_id) is unique, so every row gets its own file */
	private int $fileId = 424200;

	private function attach(int $transactionId, string $name, string $createdAt, ?string $userId = null): void {
		$this->insertRow('budget_attachments', [
			'transaction_id' => $transactionId,
			'user_id' => $userId ?? $this->userId,
			'file_id' => ++$this->fileId,
			'file_name' => $name,
			'mime_type' => 'application/pdf',
			'created_at' => $createdAt,
		]);
	}

	public function testNamesComeOldestFirstPerTransaction(): void {
		$account = $this->makeAccount()->getId();
		$two = $this->makeTransaction($account);
		$none = $this->makeTransaction($account);
		$one = $this->makeTransaction($account);
		// Inserted newest first, so the id order is not the answer
		$this->attach($two, 'delivery note.pdf', '2026-03-16 10:00:00');
		$this->attach($two, 'invoice.pdf', '2026-03-15 09:00:00');
		$this->attach($one, 'receipt.pdf', '2026-03-15 09:00:00');

		$names = $this->service(AttachmentMapper::class)->receiptNames([$two, $none, $one], $this->userId);

		$this->assertSame([$two => ['invoice.pdf', 'delivery note.pdf'], $one => ['receipt.pdf']], $names);
	}

	public function testOnlyTheCallersOwnReceiptsOnTheCallersOwnAccounts(): void {
		$owner = $this->newUserId();
		$mine = $this->makeTransaction($this->makeAccount()->getId());
		$theirs = $this->makeTransaction($this->makeAccount([], $owner)->getId());
		$this->attach($mine, 'mine.pdf', '2026-03-15 09:00:00');
		$this->attach($mine, 'someone-elses-row.pdf', '2026-03-15 09:00:00', $owner);
		$this->attach($theirs, 'owners.pdf', '2026-03-15 09:00:00', $owner);
		// A row with the caller's id on another user's transaction still
		// isn't theirs to list: the receipts endpoint 404s on that account
		$this->attach($theirs, 'stray.pdf', '2026-03-15 09:00:00');

		$mapper = $this->service(AttachmentMapper::class);

		$this->assertSame([$mine => ['mine.pdf']], $mapper->receiptNames([$mine, $theirs], $this->userId));
		$this->assertSame([$theirs => ['owners.pdf']], $mapper->receiptNames([$mine, $theirs], $owner));
	}
}

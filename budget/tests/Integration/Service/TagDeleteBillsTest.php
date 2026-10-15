<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A deleted tag stayed in bills' tag lists and was linked to every payment
 * they booked afterwards. Those rows showed as untagged, but a report filtered
 * by tag with "Include untagged" ticked left them out.
 */
class TagDeleteBillsTest extends IntegrationTestCase {
	private int $accountId;

	protected function setUp(): void {
		parent::setUp();
		$this->accountId = $this->makeAccount()->getId();
	}

	public function testDeletingAGlobalTagTakesItOffBills(): void {
		$doomed = $this->makeTag(null);
		$kept = $this->makeTag(null);
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Power', 'amount' => '50.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'is_active' => true, 'created_at' => $this->now(), 'due_day' => 1,
			'tag_ids' => json_encode([$doomed, $kept]),
		]);

		$this->service(TagSetService::class)->deleteGlobalTag($doomed, $this->userId);

		$this->assertSame([$kept], json_decode($this->fetchRow('budget_bills', $bill)['tag_ids'], true));
	}

	public function testARowLinkedOnlyToADeletedTagCountsAsUntagged(): void {
		$live = $this->makeTag(null);
		$this->makeTransaction($this->accountId, ['amount' => '20.00', 'date' => '2026-03-05']);
		$deadLinked = $this->makeTransaction($this->accountId, ['amount' => '30.00', 'date' => '2026-03-06']);
		// A link to a tag id that no longer exists, as bills wrote before
		$this->tagTransaction($deadLinked, 2147480002);

		$rows = $this->service(TransactionReportQueries::class)
			->getCashFlowByMonth($this->userId, null, '2026-03-01', '2026-03-31', [$live], true);

		$this->assertEqualsWithDelta(50.0, (float)$rows[0]['expenses'], 0.001);
	}
}

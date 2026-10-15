<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ReportService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The dashboard summary's spending by category, which the Spending by
 * Category and Top Categories tiles draw on first load, and the tag
 * dimensions report convert other currencies like the Spending report.
 * They summed as stored, so a tile showed one total on load and another
 * once its settings were touched and it refreshed from the Spending report.
 */
class SummarySpendingCurrencyTest extends IntegrationTestCase {
	private const START = '2025-06-01';
	private const END = '2025-06-30';

	private int $food;
	private int $tag;

	protected function setUp(): void {
		parent::setUp();
		$this->service(SettingService::class)->set($this->userId, 'default_currency', 'GBP');
		// 1 EUR = 0.85 GBP
		$this->insertRow('budget_manual_rates', [
			'user_id' => $this->userId, 'currency' => 'GBP', 'rate_per_eur' => '0.8500000000', 'updated_at' => $this->now(),
		]);
		$pounds = $this->makeAccount(['name' => 'Current'])->getId();
		$euros = $this->makeAccount(['name' => 'Euro card', 'currency' => 'EUR'])->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
		$this->tag = $this->makeTag($this->makeTagSet($this->food));

		$this->tagTransaction($this->makeTransaction($pounds, ['category_id' => $this->food, 'amount' => '100.00', 'date' => '2025-06-02']), $this->tag);
		$this->tagTransaction($this->makeTransaction($euros, ['category_id' => $this->food, 'amount' => '100.00', 'date' => '2025-06-04']), $this->tag);
		$this->makeTransaction($euros, ['category_id' => $this->food, 'amount' => '20.00', 'date' => '2025-06-05']);
	}

	public function testTheSummarysSpendingMatchesTheSpendingReport(): void {
		$reports = $this->service(ReportService::class);

		$summary = array_column($reports->generateSummary($this->userId, self::START, self::END)['spending'], 'total', 'id');
		$report = array_column($reports->getSpendingReport($this->userId, self::START, self::END)['data'], 'total', 'id');

		// 100 pounds and 120 euros (102 pounds)
		$this->assertEqualsWithDelta(202.0, $summary[$this->food], 0.001);
		$this->assertEqualsWithDelta($report[$this->food], $summary[$this->food], 0.001);
	}

	public function testTagDimensionsAreInPounds(): void {
		$dimensions = $this->service(ReportService::class)
			->getTagDimensions($this->userId, self::START, self::END)['categories'];

		$this->assertCount(1, $dimensions);
		$this->assertEqualsWithDelta(202.0, $dimensions[0]['categoryTotal'], 0.001);
		$tags = $dimensions[0]['tagDimensions'][0]['tags'];
		$this->assertCount(1, $tags);
		$this->assertSame($this->tag, $tags[0]['tagId']);
		$this->assertEqualsWithDelta(185.0, $tags[0]['total'], 0.001);
		$this->assertSame(2, $tags[0]['count']);
	}
}

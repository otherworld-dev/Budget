<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Report\TagReportService;
use PHPUnit\Framework\TestCase;

class TagReportServiceTest extends TestCase {
    private TagReportService $service;
    private TransactionMapper $transactionMapper;
    private TagSetMapper $tagSetMapper;
    private TagMapper $tagMapper;

    protected function setUp(): void {
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->tagSetMapper = $this->createMock(TagSetMapper::class);
        $this->tagMapper = $this->createMock(TagMapper::class);

        $this->service = new TagReportService(
            $this->transactionMapper,
            $this->tagSetMapper,
            $this->tagMapper
        );
    }

    private function makeTagSet(int $id, string $name, ?string $description = null): TagSet {
        $ts = new TagSet();
        $ts->setId($id);
        $ts->setName($name);
        $ts->setDescription($description);
        return $ts;
    }

    private function makeTag(int $id, string $name, ?string $color = null): Tag {
        $tag = new Tag();
        $tag->setId($id);
        $tag->setName($name);
        $tag->setColor($color);
        return $tag;
    }

    // ===== getTagCombinationReport =====

    public function testGetTagCombinationReportDelegatesToMapper(): void {
        $combinations = [
            ['tags' => [1, 2], 'total' => 500.0, 'count' => 10],
        ];
        $this->transactionMapper->expects($this->once())->method('getSpendingByTagCombination')
            ->with('user1', '2025-01-01', '2025-12-31', null, null, 2, 50)
            ->willReturn($combinations);

        $result = $this->service->getTagCombinationReport('user1', '2025-01-01', '2025-12-31');

        $this->assertEquals('2025-01-01', $result['period']['startDate']);
        $this->assertEquals('2025-12-31', $result['period']['endDate']);
        $this->assertEquals(2, $result['minCombinationSize']);
        $this->assertCount(1, $result['combinations']);
        $this->assertEquals(1, $result['totalCombinations']);
    }

    public function testGetTagCombinationReportWithFilters(): void {
        $this->transactionMapper->expects($this->once())->method('getSpendingByTagCombination')
            ->with('user1', '2025-01-01', '2025-06-30', 5, 10, 3, 25);

        $this->service->getTagCombinationReport('user1', '2025-01-01', '2025-06-30', 5, 10, 3, 25);
    }

    // ===== getCrossTabulation =====

    public function testGetCrossTabulationBuildsMatrix(): void {
        $tagSet1 = $this->makeTagSet(1, 'Priority');
        $tagSet2 = $this->makeTagSet(2, 'Type');

        $this->tagSetMapper->method('find')->willReturnMap([
            [1, 'user1', $tagSet1],
            [2, 'user1', $tagSet2],
        ]);

        $this->transactionMapper->method('getTagCrossTabulation')->willReturn([
            'rows' => [['id' => 10, 'name' => 'High']],
            'columns' => [['id' => 20, 'name' => 'Recurring']],
            'data' => [
                ['rowTagId' => 10, 'colTagId' => 20, 'total' => 300.0, 'count' => 5],
            ],
        ]);

        $result = $this->service->getCrossTabulation('user1', 1, 2, '2025-01-01', '2025-12-31');

        $this->assertEquals('Priority', $result['tagSet1']['name']);
        $this->assertEquals('Type', $result['tagSet2']['name']);
        $this->assertEquals(300.0, $result['matrix'][10][20]['total']);
        $this->assertEquals(5, $result['matrix'][10][20]['count']);
        $this->assertEquals(300.0, $result['rowTotals'][10]);
        $this->assertEquals(300.0, $result['columnTotals'][20]);
        $this->assertEquals(300.0, $result['grandTotal']);
    }

    public function testGetCrossTabulationMultipleCells(): void {
        $this->tagSetMapper->method('find')->willReturnCallback(function ($id) {
            return $this->makeTagSet($id, "Set{$id}");
        });

        $this->transactionMapper->method('getTagCrossTabulation')->willReturn([
            'rows' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']],
            'columns' => [['id' => 3, 'name' => 'X'], ['id' => 4, 'name' => 'Y']],
            'data' => [
                ['rowTagId' => 1, 'colTagId' => 3, 'total' => 100.0, 'count' => 2],
                ['rowTagId' => 1, 'colTagId' => 4, 'total' => 200.0, 'count' => 3],
                ['rowTagId' => 2, 'colTagId' => 3, 'total' => 150.0, 'count' => 4],
            ],
        ]);

        $result = $this->service->getCrossTabulation('user1', 1, 2, '2025-01-01', '2025-12-31');

        // Row totals: row 1 = 100+200=300, row 2 = 150
        $this->assertEquals(300.0, $result['rowTotals'][1]);
        $this->assertEquals(150.0, $result['rowTotals'][2]);
        // Column totals: col 3 = 100+150=250, col 4 = 200
        $this->assertEquals(250.0, $result['columnTotals'][3]);
        $this->assertEquals(200.0, $result['columnTotals'][4]);
        $this->assertEquals(450.0, $result['grandTotal']);
    }

    // ===== getTagTrendReport =====

    public function testGetTagTrendReportEmptyTagIds(): void {
        $result = $this->service->getTagTrendReport('user1', [], '2025-01-01', '2025-06-30');

        $this->assertEmpty($result['tags']);
        $this->assertEmpty($result['trends']);
    }

    public function testGetTagTrendReportOrganizesByTag(): void {
        $tag1 = $this->makeTag(1, 'Essential', '#ff0000');
        $tag2 = $this->makeTag(2, 'Optional', '#00ff00');

        $this->tagMapper->method('findByIds')->willReturn([1 => $tag1, 2 => $tag2]);
        $this->transactionMapper->method('getTagTrendByMonth')->willReturn([
            ['tagId' => 1, 'month' => '2025-01', 'total' => 500.0],
            ['tagId' => 1, 'month' => '2025-02', 'total' => 600.0],
            ['tagId' => 2, 'month' => '2025-01', 'total' => 200.0],
        ]);

        $result = $this->service->getTagTrendReport('user1', [1, 2], '2025-01-01', '2025-02-28');

        $this->assertCount(2, $result['tags']);
        $tag1Data = $result['tags'][0];
        $this->assertEquals('Essential', $tag1Data['tagName']);
        $this->assertEquals('#ff0000', $tag1Data['color']);
        // Should have 2 months of trend data
        $this->assertCount(2, $tag1Data['trend']);
    }

    public function testGetTagTrendReportZeroFillsMissingMonths(): void {
        $tag = $this->makeTag(1, 'Test', null);

        $this->tagMapper->method('findByIds')->willReturn([1 => $tag]);
        // Only has data for January, not February
        $this->transactionMapper->method('getTagTrendByMonth')->willReturn([
            ['tagId' => 1, 'month' => '2025-01', 'total' => 100.0],
        ]);

        $result = $this->service->getTagTrendReport('user1', [1], '2025-01-01', '2025-02-28');

        $trend = $result['tags'][0]['trend'];
        $this->assertCount(2, $trend);
        $this->assertEquals(100.0, $trend[0]['total']);
        $this->assertEquals(0, $trend[1]['total']); // Zero-filled
    }

    // ===== getTagSetBreakdown =====

    public function testGetTagSetBreakdownWithPercentages(): void {
        $tagSet = $this->makeTagSet(1, 'Priority', 'Expense priority');
        $this->tagSetMapper->method('find')->willReturn($tagSet);

        $this->transactionMapper->method('getSpendingByTag')->willReturn([
            ['tagId' => 1, 'name' => 'Essential', 'total' => 750.0, 'count' => 15],
            ['tagId' => 2, 'name' => 'Optional', 'total' => 250.0, 'count' => 5],
        ]);

        $result = $this->service->getTagSetBreakdown('user1', 1, '2025-01-01', '2025-12-31');

        $this->assertEquals('Priority', $result['tagSet']['name']);
        $this->assertEquals('Expense priority', $result['tagSet']['description']);
        $this->assertEquals(1000.0, $result['totals']['amount']);
        $this->assertEquals(20, $result['totals']['transactions']);
        $this->assertCount(2, $result['tags']);
        $this->assertEquals(75.0, $result['tags'][0]['percentage']);
        $this->assertEquals(25.0, $result['tags'][1]['percentage']);
    }

    public function testGetTagSetBreakdownZeroTotalPercentage(): void {
        $tagSet = $this->makeTagSet(1, 'Empty');
        $this->tagSetMapper->method('find')->willReturn($tagSet);

        $this->transactionMapper->method('getSpendingByTag')->willReturn([
            ['tagId' => 1, 'name' => 'Tag', 'total' => 0.0, 'count' => 0],
        ]);

        $result = $this->service->getTagSetBreakdown('user1', 1, '2025-01-01', '2025-12-31');

        $this->assertEquals(0, $result['tags'][0]['percentage']);
    }
}

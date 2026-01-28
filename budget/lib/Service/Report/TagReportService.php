<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TagMapper;

/**
 * Handles dedicated tag-based reporting and analysis.
 */
class TagReportService {
    private TransactionMapper $transactionMapper;
    private TagSetMapper $tagSetMapper;
    private TagMapper $tagMapper;

    public function __construct(
        TransactionMapper $transactionMapper,
        TagSetMapper $tagSetMapper,
        TagMapper $tagMapper
    ) {
        $this->transactionMapper = $transactionMapper;
        $this->tagSetMapper = $tagSetMapper;
        $this->tagMapper = $tagMapper;
    }

    /**
     * Get spending by tag combinations.
     * Shows how much is spent on specific combinations of tags.
     *
     * @param string $userId
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @param int $minCombinationSize Minimum number of tags (default 2)
     * @param int $limit Maximum results
     * @return array
     */
    public function getTagCombinationReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null,
        int $minCombinationSize = 2,
        int $limit = 50
    ): array {
        $combinations = $this->transactionMapper->getSpendingByTagCombination(
            $userId,
            $startDate,
            $endDate,
            $accountId,
            $categoryId,
            $minCombinationSize,
            $limit
        );

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate],
            'minCombinationSize' => $minCombinationSize,
            'combinations' => $combinations,
            'totalCombinations' => count($combinations)
        ];
    }

    /**
     * Get cross-tabulation (pivot table) of two tag sets.
     * Creates a matrix showing spending across two dimensions.
     *
     * @param string $userId
     * @param int $tagSetId1 First tag set (rows)
     * @param int $tagSetId2 Second tag set (columns)
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @return array
     */
    public function getCrossTabulation(
        string $userId,
        int $tagSetId1,
        int $tagSetId2,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        // Get tag set metadata
        $tagSet1 = $this->tagSetMapper->find($tagSetId1, $userId);
        $tagSet2 = $this->tagSetMapper->find($tagSetId2, $userId);

        // Get cross-tabulation data
        $crossTab = $this->transactionMapper->getTagCrossTabulation(
            $userId,
            $tagSetId1,
            $tagSetId2,
            $startDate,
            $endDate,
            $accountId,
            $categoryId
        );

        // Build the matrix structure for easier consumption
        $matrix = [];
        foreach ($crossTab['data'] as $cell) {
            $rowId = $cell['rowTagId'];
            $colId = $cell['colTagId'];

            if (!isset($matrix[$rowId])) {
                $matrix[$rowId] = [];
            }

            $matrix[$rowId][$colId] = [
                'total' => $cell['total'],
                'count' => $cell['count']
            ];
        }

        // Calculate row and column totals
        $rowTotals = [];
        $colTotals = [];
        $grandTotal = 0;

        foreach ($matrix as $rowId => $columns) {
            $rowTotals[$rowId] = 0;
            foreach ($columns as $colId => $cell) {
                $rowTotals[$rowId] += $cell['total'];
                $colTotals[$colId] = ($colTotals[$colId] ?? 0) + $cell['total'];
                $grandTotal += $cell['total'];
            }
        }

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate],
            'tagSet1' => [
                'id' => $tagSetId1,
                'name' => $tagSet1->getName()
            ],
            'tagSet2' => [
                'id' => $tagSetId2,
                'name' => $tagSet2->getName()
            ],
            'rows' => $crossTab['rows'],
            'columns' => $crossTab['columns'],
            'matrix' => $matrix,
            'rowTotals' => $rowTotals,
            'columnTotals' => $colTotals,
            'grandTotal' => $grandTotal
        ];
    }

    /**
     * Get monthly trend for specific tags.
     * Shows how spending on specific tags changes over time.
     *
     * @param string $userId
     * @param int[] $tagIds Tags to track
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @return array
     */
    public function getTagTrendReport(
        string $userId,
        array $tagIds,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        if (empty($tagIds)) {
            return [
                'period' => ['startDate' => $startDate, 'endDate' => $endDate],
                'tags' => [],
                'trends' => []
            ];
        }

        // Get tag metadata
        $tags = $this->tagMapper->findByIds($tagIds);

        // Get trend data
        $trendData = $this->transactionMapper->getTagTrendByMonth(
            $userId,
            $tagIds,
            $startDate,
            $endDate,
            $accountId
        );

        // Organize data by tag
        $tagTrends = [];
        foreach ($tags as $tagId => $tag) {
            $tagTrends[$tagId] = [
                'tagId' => $tagId,
                'tagName' => $tag->getName(),
                'color' => $tag->getColor(),
                'months' => []
            ];
        }

        foreach ($trendData as $row) {
            $tagId = $row['tagId'];
            if (isset($tagTrends[$tagId])) {
                $tagTrends[$tagId]['months'][$row['month']] = [
                    'total' => $row['total'],
                    'month' => $row['month']
                ];
            }
        }

        // Fill in missing months with zeros
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = new \DateInterval('P1M');

        foreach ($tagTrends as &$tagTrend) {
            $current = clone $start;
            $monthlyData = [];

            while ($current <= $end) {
                $month = $current->format('Y-m');
                $monthlyData[] = [
                    'month' => $month,
                    'label' => $current->format('M Y'),
                    'total' => $tagTrend['months'][$month]['total'] ?? 0
                ];
                $current->add($interval);
            }

            $tagTrend['trend'] = $monthlyData;
            unset($tagTrend['months']); // Remove intermediate structure
        }

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate],
            'tags' => array_values($tagTrends)
        ];
    }

    /**
     * Get spending breakdown by a specific tag set.
     * Shows spending on each tag within a tag set.
     *
     * @param string $userId
     * @param int $tagSetId Tag set to analyze
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @return array
     */
    public function getTagSetBreakdown(
        string $userId,
        int $tagSetId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        // Get tag set metadata
        $tagSet = $this->tagSetMapper->find($tagSetId, $userId);

        // Get spending by tag
        $spending = $this->transactionMapper->getSpendingByTag(
            $userId,
            $tagSetId,
            $startDate,
            $endDate,
            $accountId,
            $categoryId
        );

        // Calculate totals
        $total = 0;
        $count = 0;
        foreach ($spending as $item) {
            $total += $item['total'];
            $count += $item['count'];
        }

        // Calculate percentages
        $spendingWithPercentages = array_map(function ($item) use ($total) {
            $item['percentage'] = $total > 0 ? ($item['total'] / $total) * 100 : 0;
            return $item;
        }, $spending);

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate],
            'tagSet' => [
                'id' => $tagSetId,
                'name' => $tagSet->getName(),
                'description' => $tagSet->getDescription()
            ],
            'tags' => $spendingWithPercentages,
            'totals' => [
                'amount' => $total,
                'transactions' => $count
            ]
        ];
    }
}

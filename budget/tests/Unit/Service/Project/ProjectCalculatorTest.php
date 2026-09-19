<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Project;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\Project;
use OCA\Budget\Db\ProjectAllocation;
use OCA\Budget\Service\Project\ProjectCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectCalculatorTest extends TestCase {
    /** Net spent per category in the window */
    private const SPENDING = [1 => 50.0, 2 => 100.0, 3 => 200.0, 4 => 300.0, 5 => 999.0];

    /**
     * Renovation > Kitchen > Appliances, Renovation > Bathroom, and Garden
     * outside the project.
     *
     * @param int[] $excluded ids flagged Exclude from reports
     * @return Category[]
     */
    private function categories(array $excluded = []): array {
        return [
            $this->cat(1, 'Renovation', null, $excluded),
            $this->cat(2, 'Kitchen', 1, $excluded),
            $this->cat(3, 'Appliances', 2, $excluded),
            $this->cat(4, 'Bathroom', 1, $excluded),
            $this->cat(5, 'Garden', null, $excluded),
        ];
    }

    private function cat(int $id, string $name, ?int $parentId, array $excluded): Category {
        $category = new Category();
        $category->setId($id);
        $category->setName($name);
        $category->setType('expense');
        $category->setParentId($parentId);
        $category->setExcludedFromReports(in_array($id, $excluded, true));
        return $category;
    }

    private function project(string $start = '2026-03-01', ?string $end = null, float $total = 900.0): Project {
        $project = new Project();
        $project->setId(10);
        $project->setCategoryId(1);
        $project->setTotalAmount($total);
        $project->setStartDate($start);
        $project->setEndDate($end);
        return $project;
    }

    private function alloc(int $categoryId, float $amount): ProjectAllocation {
        $allocation = new ProjectAllocation();
        $allocation->setProjectId(10);
        $allocation->setCategoryId($categoryId);
        $allocation->setAmount($amount);
        return $allocation;
    }

    public function testBranchCoversEverythingUnderTheCategory(): void {
        $map = ProjectCalculator::childrenMap($this->categories());
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], ProjectCalculator::branchIds(1, $map));
    }

    public function testAnExcludedSubcategoryDropsOutWithEverythingUnderIt(): void {
        $map = ProjectCalculator::childrenMap($this->categories([2]));
        $this->assertEqualsCanonicalizing([1, 4], ProjectCalculator::branchIds(1, $map));
    }

    public function testTheCategoryItselfAlwaysStays(): void {
        $map = ProjectCalculator::childrenMap($this->categories([1]));
        $this->assertContains(1, ProjectCalculator::branchIds(1, $map));
    }

    #[DataProvider('statuses')]
    public function testStatus(string $today, ?string $end, string $expected): void {
        $this->assertSame($expected, ProjectCalculator::status('2026-03-01', $end, $today));
    }

    public static function statuses(): array {
        return [
            'before the start' => ['2026-02-28', null, 'upcoming'],
            'on the start day' => ['2026-03-01', null, 'active'],
            'no end date' => ['2030-01-01', null, 'active'],
            'on the end day' => ['2026-06-30', '2026-06-30', 'active'],
            'after the end' => ['2026-07-01', '2026-06-30', 'finished'],
        ];
    }

    public function testWindowRunsFromTheStartToTodayOrTheEnd(): void {
        $this->assertNull(ProjectCalculator::window('2026-03-01', null, '2026-02-28'));
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-09-19'], ProjectCalculator::window('2026-03-01', null, '2026-09-19'));
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-06-30'], ProjectCalculator::window('2026-03-01', '2026-06-30', '2026-09-19'));
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-05-10'], ProjectCalculator::window('2026-03-01', '2026-06-30', '2026-05-10'));
    }

    public function testTimeElapsedCountsWholeDaysWithBothEnds(): void {
        $this->assertNull(ProjectCalculator::timeElapsed('2026-01-01', null, '2026-01-05'));
        $this->assertSame(0.0, ProjectCalculator::timeElapsed('2026-01-01', '2026-01-10', '2025-12-31'));
        $this->assertSame(0.5, ProjectCalculator::timeElapsed('2026-01-01', '2026-01-10', '2026-01-05'));
        $this->assertSame(1.0, ProjectCalculator::timeElapsed('2026-01-01', '2026-01-10', '2026-03-01'));
    }

    public function testQueryIdsIncludeTheBranchOfAnAmountMovedOutOfTheProject(): void {
        $map = ProjectCalculator::childrenMap($this->categories());
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], ProjectCalculator::queryIds(1, [$this->alloc(5, 100.0)], $map));
    }

    public function testSummaryTotalsTheWholeBranch(): void {
        $s = ProjectCalculator::summarise($this->project(), [], $this->categories(), self::SPENDING, '2026-09-19');

        $this->assertSame(650.0, $s['spent']);
        $this->assertSame(250.0, $s['remaining']);
        $this->assertSame(72.2, $s['percentage']);
        $this->assertSame(50.0, $s['directSpent']);
        $this->assertSame('active', $s['status']);
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-09-19'], $s['window']);
        $this->assertNull($s['timeElapsed']);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $s['branchCategoryIds']);
        $this->assertSame(0.0, $s['allocated']);
        $this->assertSame(900.0, $s['unallocated']);
    }

    public function testBreakdownHasARowPerSubcategoryWithItsAmount(): void {
        $s = ProjectCalculator::summarise($this->project(), [$this->alloc(2, 400.0)], $this->categories(), self::SPENDING, '2026-09-19');

        $this->assertSame(['Bathroom', 'Kitchen'], array_column($s['breakdown'], 'name'));
        [$bathroom, $kitchen] = $s['breakdown'];
        $this->assertNull($bathroom['allocation']);
        $this->assertSame(300.0, $bathroom['spent']);
        $this->assertNull($bathroom['remaining']);
        $this->assertNull($bathroom['percentage']);
        $this->assertSame(400.0, $kitchen['allocation']);
        // Kitchen's own 100 plus Appliances' 200
        $this->assertSame(300.0, $kitchen['spent']);
        $this->assertSame(100.0, $kitchen['remaining']);
        $this->assertSame(75.0, $kitchen['percentage']);
        $this->assertFalse($kitchen['outsideProject']);
        $this->assertSame(400.0, $s['allocated']);
        $this->assertSame(500.0, $s['unallocated']);
    }

    public function testANestedAmountGetsItsOwnRowUnderItsSubcategory(): void {
        $s = ProjectCalculator::summarise($this->project(), [$this->alloc(3, 250.0)], $this->categories(), self::SPENDING, '2026-09-19');

        $this->assertSame(['Bathroom', 'Kitchen', 'Appliances'], array_column($s['breakdown'], 'name'));
        $this->assertSame([0, 0, 1], array_column($s['breakdown'], 'depth'));
        $this->assertNull($s['breakdown'][1]['allocation']);
        $this->assertSame(300.0, $s['breakdown'][1]['spent']);
        $this->assertSame(250.0, $s['breakdown'][2]['allocation']);
        $this->assertSame(200.0, $s['breakdown'][2]['spent']);
    }

    public function testAnAmountOutsideTheProjectIsListedButNotCounted(): void {
        $s = ProjectCalculator::summarise($this->project(), [$this->alloc(5, 100.0)], $this->categories(), self::SPENDING, '2026-09-19');

        $this->assertSame(650.0, $s['spent']);
        $row = $s['breakdown'][2];
        $this->assertSame('Garden', $row['name']);
        $this->assertTrue($row['outsideProject']);
        $this->assertSame(999.0, $row['spent']);
    }

    public function testASubcategoryExcludedFromReportsDropsOut(): void {
        $s = ProjectCalculator::summarise($this->project(), [], $this->categories([4]), self::SPENDING, '2026-09-19');

        $this->assertSame(350.0, $s['spent']);
        $this->assertSame(['Kitchen'], array_column($s['breakdown'], 'name'));
    }

    public function testSubcategoriesAreListedInTreeOrderForTheForm(): void {
        $s = ProjectCalculator::summarise($this->project(), [], $this->categories(), [], '2026-09-19');

        $this->assertSame([
            ['id' => 4, 'name' => 'Bathroom', 'depth' => 1],
            ['id' => 2, 'name' => 'Kitchen', 'depth' => 1],
            ['id' => 3, 'name' => 'Appliances', 'depth' => 2],
        ], $s['subcategories']);
    }

    public function testAnUpcomingProjectHasNoWindowAndNothingSpent(): void {
        $s = ProjectCalculator::summarise($this->project('2026-12-01'), [], $this->categories(), [], '2026-09-19');

        $this->assertSame('upcoming', $s['status']);
        $this->assertNull($s['window']);
        $this->assertSame(0.0, $s['spent']);
    }

    public function testTimeElapsedIsReportedWithAnEndDate(): void {
        $s = ProjectCalculator::summarise($this->project('2026-01-01', '2026-01-10'), [], $this->categories(), [], '2026-01-05');
        $this->assertSame(0.5, $s['timeElapsed']);
    }
}

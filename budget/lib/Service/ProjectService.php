<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use DateTime;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Project;
use OCA\Budget\Db\ProjectAllocation;
use OCA\Budget\Db\ProjectAllocationMapper;
use OCA\Budget\Db\ProjectMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Project\ProjectCalculator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Project budgets (#391): one total over a date range for a category and
 * everything under it. Validation and storage live here; the figures come
 * from ProjectCalculator over TransactionMapper::getCategorySpendingBatch(),
 * the same spending the monthly Budget page uses.
 */
class ProjectService {
    /** Why a project was refused, as the exception code; the controller words each one */
    public const ERR_NAME = 1;
    public const ERR_TOTAL = 2;
    public const ERR_CATEGORY = 3;
    public const ERR_START_DATE = 4;
    public const ERR_END_DATE = 5;
    public const ERR_ALLOC_AMOUNT = 6;
    public const ERR_ALLOC_OUTSIDE = 7;
    public const ERR_ALLOC_DUPLICATE = 8;
    public const ERR_ALLOC_OVERLAP = 9;
    public const ERR_ALLOC_OVER_TOTAL = 10;

    /** @var array<string, Category[]> owner => categories, for this request */
    private array $categoryCache = [];

    public function __construct(
        private ProjectMapper $projectMapper,
        private ProjectAllocationMapper $allocationMapper,
        private CategoryMapper $categoryMapper,
        private TransactionMapper $transactionMapper,
        private UserClock $userClock,
        private IDBConnection $db,
        private ?AutoShareService $autoShareService = null,
        private ?ShareItemMapper $shareItemMapper = null,
    ) {
    }

    /**
     * @return array[] the user's own projects, each summarised
     */
    public function listOwn(string $userId): array {
        return array_map(fn (Project $project) => $this->summarise($project), $this->projectMapper->findAll($userId));
    }

    /**
     * @param int[] $ids already authorised by GranularShareService
     * @return array[]
     */
    public function listByIds(array $ids): array {
        return array_map(fn (Project $project) => $this->summarise($project), $this->projectMapper->findByIds($ids));
    }

    /**
     * @throws DoesNotExistException
     */
    public function get(int $id, string $ownerUserId): array {
        return $this->summarise($this->projectMapper->find($id, $ownerUserId));
    }

    /**
     * @throws \InvalidArgumentException coded with an ERR_* constant
     */
    public function create(string $userId, array $input): array {
        $categories = $this->categories($userId);
        $fields = $this->validate($input, $categories);

        $project = new Project();
        $project->setUserId($userId);
        $this->apply($project, $fields);
        $project->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        $this->db->beginTransaction();
        try {
            $project = $this->projectMapper->insert($project);
            $this->saveAllocations($project, $fields['allocations']);
            if (!empty($input['excludeFromBudget'])) {
                $this->excludeFromMonthlyBudgets($fields['categoryId'], $categories);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->autoShareService?->autoShareNewEntity($userId, ShareItem::TYPE_PROJECT, $project->getId());

        return $this->summarise($project);
    }

    /**
     * Keys missing from $input keep their current value; an endDate sent as
     * null clears it. Someone the project is shared with at Read & Write
     * ($asOwner false) cannot move it to another category, and the category's
     * Exclude from budgeting flag is only ever set when a project is created.
     *
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException coded with an ERR_* constant
     */
    public function update(int $id, string $ownerUserId, array $input, bool $asOwner): array {
        $project = $this->projectMapper->find($id, $ownerUserId);

        $input += [
            'name' => $project->getName(),
            'totalAmount' => $project->getTotalAmount(),
            'startDate' => Project::day($project->getStartDate()),
            'endDate' => Project::day($project->getEndDate()),
            'allocations' => array_map(
                static fn (ProjectAllocation $a) => ['categoryId' => $a->getCategoryId(), 'amount' => $a->getAmount()],
                $this->allocationMapper->findByProject($id)
            ),
        ];
        if (!$asOwner || !isset($input['categoryId'])) {
            $input['categoryId'] = $project->getCategoryId();
        }

        $fields = $this->validate($input, $this->categories($ownerUserId));
        $this->apply($project, $fields);

        $this->db->beginTransaction();
        try {
            $project = $this->projectMapper->update($project);
            $this->saveAllocations($project, $fields['allocations']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->summarise($project);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $ownerUserId): void {
        $project = $this->projectMapper->find($id, $ownerUserId);

        $this->db->beginTransaction();
        try {
            $this->allocationMapper->deleteByProject($id);
            $this->projectMapper->delete($project);
            $this->shareItemMapper?->deleteByEntity(ShareItem::TYPE_PROJECT, $id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function summarise(Project $project): array {
        $owner = $project->getUserId();
        $categories = $this->categories($owner);
        $allocations = $this->allocationMapper->findByProject($project->getId());
        $today = $this->userClock->today($owner);

        $window = ProjectCalculator::window(
            (string)Project::day($project->getStartDate()),
            Project::day($project->getEndDate()),
            $today
        );
        $spending = [];
        if ($window !== null) {
            $ids = ProjectCalculator::queryIds(
                $project->getCategoryId(),
                $allocations,
                ProjectCalculator::childrenMap($categories)
            );
            $spending = $this->transactionMapper->getCategorySpendingBatch($ids, $window['from'], $window['to'], 'debit');
        }

        $categoryName = null;
        foreach ($categories as $category) {
            if ($category->getId() === $project->getCategoryId()) {
                $categoryName = $category->getName();
            }
        }

        return $project->jsonSerialize() + [
            'categoryName' => $categoryName,
            'allocations' => array_map(
                static fn (ProjectAllocation $a) => ['categoryId' => $a->getCategoryId(), 'amount' => $a->getAmount()],
                $allocations
            ),
        ] + ProjectCalculator::summarise($project, $allocations, $categories, $spending, $today);
    }

    /**
     * @param Category[] $categories the owner's categories
     * @return array{name: string, categoryId: int, totalAmount: float, startDate: string, endDate: ?string, allocations: array<int, float>}
     */
    private function validate(array $input, array $categories): array {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Enter a name for the project', self::ERR_NAME);
        }

        $total = $input['totalAmount'] ?? null;
        if (!is_numeric($total) || MoneyCalculator::compare((float)$total, '0') <= 0) {
            throw new \InvalidArgumentException('The total must be more than zero', self::ERR_TOTAL);
        }
        $total = MoneyCalculator::toFloat(MoneyCalculator::add((float)$total, '0'));

        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->getId()] = $category;
        }
        $categoryId = (int)($input['categoryId'] ?? 0);
        $category = $byId[$categoryId] ?? null;
        if ($category === null || $category->getType() !== 'expense') {
            throw new \InvalidArgumentException('Choose one of your own expense categories', self::ERR_CATEGORY);
        }

        $start = self::parseDay($input['startDate'] ?? null);
        if ($start === null) {
            throw new \InvalidArgumentException('Enter a start date', self::ERR_START_DATE);
        }
        $end = null;
        $endInput = $input['endDate'] ?? null;
        if ($endInput !== null && $endInput !== '') {
            $end = self::parseDay($endInput);
            if ($end === null || $end < $start) {
                throw new \InvalidArgumentException('The end date cannot be before the start date', self::ERR_END_DATE);
            }
        }

        $branch = array_flip(ProjectCalculator::branchIds($categoryId, ProjectCalculator::childrenMap($categories)));
        $allocations = [];
        foreach ((array)($input['allocations'] ?? []) as $allocation) {
            $allocation = is_array($allocation) ? $allocation : [];
            $allocationCategoryId = (int)($allocation['categoryId'] ?? 0);
            $amount = $allocation['amount'] ?? null;
            if (!is_numeric($amount) || MoneyCalculator::compare((float)$amount, '0') <= 0) {
                throw new \InvalidArgumentException('Every subcategory amount must be more than zero', self::ERR_ALLOC_AMOUNT);
            }
            if ($allocationCategoryId === $categoryId || !isset($branch[$allocationCategoryId])) {
                throw new \InvalidArgumentException('Amounts can only go to subcategories of the project category', self::ERR_ALLOC_OUTSIDE);
            }
            if (isset($allocations[$allocationCategoryId])) {
                throw new \InvalidArgumentException('Each subcategory can only have one amount', self::ERR_ALLOC_DUPLICATE);
            }
            $allocations[$allocationCategoryId] = MoneyCalculator::toFloat(MoneyCalculator::add((float)$amount, '0'));
        }

        // Kitchen and Kitchen > Appliances would both claim the appliances
        // spending, and Unallocated would count it twice
        foreach (array_keys($allocations) as $allocationCategoryId) {
            $parentId = $byId[$allocationCategoryId]->getParentId();
            while ($parentId !== null && $parentId !== $categoryId) {
                if (isset($allocations[$parentId])) {
                    throw new \InvalidArgumentException('A subcategory and its own subcategory cannot both have amounts', self::ERR_ALLOC_OVERLAP);
                }
                $parentId = ($byId[$parentId] ?? null)?->getParentId();
            }
        }

        if (MoneyCalculator::compare(MoneyCalculator::sum(array_values($allocations)), $total) > 0) {
            throw new \InvalidArgumentException('The subcategory amounts add up to more than the total', self::ERR_ALLOC_OVER_TOTAL);
        }

        return [
            'name' => $name,
            'categoryId' => $categoryId,
            'totalAmount' => $total,
            'startDate' => $start,
            'endDate' => $end,
            'allocations' => $allocations,
        ];
    }

    private static function parseDay(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return ($date !== false && $date->format('Y-m-d') === $value) ? $value : null;
    }

    private function apply(Project $project, array $fields): void {
        $project->setName($fields['name']);
        $project->setCategoryId($fields['categoryId']);
        $project->setTotalAmount($fields['totalAmount']);
        $project->setStartDate($fields['startDate']);
        $project->setEndDate($fields['endDate']);
        $project->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));
    }

    /**
     * Replace the project's allocations with $amounts (category id => amount).
     *
     * @param array<int, float> $amounts
     */
    private function saveAllocations(Project $project, array $amounts): void {
        $this->allocationMapper->deleteByProject($project->getId());
        foreach ($amounts as $categoryId => $amount) {
            $allocation = new ProjectAllocation();
            $allocation->setUserId($project->getUserId());
            $allocation->setProjectId($project->getId());
            $allocation->setCategoryId($categoryId);
            $allocation->setAmount($amount);
            $this->allocationMapper->insert($allocation);
        }
    }

    /**
     * Tick the category's existing Exclude from budgeting flag, which
     * BudgetScope already carries down to its subcategories, so a big
     * project bill is not "over budget" on the monthly Budget page.
     *
     * @param Category[] $categories
     */
    private function excludeFromMonthlyBudgets(int $categoryId, array $categories): void {
        foreach ($categories as $category) {
            if ($category->getId() === $categoryId && !$category->getExcludedFromBudget()) {
                $category->setExcludedFromBudget(true);
                $this->categoryMapper->update($category);
            }
        }
    }

    /**
     * @return Category[]
     */
    private function categories(string $owner): array {
        return $this->categoryCache[$owner] ??= $this->categoryMapper->findAll($owner);
    }
}

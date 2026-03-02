<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\CategoryService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class CategoryServiceTest extends TestCase {
    private CategoryService $service;
    private CategoryMapper $categoryMapper;
    private TransactionMapper $transactionMapper;
    private TagSetMapper $tagSetMapper;
    private TagMapper $tagMapper;
    private TransactionTagMapper $transactionTagMapper;

    protected function setUp(): void {
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->tagSetMapper = $this->createMock(TagSetMapper::class);
        $this->tagMapper = $this->createMock(TagMapper::class);
        $this->transactionTagMapper = $this->createMock(TransactionTagMapper::class);

        $this->service = new CategoryService(
            $this->categoryMapper,
            $this->transactionMapper,
            $this->tagSetMapper,
            $this->tagMapper,
            $this->transactionTagMapper
        );
    }

    private function makeCategory(array $overrides = []): Category {
        $category = new Category();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Food',
            'type' => 'expense',
            'parentId' => null,
            'icon' => 'icon-food',
            'color' => '#ff0000',
            'budgetAmount' => null,
            'sortOrder' => 0,
        ];
        $data = array_merge($defaults, $overrides);

        $category->setId($data['id']);
        $category->setUserId($data['userId']);
        $category->setName($data['name']);
        $category->setType($data['type']);
        $category->setParentId($data['parentId']);
        $category->setIcon($data['icon']);
        $category->setColor($data['color']);
        $category->setBudgetAmount($data['budgetAmount']);
        $category->setSortOrder($data['sortOrder']);
        $category->setCreatedAt('2026-01-01 00:00:00');
        $category->setUpdatedAt('2026-01-01 00:00:00');
        return $category;
    }

    private function makeTagSet(int $id, int $categoryId): TagSet {
        $ts = new TagSet();
        $ts->setId($id);
        $ts->setCategoryId($categoryId);
        $ts->setName('TagSet ' . $id);
        return $ts;
    }

    private function makeTag(int $id, int $tagSetId): Tag {
        $tag = new Tag();
        $tag->setId($id);
        $tag->setTagSetId($tagSetId);
        $tag->setName('Tag ' . $id);
        return $tag;
    }

    // ===== create() =====

    public function testCreateBasicCategory(): void {
        $this->categoryMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Category $cat) {
                $this->assertEquals('user1', $cat->getUserId());
                $this->assertEquals('Food', $cat->getName());
                $this->assertEquals('expense', $cat->getType());
                $this->assertNull($cat->getParentId());
                $cat->setId(1);
                return $cat;
            });

        $result = $this->service->create('user1', 'Food', 'expense');
        $this->assertEquals('Food', $result->getName());
    }

    public function testCreateWithParentValidatesOwnership(): void {
        $parent = $this->makeCategory(['id' => 10]);
        $this->categoryMapper->expects($this->once())
            ->method('find')
            ->with(10, 'user1')
            ->willReturn($parent);

        $this->categoryMapper->method('insert')->willReturnCallback(function (Category $cat) {
            $this->assertEquals(10, $cat->getParentId());
            $cat->setId(2);
            return $cat;
        });

        $this->service->create('user1', 'Groceries', 'expense', 10);
    }

    public function testCreateWithParentThrowsIfParentNotFound(): void {
        $this->categoryMapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->create('user1', 'Child', 'expense', 999);
    }

    public function testCreateGeneratesColorWhenNotProvided(): void {
        $this->categoryMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Category $cat) {
                // Color should be set (randomly generated)
                $this->assertNotNull($cat->getColor());
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $cat->getColor());
                $cat->setId(1);
                return $cat;
            });

        $this->service->create('user1', 'No Color', 'expense', null, null, null);
    }

    public function testCreateUsesProvidedColor(): void {
        $this->categoryMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Category $cat) {
                $this->assertEquals('#abcdef', $cat->getColor());
                $cat->setId(1);
                return $cat;
            });

        $this->service->create('user1', 'Custom Color', 'expense', null, null, '#abcdef');
    }

    // ===== beforeUpdate() =====

    public function testUpdateRejectsSelfReferentialParent(): void {
        $category = $this->makeCategory(['id' => 5]);
        $this->categoryMapper->method('find')->willReturn($category);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('its own parent');

        $this->service->update(5, 'user1', ['parentId' => 5]);
    }

    public function testUpdateValidatesNewParent(): void {
        $category = $this->makeCategory(['id' => 5]);
        $parent = $this->makeCategory(['id' => 10]);

        // First call returns the category being updated, second validates parent
        $this->categoryMapper->method('find')
            ->willReturnCallback(function (int $id) use ($category, $parent) {
                return $id === 5 ? $category : $parent;
            });

        $this->categoryMapper->method('update')->willReturnArgument(0);

        $result = $this->service->update(5, 'user1', ['parentId' => 10]);
        $this->assertEquals(10, $result->getParentId());
    }

    // ===== beforeDelete() =====

    public function testDeleteCascadesChildrenFirst(): void {
        $parent = $this->makeCategory(['id' => 1]);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1]);

        // find() returns parent when called from delete()
        $this->categoryMapper->method('find')
            ->willReturnCallback(function (int $id) use ($parent, $child) {
                return $id === 1 ? $parent : $child;
            });

        // Parent has one child, child has no children
        $this->categoryMapper->method('findChildren')
            ->willReturnCallback(function (string $userId, int $parentId) use ($child) {
                return $parentId === 1 ? [$child] : [];
            });

        // No transactions on either
        $this->transactionMapper->method('findByCategory')->willReturn([]);
        $this->tagSetMapper->method('findByCategory')->willReturn([]);

        // Should delete child first, then parent
        $deleteOrder = [];
        $this->categoryMapper->method('delete')
            ->willReturnCallback(function (Category $cat) use (&$deleteOrder) {
                $deleteOrder[] = $cat->getId();
                return $cat;
            });

        $this->service->delete(1, 'user1');

        $this->assertEquals([2, 1], $deleteOrder);
    }

    public function testDeleteRejectsWhenTransactionsExist(): void {
        $category = $this->makeCategory();
        $this->categoryMapper->method('find')->willReturn($category);
        $this->categoryMapper->method('findChildren')->willReturn([]);

        $this->transactionMapper->method('findByCategory')
            ->willReturn([['id' => 1]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('existing transactions');

        $this->service->delete(1, 'user1');
    }

    public function testDeleteCascadesTagSetsAndTags(): void {
        $category = $this->makeCategory();
        $this->categoryMapper->method('find')->willReturn($category);
        $this->categoryMapper->method('findChildren')->willReturn([]);
        $this->transactionMapper->method('findByCategory')->willReturn([]);

        $tagSet = $this->makeTagSet(10, 1);
        $tag = $this->makeTag(20, 10);

        $this->tagSetMapper->method('findByCategory')->willReturn([$tagSet]);
        $this->tagMapper->method('findByTagSet')->willReturn([$tag]);

        $this->transactionTagMapper->expects($this->once())
            ->method('deleteByTag')
            ->with(20);

        $this->tagMapper->expects($this->once())
            ->method('delete')
            ->with($tag);

        $this->tagSetMapper->expects($this->once())
            ->method('delete')
            ->with($tagSet);

        $this->categoryMapper->expects($this->once())->method('delete');

        $this->service->delete(1, 'user1');
    }

    // ===== findByType() =====

    public function testFindByTypeDelegatesToMapper(): void {
        $categories = [$this->makeCategory()];
        $this->categoryMapper->expects($this->once())
            ->method('findByType')
            ->with('user1', 'expense')
            ->willReturn($categories);

        $result = $this->service->findByType('user1', 'expense');
        $this->assertCount(1, $result);
    }

    // ===== getCategoryTree() =====

    public function testGetCategoryTreeBuildsHierarchy(): void {
        $parent = $this->makeCategory(['id' => 1, 'name' => 'Food', 'parentId' => null]);
        $child1 = $this->makeCategory(['id' => 2, 'name' => 'Groceries', 'parentId' => 1]);
        $child2 = $this->makeCategory(['id' => 3, 'name' => 'Dining Out', 'parentId' => 1]);

        $this->categoryMapper->method('findAll')->willReturn([$parent, $child1, $child2]);

        $tree = $this->service->getCategoryTree('user1');

        $this->assertCount(1, $tree);
        $this->assertEquals('Food', $tree[0]['name']);
        $this->assertCount(2, $tree[0]['children']);
        $this->assertEquals('Groceries', $tree[0]['children'][0]['name']);
        $this->assertEquals('Dining Out', $tree[0]['children'][1]['name']);
    }

    public function testGetCategoryTreeMultipleRoots(): void {
        $cat1 = $this->makeCategory(['id' => 1, 'name' => 'Food', 'parentId' => null]);
        $cat2 = $this->makeCategory(['id' => 2, 'name' => 'Transport', 'parentId' => null]);

        $this->categoryMapper->method('findAll')->willReturn([$cat1, $cat2]);

        $tree = $this->service->getCategoryTree('user1');

        $this->assertCount(2, $tree);
    }

    public function testGetCategoryTreeEmpty(): void {
        $this->categoryMapper->method('findAll')->willReturn([]);

        $tree = $this->service->getCategoryTree('user1');
        $this->assertEmpty($tree);
    }

    public function testGetCategoryTreeNestedChildren(): void {
        $root = $this->makeCategory(['id' => 1, 'name' => 'Root', 'parentId' => null]);
        $mid = $this->makeCategory(['id' => 2, 'name' => 'Middle', 'parentId' => 1]);
        $leaf = $this->makeCategory(['id' => 3, 'name' => 'Leaf', 'parentId' => 2]);

        $this->categoryMapper->method('findAll')->willReturn([$root, $mid, $leaf]);

        $tree = $this->service->getCategoryTree('user1');

        $this->assertCount(1, $tree);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertCount(1, $tree[0]['children'][0]['children']);
        $this->assertEquals('Leaf', $tree[0]['children'][0]['children'][0]['name']);
    }

    // ===== getCategorySpending() =====

    public function testGetCategorySpendingVerifiesOwnership(): void {
        $category = $this->makeCategory();
        $this->categoryMapper->expects($this->once())
            ->method('find')
            ->with(1, 'user1')
            ->willReturn($category);

        $this->categoryMapper->method('getCategorySpending')->willReturn(250.0);

        $result = $this->service->getCategorySpending(1, 'user1', '2026-01-01', '2026-01-31');
        $this->assertEquals(250.0, $result);
    }

    // ===== getAllCategorySpending() =====

    public function testGetAllCategorySpendingTransformsData(): void {
        $this->transactionMapper->method('getSpendingSummary')
            ->willReturn([
                ['id' => 1, 'total' => -150.50, 'name' => 'Food', 'color' => '#ff0000', 'count' => 10],
                ['id' => 2, 'total' => -80.00, 'name' => 'Transport', 'color' => '#00ff00', 'count' => 5],
            ]);

        $result = $this->service->getAllCategorySpending('user1', '2026-01-01', '2026-01-31');

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['categoryId']);
        $this->assertEquals(150.50, $result[0]['spent']);  // abs value
        $this->assertEquals('Food', $result[0]['name']);
        $this->assertEquals(10, $result[0]['count']);
    }

    // ===== getBudgetAnalysis() =====

    public function testGetBudgetAnalysisCalculatesCorrectly(): void {
        $categories = [
            $this->makeCategory(['id' => 1, 'name' => 'Food', 'budgetAmount' => 500.00]),
            $this->makeCategory(['id' => 2, 'name' => 'Transport', 'budgetAmount' => 200.00]),
            $this->makeCategory(['id' => 3, 'name' => 'No Budget', 'budgetAmount' => null]),
        ];
        $this->categoryMapper->method('findAll')->willReturn($categories);

        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturn([
                1 => 250.0,   // 50% of 500
                2 => 180.0,   // 90% of 200
            ]);

        $result = $this->service->getBudgetAnalysis('user1', '2026-01');

        // Should only include budgeted categories
        $this->assertCount(2, $result);

        // Food: 250/500 = 50% -> good
        $this->assertEquals(500.0, $result[0]['budget']);
        $this->assertEquals(250.0, $result[0]['spent']);
        $this->assertEquals(250.0, $result[0]['remaining']);
        $this->assertEquals(50.0, $result[0]['percentage']);
        $this->assertEquals('good', $result[0]['status']);

        // Transport: 180/200 = 90% -> danger
        $this->assertEquals(90.0, $result[1]['percentage']);
        $this->assertEquals('danger', $result[1]['status']);
    }

    public function testGetBudgetAnalysisStatusThresholds(): void {
        $categories = [
            $this->makeCategory(['id' => 1, 'budgetAmount' => 100.00]),
            $this->makeCategory(['id' => 2, 'budgetAmount' => 100.00]),
            $this->makeCategory(['id' => 3, 'budgetAmount' => 100.00]),
            $this->makeCategory(['id' => 4, 'budgetAmount' => 100.00]),
        ];
        $this->categoryMapper->method('findAll')->willReturn($categories);

        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturn([
                1 => 40.0,    // 40% -> good
                2 => 60.0,    // 60% -> warning
                3 => 95.0,    // 95% -> danger
                4 => 120.0,   // 120% -> over
            ]);

        $result = $this->service->getBudgetAnalysis('user1');

        $this->assertEquals('good', $result[0]['status']);
        $this->assertEquals('warning', $result[1]['status']);
        $this->assertEquals('danger', $result[2]['status']);
        $this->assertEquals('over', $result[3]['status']);
    }

    public function testGetBudgetAnalysisDefaultsToCurrentMonth(): void {
        $this->categoryMapper->method('findAll')->willReturn([]);
        $this->transactionMapper->method('getCategorySpendingBatch')->willReturn([]);

        // Should not throw
        $result = $this->service->getBudgetAnalysis('user1');
        $this->assertIsArray($result);
    }

    // ===== removeDuplicates() =====

    public function testRemoveDuplicatesDeletesSafeDuplicates(): void {
        $original = $this->makeCategory(['id' => 1, 'name' => 'Food', 'type' => 'expense']);
        $duplicate = $this->makeCategory(['id' => 2, 'name' => 'Food', 'type' => 'expense']);

        $this->categoryMapper->method('findAll')->willReturn([$original, $duplicate]);

        // Duplicate has no transactions and no children
        $this->transactionMapper->method('findByCategory')->willReturn([]);
        $this->categoryMapper->method('findChildren')->willReturn([]);

        $this->categoryMapper->expects($this->once())
            ->method('delete')
            ->with($duplicate);

        $result = $this->service->removeDuplicates('user1');

        $this->assertContains('Food', $result);
    }

    public function testRemoveDuplicatesKeepsDuplicateWithTransactions(): void {
        $original = $this->makeCategory(['id' => 1, 'name' => 'Food', 'type' => 'expense']);
        $duplicate = $this->makeCategory(['id' => 2, 'name' => 'Food', 'type' => 'expense']);

        $this->categoryMapper->method('findAll')->willReturn([$original, $duplicate]);

        // Duplicate has transactions
        $this->transactionMapper->method('findByCategory')
            ->willReturn([['id' => 1]]);

        $this->categoryMapper->expects($this->never())->method('delete');

        $result = $this->service->removeDuplicates('user1');
        $this->assertEmpty($result);
    }

    // ===== deleteAll() =====

    public function testDeleteAllDeletesChildrenBeforeParents(): void {
        $parent = $this->makeCategory(['id' => 1, 'parentId' => null]);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1]);

        $this->categoryMapper->method('findAll')->willReturn([$parent, $child]);
        $this->transactionMapper->method('findByCategory')->willReturn([]);
        $this->categoryMapper->method('findChildren')->willReturn([]);

        $deleteOrder = [];
        $this->categoryMapper->method('delete')
            ->willReturnCallback(function (Category $cat) use (&$deleteOrder) {
                $deleteOrder[] = $cat->getId();
                return $cat;
            });

        $count = $this->service->deleteAll('user1');

        $this->assertEquals(2, $count);
        $this->assertEquals([2, 1], $deleteOrder);
    }

    public function testDeleteAllSkipsCategoriesWithTransactions(): void {
        $cat1 = $this->makeCategory(['id' => 1, 'parentId' => null]);
        $cat2 = $this->makeCategory(['id' => 2, 'parentId' => null]);

        $this->categoryMapper->method('findAll')->willReturn([$cat1, $cat2]);
        $this->categoryMapper->method('findChildren')->willReturn([]);

        $this->transactionMapper->method('findByCategory')
            ->willReturnCallback(function (int $id) {
                return $id === 1 ? [['id' => 1]] : [];
            });

        $count = $this->service->deleteAll('user1');
        $this->assertEquals(1, $count);
    }

    // ===== getSuggestedBudgetPercentages() =====

    public function testGetSuggestedBudgetPercentagesReturnsMappings(): void {
        $result = $this->service->getSuggestedBudgetPercentages();

        $this->assertArrayHasKey('Housing', $result);
        $this->assertEquals(30, $result['Housing']);
        $this->assertArrayHasKey('Savings', $result);
        $this->assertEquals(20, $result['Savings']);
    }
}

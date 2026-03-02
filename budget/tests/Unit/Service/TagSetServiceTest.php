<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\TagSetService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class TagSetServiceTest extends TestCase {
    private TagSetService $service;
    private TagSetMapper $tagSetMapper;
    private TagMapper $tagMapper;
    private CategoryMapper $categoryMapper;
    private TransactionTagMapper $transactionTagMapper;
    private SavingsGoalMapper $savingsGoalMapper;

    protected function setUp(): void {
        $this->tagSetMapper = $this->createMock(TagSetMapper::class);
        $this->tagMapper = $this->createMock(TagMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->transactionTagMapper = $this->createMock(TransactionTagMapper::class);
        $this->savingsGoalMapper = $this->createMock(SavingsGoalMapper::class);

        $this->service = new TagSetService(
            $this->tagSetMapper,
            $this->tagMapper,
            $this->categoryMapper,
            $this->transactionTagMapper,
            $this->savingsGoalMapper
        );
    }

    private function makeTagSet(array $overrides = []): TagSet {
        $ts = new TagSet();
        $defaults = [
            'id' => 1,
            'categoryId' => 10,
            'name' => 'Location',
            'description' => 'Where it happened',
            'sortOrder' => 0,
        ];
        $data = array_merge($defaults, $overrides);

        $ts->setId($data['id']);
        $ts->setCategoryId($data['categoryId']);
        $ts->setName($data['name']);
        $ts->setDescription($data['description']);
        $ts->setSortOrder($data['sortOrder']);
        $ts->setCreatedAt('2026-01-01 00:00:00');
        $ts->setUpdatedAt('2026-01-01 00:00:00');
        return $ts;
    }

    private function makeTag(array $overrides = []): Tag {
        $tag = new Tag();
        $defaults = [
            'id' => 1,
            'tagSetId' => 1,
            'name' => 'Online',
            'color' => '#ff0000',
            'sortOrder' => 0,
        ];
        $data = array_merge($defaults, $overrides);

        $tag->setId($data['id']);
        $tag->setTagSetId($data['tagSetId']);
        $tag->setName($data['name']);
        $tag->setColor($data['color']);
        $tag->setSortOrder($data['sortOrder']);
        $tag->setCreatedAt('2026-01-01 00:00:00');
        return $tag;
    }

    private function makeCategory(int $id = 10): Category {
        $cat = new Category();
        $cat->setId($id);
        $cat->setUserId('user1');
        $cat->setName('Food');
        $cat->setType('expense');
        return $cat;
    }

    // ===== create() =====

    public function testCreateValidatesCategoryOwnership(): void {
        $this->categoryMapper->expects($this->once())
            ->method('find')
            ->with(10, 'user1')
            ->willReturn($this->makeCategory());

        $this->tagSetMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (TagSet $ts) {
                $this->assertEquals(10, $ts->getCategoryId());
                $this->assertEquals('Location', $ts->getName());
                $this->assertEquals('Where it happened', $ts->getDescription());
                $ts->setId(1);
                return $ts;
            });

        $result = $this->service->create('user1', 10, 'Location', 'Where it happened');
        $this->assertEquals('Location', $result->getName());
    }

    public function testCreateThrowsIfCategoryNotFound(): void {
        $this->categoryMapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->create('user1', 999, 'Bad TagSet');
    }

    // ===== findByCategory() =====

    public function testFindByCategoryDelegatesToMapper(): void {
        $tagSets = [$this->makeTagSet()];
        $this->tagSetMapper->expects($this->once())
            ->method('findByCategory')
            ->with(10, 'user1')
            ->willReturn($tagSets);

        $result = $this->service->findByCategory(10, 'user1');
        $this->assertCount(1, $result);
    }

    // ===== getCategoryTagSetsWithTags() =====

    public function testGetCategoryTagSetsWithTagsBatchLoadsTags(): void {
        $ts1 = $this->makeTagSet(['id' => 1]);
        $ts2 = $this->makeTagSet(['id' => 2, 'name' => 'Type']);

        $this->tagSetMapper->method('findByCategory')->willReturn([$ts1, $ts2]);

        $tag1 = $this->makeTag(['id' => 10, 'tagSetId' => 1]);
        $tag2 = $this->makeTag(['id' => 11, 'tagSetId' => 1]);
        $tag3 = $this->makeTag(['id' => 12, 'tagSetId' => 2]);

        $this->tagMapper->expects($this->once())
            ->method('findByTagSets')
            ->with([1, 2])
            ->willReturn([
                1 => [$tag1, $tag2],
                2 => [$tag3],
            ]);

        $result = $this->service->getCategoryTagSetsWithTags(10, 'user1');

        $this->assertCount(2, $result);
        $this->assertCount(2, $result[0]->getTags());
        $this->assertCount(1, $result[1]->getTags());
    }

    public function testGetCategoryTagSetsWithTagsReturnsEmptyForNoTagSets(): void {
        $this->tagSetMapper->method('findByCategory')->willReturn([]);

        $this->tagMapper->expects($this->never())->method('findByTagSets');

        $result = $this->service->getCategoryTagSetsWithTags(10, 'user1');
        $this->assertEmpty($result);
    }

    public function testGetCategoryTagSetsWithTagsHandlesTagSetWithNoTags(): void {
        $ts = $this->makeTagSet(['id' => 1]);
        $this->tagSetMapper->method('findByCategory')->willReturn([$ts]);

        $this->tagMapper->method('findByTagSets')->willReturn([]);

        $result = $this->service->getCategoryTagSetsWithTags(10, 'user1');

        $this->assertCount(1, $result);
        $this->assertEmpty($result[0]->getTags());
    }

    // ===== getAllTagSetsWithTags() =====

    public function testGetAllTagSetsWithTagsBatchLoadsAllUserTags(): void {
        $ts = $this->makeTagSet(['id' => 1]);
        $this->tagSetMapper->method('findAll')->willReturn([$ts]);

        $tag = $this->makeTag(['id' => 10, 'tagSetId' => 1]);
        $this->tagMapper->method('findByTagSets')->willReturn([1 => [$tag]]);

        $result = $this->service->getAllTagSetsWithTags('user1');

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]->getTags());
    }

    public function testGetAllTagSetsWithTagsReturnsEmptyForNoTagSets(): void {
        $this->tagSetMapper->method('findAll')->willReturn([]);

        $result = $this->service->getAllTagSetsWithTags('user1');
        $this->assertEmpty($result);
    }

    // ===== getTagSetWithTags() =====

    public function testGetTagSetWithTagsLoadsSingle(): void {
        $ts = $this->makeTagSet();
        $this->tagSetMapper->method('find')->willReturn($ts);

        $tags = [$this->makeTag(['id' => 10])];
        $this->tagMapper->method('findByTagSet')->with(1)->willReturn($tags);

        $result = $this->service->getTagSetWithTags(1, 'user1');

        $this->assertEquals('Location', $result->getName());
        $this->assertCount(1, $result->getTags());
    }

    // ===== createTag() =====

    public function testCreateTagValidatesTagSetOwnership(): void {
        $ts = $this->makeTagSet();
        $this->tagSetMapper->expects($this->once())
            ->method('find')
            ->with(1, 'user1')
            ->willReturn($ts);

        $this->tagMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Tag $tag) {
                $this->assertEquals(1, $tag->getTagSetId());
                $this->assertEquals('Store', $tag->getName());
                $this->assertEquals('#00ff00', $tag->getColor());
                $tag->setId(10);
                return $tag;
            });

        $result = $this->service->createTag(1, 'user1', 'Store', '#00ff00');
        $this->assertEquals('Store', $result->getName());
    }

    public function testCreateTagGeneratesColorWhenNotProvided(): void {
        $ts = $this->makeTagSet();
        $this->tagSetMapper->method('find')->willReturn($ts);

        $this->tagMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Tag $tag) {
                $this->assertNotNull($tag->getColor());
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $tag->getColor());
                $tag->setId(10);
                return $tag;
            });

        $this->service->createTag(1, 'user1', 'Auto Color');
    }

    public function testCreateTagThrowsIfTagSetNotFound(): void {
        $this->tagSetMapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->createTag(999, 'user1', 'Bad Tag');
    }

    // ===== updateTag() =====

    public function testUpdateTagAppliesChanges(): void {
        $tag = $this->makeTag();
        $this->tagMapper->method('find')->with(1, 'user1')->willReturn($tag);

        $this->tagMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->updateTag(1, 'user1', ['name' => 'Renamed']);
        $this->assertEquals('Renamed', $result->getName());
    }

    // ===== deleteTag() =====

    public function testDeleteTagClearsReferencesAndCascades(): void {
        $tag = $this->makeTag(['id' => 5]);
        $this->tagMapper->method('find')->with(5, 'user1')->willReturn($tag);

        $this->savingsGoalMapper->expects($this->once())
            ->method('clearTagReference')
            ->with(5);

        $this->transactionTagMapper->expects($this->once())
            ->method('deleteByTag')
            ->with(5);

        $this->tagMapper->expects($this->once())
            ->method('delete')
            ->with($tag);

        $this->service->deleteTag(5, 'user1');
    }

    public function testDeleteTagThrowsIfNotFound(): void {
        $this->tagMapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->deleteTag(999, 'user1');
    }

    // ===== beforeUpdate() (via update()) =====

    public function testUpdateValidatesNewCategory(): void {
        $ts = $this->makeTagSet();
        $this->tagSetMapper->method('find')->willReturn($ts);

        $cat = $this->makeCategory(20);
        $this->categoryMapper->expects($this->once())
            ->method('find')
            ->with(20, 'user1')
            ->willReturn($cat);

        $this->tagSetMapper->method('update')->willReturnArgument(0);

        $this->service->update(1, 'user1', ['categoryId' => 20]);
    }

    public function testUpdateRejectsInvalidCategory(): void {
        $ts = $this->makeTagSet();
        $this->tagSetMapper->method('find')->willReturn($ts);

        $this->categoryMapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->update(1, 'user1', ['categoryId' => 999]);
    }

    // ===== beforeDelete() (via delete()) =====

    public function testDeleteCascadesAllTagsWithReferences(): void {
        $ts = $this->makeTagSet(['id' => 1]);
        $this->tagSetMapper->method('find')->willReturn($ts);

        $tag1 = $this->makeTag(['id' => 10, 'tagSetId' => 1]);
        $tag2 = $this->makeTag(['id' => 11, 'tagSetId' => 1]);
        $this->tagMapper->method('findByTagSet')->with(1)->willReturn([$tag1, $tag2]);

        // Should clear savings goal refs for both tags
        $this->savingsGoalMapper->expects($this->exactly(2))
            ->method('clearTagReference')
            ->willReturnCallback(function (int $tagId) {
                $this->assertContains($tagId, [10, 11]);
                return 0;
            });

        // Should delete transaction tags for both tags
        $this->transactionTagMapper->expects($this->exactly(2))
            ->method('deleteByTag');

        // Should delete both tags
        $this->tagMapper->expects($this->exactly(2))
            ->method('delete');

        // Should delete the tag set itself
        $this->tagSetMapper->expects($this->once())
            ->method('delete')
            ->with($ts);

        $this->service->delete(1, 'user1');
    }

    public function testDeleteWithNoTagsJustDeletesTagSet(): void {
        $ts = $this->makeTagSet(['id' => 1]);
        $this->tagSetMapper->method('find')->willReturn($ts);

        $this->tagMapper->method('findByTagSet')->willReturn([]);

        $this->savingsGoalMapper->expects($this->never())->method('clearTagReference');
        $this->transactionTagMapper->expects($this->never())->method('deleteByTag');
        $this->tagMapper->expects($this->never())->method('delete');

        $this->tagSetMapper->expects($this->once())->method('delete');

        $this->service->delete(1, 'user1');
    }
}

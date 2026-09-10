<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\TransactionTagService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TransactionTagServiceTest extends TestCase {
    private TransactionTagService $service;
    private TransactionTagMapper $transactionTagMapper;
    private TagMapper $tagMapper;
    private TagSetMapper $tagSetMapper;
    private TransactionMapper $transactionMapper;
    private IDBConnection $db;

    protected function setUp(): void {
        $this->transactionTagMapper = $this->createMock(TransactionTagMapper::class);
        $this->tagMapper = $this->createMock(TagMapper::class);
        $this->tagSetMapper = $this->createMock(TagSetMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->service = new TransactionTagService(
            $this->transactionTagMapper,
            $this->tagMapper,
            $this->tagSetMapper,
            $this->transactionMapper,
            $this->db
        );
    }

    private function makeTransaction(int $id, ?int $categoryId = 5): Transaction {
        $t = new Transaction();
        $t->setId($id);
        $t->setCategoryId($categoryId);
        return $t;
    }

    private function makeTag(int $id, int $tagSetId = 1): Tag {
        $tag = new Tag();
        $tag->setId($id);
        $tag->setTagSetId($tagSetId);
        $tag->setName("Tag $id");
        return $tag;
    }

    private function makeTransactionTag(int $transactionId, int $tagId): TransactionTag {
        $tt = new TransactionTag();
        $tt->setTransactionId($transactionId);
        $tt->setTagId($tagId);
        return $tt;
    }

    // ===== setTransactionTags =====

    public function testSetTransactionTagsWithEmptyTagIdsClears(): void {
        $this->transactionMapper->method('find')->willReturn($this->makeTransaction(100));
        $this->transactionTagMapper->expects($this->once())->method('deleteByTransaction')->with(100);

        $result = $this->service->setTransactionTags(100, 'user1', []);

        $this->assertEmpty($result);
    }

    public function testSetTransactionTagsCreatesNewTags(): void {
        $transaction = $this->makeTransaction(100);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->transactionTagMapper->method('deleteByTransaction');
        $this->transactionTagMapper->method('insert')->willReturnArgument(0);

        // Mock tag validation
        $tag1 = $this->makeTag(10, 1);
        $tag2 = $this->makeTag(20, 1);
        $this->tagMapper->method('findByIds')->willReturn([10 => $tag1, 20 => $tag2]);

        // Mock DB query for tag set validation
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $result = $this->createMock(IResult::class);
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':param');
        foreach (['select', 'from', 'innerJoin', 'where', 'andWhere'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(['id' => 1]); // Found = valid
        $result->method('closeCursor');

        $tags = $this->service->setTransactionTags(100, 'user1', [10, 20]);

        $this->assertCount(2, $tags);
    }

    public function testSetTransactionTagsThrowsWhenTagsDontExist(): void {
        $transaction = $this->makeTransaction(100);
        $this->transactionMapper->method('find')->willReturn($transaction);

        // Return only 1 tag when 2 were requested
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeTag(10)]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('One or more tags do not exist');

        $this->service->setTransactionTags(100, 'user1', [10, 20]);
    }

    // ===== getTransactionTags =====

    public function testGetTransactionTagsReturnsTagEntities(): void {
        $this->transactionMapper->method('find');
        $this->transactionTagMapper->method('findByTransaction')->willReturn([
            $this->makeTransactionTag(100, 10),
            $this->makeTransactionTag(100, 20),
        ]);

        $tag1 = $this->makeTag(10);
        $tag2 = $this->makeTag(20);
        $this->tagMapper->method('findByIds')->willReturn([10 => $tag1, 20 => $tag2]);

        $result = $this->service->getTransactionTags(100, 'user1');

        $this->assertCount(2, $result);
    }

    public function testGetTransactionTagsReturnsEmptyWhenNoTags(): void {
        $this->transactionMapper->method('find');
        $this->transactionTagMapper->method('findByTransaction')->willReturn([]);

        $result = $this->service->getTransactionTags(100, 'user1');

        $this->assertEmpty($result);
    }

    // ===== clearTransactionTags =====

    public function testClearTransactionTagsDeletesAll(): void {
        $this->transactionMapper->expects($this->once())->method('find')->with(100, 'user1');
        $this->transactionTagMapper->expects($this->once())->method('deleteByTransaction')->with(100);

        $this->service->clearTransactionTags(100, 'user1');
    }

    // ===== getTagUsageStats =====

    public function testGetTagUsageStatsDelegatesToMapper(): void {
        $expected = [10 => 5, 20 => 3];
        $this->transactionTagMapper->method('getTagUsageStats')->willReturn($expected);

        $result = $this->service->getTagUsageStats('user1');

        $this->assertEquals($expected, $result);
    }

    // ===== bulkUpdateTags (#379) =====

    private function makeGlobalTag(int $id, string $userId = 'user1'): Tag {
        $tag = new Tag();
        $tag->setId($id);
        $tag->setTagSetId(null);
        $tag->setUserId($userId);
        $tag->setName("Global $id");
        return $tag;
    }

    /** A tag belonging to $tagSetId, which the test binds to a category. */
    private function makeSetTag(int $id, int $tagSetId): Tag {
        $tag = new Tag();
        $tag->setId($id);
        $tag->setTagSetId($tagSetId);
        $tag->setName("Set tag $id");
        return $tag;
    }

    /** Stub the tag-set -> category binding that gates category tags. */
    private function bindTagSets(array $tagSetIdToCategoryId, array $categoryNames = []): void {
        $rows = [];
        foreach ($tagSetIdToCategoryId as $tagSetId => $categoryId) {
            $rows[] = [
                'id' => $tagSetId,
                'name' => "Set $tagSetId",
                'categoryId' => $categoryId,
                'categoryName' => $categoryNames[$categoryId] ?? "Category $categoryId",
            ];
        }
        $this->tagSetMapper->method('findByCategoriesWithNames')->willReturn($rows);
        $this->tagSetMapper->method('findCategoryIdsForTagSets')->willReturn($tagSetIdToCategoryId);
    }

    public function testBulkUpdateTagsRejectsTagOwnedByAnotherUser(): void {
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeGlobalTag(10, 'someone-else')]);
        $this->transactionMapper->method('findOwnedCategoryIds')->willReturn([100 => 5]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('One or more tags are not available for this selection');

        $this->service->bulkUpdateTags('user1', [100], [10], []);
    }

    public function testBulkUpdateTagsRejectsUnknownTag(): void {
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeGlobalTag(10)]);
        $this->transactionMapper->method('findOwnedCategoryIds')->willReturn([100 => 5]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('One or more tags do not exist');

        $this->service->bulkUpdateTags('user1', [100], [10, 20], []);
    }

    public function testBulkUpdateTagsRejectsTagInBothAddAndRemove(): void {
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeGlobalTag(10)]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('A tag cannot be both added and removed');

        $this->service->bulkUpdateTags('user1', [100], [10], [10]);
    }

    public function testBulkUpdateTagsAddsGlobalTagOnlyWhereMissing(): void {
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeGlobalTag(10)]);
        $this->transactionMapper->method('findOwnedCategoryIds')
            ->willReturn([100 => 5, 101 => 5, 102 => 9]);
        // 101 already carries tag 10 - re-adding it must not duplicate the row.
        $this->transactionTagMapper->method('findExistingTagIdsByTransaction')->willReturn([101 => [10]]);

        $inserted = [];
        $this->transactionTagMapper->method('insert')->willReturnCallback(
            function (TransactionTag $tt) use (&$inserted) {
                $inserted[] = [$tt->getTransactionId(), $tt->getTagId()];
                return $tt;
            }
        );

        $result = $this->service->bulkUpdateTags('user1', [100, 101, 102], [10], []);

        $this->assertSame([[100, 10], [102, 10]], $inserted);
        $this->assertSame(3, $result['success']);
        // A global tag applies to every row, the one that already had it included.
        $this->assertSame(3, $result['applied'][10]);
    }

    public function testBulkUpdateTagsAppliesCategoryTagOnlyToMatchingRows(): void {
        $this->tagMapper->method('findByIds')->willReturn([30 => $this->makeSetTag(30, 7)]);
        $this->bindTagSets([7 => 5]);
        // Two rows in category 5, one in category 9.
        $this->transactionMapper->method('findOwnedCategoryIds')
            ->willReturn([100 => 5, 101 => 9, 102 => 5]);
        $this->transactionTagMapper->method('findExistingTagIdsByTransaction')->willReturn([]);

        $inserted = [];
        $this->transactionTagMapper->method('insert')->willReturnCallback(
            function (TransactionTag $tt) use (&$inserted) {
                $inserted[] = $tt->getTransactionId();
                return $tt;
            }
        );

        $result = $this->service->bulkUpdateTags('user1', [100, 101, 102], [30], []);

        $this->assertSame([100, 102], $inserted);
        $this->assertSame(2, $result['applied'][30]);
        $this->assertSame(3, $result['success']);
    }

    public function testBulkUpdateTagsSkipsUncategorisedRowsForCategoryTags(): void {
        $this->tagMapper->method('findByIds')->willReturn([30 => $this->makeSetTag(30, 7)]);
        $this->bindTagSets([7 => 5]);
        // 101 is a split parent: splitting nulls the parent's category.
        $this->transactionMapper->method('findOwnedCategoryIds')
            ->willReturn([100 => 5, 101 => null]);
        $this->transactionTagMapper->method('findExistingTagIdsByTransaction')->willReturn([]);

        $inserted = [];
        $this->transactionTagMapper->method('insert')->willReturnCallback(
            function (TransactionTag $tt) use (&$inserted) {
                $inserted[] = $tt->getTransactionId();
                return $tt;
            }
        );

        $result = $this->service->bulkUpdateTags('user1', [100, 101], [30], []);

        $this->assertSame([100], $inserted);
        $this->assertSame(1, $result['applied'][30]);
    }

    public function testBulkUpdateTagsRefusesCategoryTagForCategoryNotInSelection(): void {
        $this->tagMapper->method('findByIds')->willReturn([30 => $this->makeSetTag(30, 7)]);
        // Tag set 7 belongs to category 42, which nothing in the selection uses.
        $this->bindTagSets([7 => 42]);
        $this->transactionMapper->method('findOwnedCategoryIds')->willReturn([100 => 5, 101 => 9]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('One or more tags are not available for this selection');

        $this->service->bulkUpdateTags('user1', [100, 101], [30], []);
    }

    public function testBulkUpdateTagsRemovesCategoryTagRegardlessOfRowCategory(): void {
        $this->tagMapper->method('findByIds')->willReturn([30 => $this->makeSetTag(30, 7)]);
        $this->bindTagSets([7 => 5]);
        $this->transactionMapper->method('findOwnedCategoryIds')
            ->willReturn([100 => 5, 101 => 9, 102 => null]);

        // Removal is deliberately NOT category-gated: re-categorising a
        // transaction leaves its old category tag on the row, and a gated
        // removal would make that tag impossible to clear in bulk.
        $this->transactionTagMapper->expects($this->once())
            ->method('deleteByTransactionsAndTags')
            ->with([100, 101, 102], [30])
            ->willReturn(1);

        $this->service->bulkUpdateTags('user1', [100, 101, 102], [], [30]);
    }

    public function testBulkUpdateTagsCountsUnownedTransactionsAsFailed(): void {
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeGlobalTag(10)]);
        // 999 belongs to somebody else, so the scoping query drops it.
        $this->transactionMapper->method('findOwnedCategoryIds')->willReturn([100 => 5]);
        $this->transactionTagMapper->method('findExistingTagIdsByTransaction')->willReturn([]);
        $this->transactionTagMapper->method('insert')->willReturnArgument(0);

        $result = $this->service->bulkUpdateTags('user1', [100, 999], [10], []);

        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(999, $result['errors'][0]['id']);
    }

    public function testBulkUpdateTagsSkipsWorkWhenNothingIsOwned(): void {
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeGlobalTag(10)]);
        $this->transactionMapper->method('findOwnedCategoryIds')->willReturn([]);

        $this->transactionTagMapper->expects($this->never())->method('deleteByTransactionsAndTags');
        $this->transactionTagMapper->expects($this->never())->method('insert');

        $result = $this->service->bulkUpdateTags('user1', [999], [], [10]);

        $this->assertSame(0, $result['success']);
        $this->assertSame(1, $result['failed']);
    }

    // ===== getBulkTagOptions (#379) =====

    public function testGetBulkTagOptionsOffersOnlyTagSetsInTheSelection(): void {
        $this->transactionMapper->method('findOwnedCategoryIds')
            ->willReturn([100 => 5, 101 => 5, 102 => 9, 103 => null]);
        $this->tagMapper->method('findGlobal')->willReturn([$this->makeGlobalTag(10)]);
        $this->bindTagSets([7 => 5], [5 => 'Groceries']);
        $this->tagMapper->method('findByTagSets')->willReturn([7 => [$this->makeSetTag(30, 7)]]);

        $options = $this->service->getBulkTagOptions('user1', [100, 101, 102, 103]);

        $this->assertSame(4, $options['totalSelected']);
        $this->assertCount(1, $options['globalTags']);
        $this->assertCount(1, $options['tagSets']);
        $this->assertSame('Groceries', $options['tagSets'][0]['categoryName']);
        $this->assertSame(2, $options['tagSets'][0]['matchingCount']);
    }

    public function testGetBulkTagOptionsCountsRowsNoCategoryTagCovers(): void {
        // 102 is in a category with no tag set; 103 is a split (no category).
        $this->transactionMapper->method('findOwnedCategoryIds')
            ->willReturn([100 => 5, 101 => 5, 102 => 9, 103 => null]);
        $this->tagMapper->method('findGlobal')->willReturn([]);
        $this->bindTagSets([7 => 5]);
        $this->tagMapper->method('findByTagSets')->willReturn([7 => [$this->makeSetTag(30, 7)]]);

        $options = $this->service->getBulkTagOptions('user1', [100, 101, 102, 103]);

        $this->assertSame(2, $options['unaffectedCount']);
    }
}

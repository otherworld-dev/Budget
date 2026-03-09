<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\TransactionSplitService;
use PHPUnit\Framework\TestCase;

class TransactionSplitServiceTest extends TestCase {
    private TransactionSplitService $service;
    private TransactionSplitMapper $splitMapper;
    private TransactionMapper $transactionMapper;

    protected function setUp(): void {
        $this->splitMapper = $this->createMock(TransactionSplitMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->service = new TransactionSplitService($this->splitMapper, $this->transactionMapper);
    }

    private function makeTransaction(float $amount, bool $isSplit = false): Transaction {
        $t = new Transaction();
        $t->setId(100);
        $t->setAmount((string)$amount);
        $t->setIsSplit($isSplit);
        $t->setCategoryId(5);
        return $t;
    }

    private function makeSplit(int $id, float $amount): TransactionSplit {
        $s = new TransactionSplit();
        $s->setId($id);
        $s->setTransactionId(100);
        $s->setAmount((string)$amount);
        $s->setCategoryId(1);
        return $s;
    }

    // ===== getSplits =====

    public function testGetSplitsVerifiesOwnershipAndReturnsSplits(): void {
        $this->transactionMapper->expects($this->once())->method('find')
            ->with(100, 'user1');
        $splits = [$this->makeSplit(1, 50), $this->makeSplit(2, 50)];
        $this->splitMapper->method('findByTransaction')->willReturn($splits);

        $result = $this->service->getSplits(100, 'user1');

        $this->assertCount(2, $result);
    }

    // ===== splitTransaction =====

    public function testSplitTransactionCreatesSplitsAndUpdatesTransaction(): void {
        $transaction = $this->makeTransaction(100.00);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->transactionMapper->expects($this->once())->method('update');
        $this->splitMapper->expects($this->once())->method('deleteByTransaction')->with(100);
        $this->splitMapper->method('insert')->willReturnArgument(0);
        $this->splitMapper->method('findByTransaction')->willReturn([]);

        $splits = [
            ['categoryId' => 1, 'amount' => 60.00, 'description' => 'Food'],
            ['categoryId' => 2, 'amount' => 40.00, 'description' => 'Drinks'],
        ];

        $this->service->splitTransaction(100, 'user1', $splits);
    }

    public function testSplitTransactionThrowsWhenAmountsMismatch(): void {
        $transaction = $this->makeTransaction(100.00);
        $this->transactionMapper->method('find')->willReturn($transaction);

        $splits = [
            ['categoryId' => 1, 'amount' => 60.00],
            ['categoryId' => 2, 'amount' => 30.00], // Total 90, not 100
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Split amounts');

        $this->service->splitTransaction(100, 'user1', $splits);
    }

    public function testSplitTransactionThrowsWithLessThanTwoSplits(): void {
        $transaction = $this->makeTransaction(100.00);
        $this->transactionMapper->method('find')->willReturn($transaction);

        $splits = [
            ['categoryId' => 1, 'amount' => 100.00],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2 parts');

        $this->service->splitTransaction(100, 'user1', $splits);
    }

    public function testSplitTransactionAllowsSmallFloatingPointVariance(): void {
        $transaction = $this->makeTransaction(100.00);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->transactionMapper->method('update')->willReturnArgument(0);
        $this->splitMapper->method('insert')->willReturnArgument(0);
        $this->splitMapper->method('findByTransaction')->willReturn([]);

        $splits = [
            ['categoryId' => 1, 'amount' => 66.67],
            ['categoryId' => 2, 'amount' => 33.335], // Total 100.005 - within 0.01 tolerance
        ];

        // Should not throw
        $this->service->splitTransaction(100, 'user1', $splits);
        $this->assertTrue(true);
    }

    // ===== unsplitTransaction =====

    public function testUnsplitTransactionDeletesSplitsAndUpdates(): void {
        $transaction = $this->makeTransaction(100.00, true);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->transactionMapper->method('update')->willReturnArgument(0);
        $this->splitMapper->expects($this->once())->method('deleteByTransaction')->with(100);

        $result = $this->service->unsplitTransaction(100, 'user1', 5);

        $this->assertFalse($result->getIsSplit());
        $this->assertEquals(5, $result->getCategoryId());
    }

    public function testUnsplitTransactionThrowsIfNotSplit(): void {
        $transaction = $this->makeTransaction(100.00, false);
        $this->transactionMapper->method('find')->willReturn($transaction);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not split');

        $this->service->unsplitTransaction(100, 'user1');
    }

    // ===== updateSplit =====

    public function testUpdateSplitUpdatesCategoryId(): void {
        $split = $this->makeSplit(1, 50.00);
        $transaction = $this->makeTransaction(100.00);

        $this->splitMapper->method('find')->willReturn($split);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->splitMapper->method('update')->willReturnArgument(0);

        $result = $this->service->updateSplit(1, 'user1', ['categoryId' => 10]);

        $this->assertEquals(10, $result->getCategoryId());
    }

    public function testUpdateSplitValidatesAmountTotal(): void {
        $split = $this->makeSplit(1, 50.00);
        $transaction = $this->makeTransaction(100.00);

        $this->splitMapper->method('find')->willReturn($split);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->splitMapper->method('findByTransaction')->willReturn([
            $this->makeSplit(1, 50.00),
            $this->makeSplit(2, 50.00),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        // Changing split 1 to 80 means total = 80 + 50 = 130, not 100
        $this->service->updateSplit(1, 'user1', ['amount' => 80.00]);
    }

    // ===== getCategoryTotalsFromSplits =====

    public function testGetCategoryTotalsFromSplitsDelegatesToMapper(): void {
        $expected = [1 => 100.0, 2 => 50.0];
        $this->splitMapper->method('getCategoryTotals')->willReturn($expected);

        $result = $this->service->getCategoryTotalsFromSplits([100, 101]);

        $this->assertEquals($expected, $result);
    }
}

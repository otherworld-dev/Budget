<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\ImportAccountLink;
use OCA\Budget\Db\ImportAccountLinkMapper;
use OCA\Budget\Service\ImportAccountLinkService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class ImportAccountLinkServiceTest extends TestCase {
    private ImportAccountLinkMapper $mapper;
    private ImportAccountLinkService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ImportAccountLinkMapper::class);
        $this->service = new ImportAccountLinkService($this->mapper);
    }

    private function makeLink(string $sourceKey, int $destId): ImportAccountLink {
        $link = new ImportAccountLink();
        $link->setId(1);
        $link->setUserId('user1');
        $link->setFormat('qif');
        $link->setSourceKey($sourceKey);
        $link->setBudgetAccountId($destId);
        return $link;
    }

    public function testRememberInsertsNewLink(): void {
        $this->mapper->method('findBySource')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (ImportAccountLink $l) {
                $this->assertEquals('Checking', $l->getSourceKey());
                $this->assertEquals(5, $l->getBudgetAccountId());
                $this->assertEquals('qif', $l->getFormat());
                return $l;
            });

        $this->service->remember('user1', 'qif', ['Checking' => 5]);
    }

    public function testRememberUpdatesWhenDestinationChanged(): void {
        $this->mapper->method('findBySource')->willReturn($this->makeLink('Checking', 5));
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (ImportAccountLink $l) {
                $this->assertEquals(8, $l->getBudgetAccountId());
                return $l;
            });

        $this->service->remember('user1', 'qif', ['Checking' => 8]);
    }

    public function testRememberSkipsUpdateWhenUnchanged(): void {
        $this->mapper->method('findBySource')->willReturn($this->makeLink('Checking', 5));
        $this->mapper->expects($this->never())->method('update');
        $this->mapper->expects($this->never())->method('insert');

        $this->service->remember('user1', 'qif', ['Checking' => 5]);
    }

    public function testRememberSkipsInvalidEntries(): void {
        $this->mapper->expects($this->never())->method('findBySource');
        $this->mapper->expects($this->never())->method('insert');

        $this->service->remember('user1', 'qif', ['' => 5, 'zero' => 0, 'neg' => -2]);
    }

    public function testRememberIgnoresUnsupportedFormat(): void {
        $this->mapper->expects($this->never())->method('findBySource');
        $this->mapper->expects($this->never())->method('insert');

        $this->service->remember('user1', 'csv', ['Checking' => 5]);
    }

    public function testRecallReturnsMap(): void {
        $this->mapper->method('findAllByFormat')->willReturn([
            $this->makeLink('Checking', 5),
            $this->makeLink('Savings', 9),
        ]);

        $map = $this->service->recall('user1', 'qif');

        $this->assertEquals(['Checking' => 5, 'Savings' => 9], $map);
    }

    public function testRecallEmptyForUnsupportedFormat(): void {
        $this->mapper->expects($this->never())->method('findAllByFormat');

        $this->assertSame([], $this->service->recall('user1', 'csv'));
    }
}

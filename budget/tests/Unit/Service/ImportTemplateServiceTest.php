<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
use OCA\Budget\Service\ImportTemplateService;
use PHPUnit\Framework\TestCase;

class ImportTemplateServiceTest extends TestCase {
    private ImportTemplateMapper $mapper;
    private ImportTemplateService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ImportTemplateMapper::class);
        $this->service = new ImportTemplateService($this->mapper);
    }

    private function validMapping(): array {
        return ['date' => 'Date', 'amount' => 'Amount', 'description' => 'Memo'];
    }

    public function testCreatePersistsTemplate(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', '  My Bank  ', $this->validMapping(), ';', true, 7);

        $this->assertEquals('My Bank', $template->getName());
        $this->assertEquals('user1', $template->getUserId());
        $this->assertEquals(';', $template->getDelimiter());
        $this->assertTrue($template->getSkipFirstRow());
        $this->assertEquals(7, $template->getAccountId());
        $this->assertEquals($this->validMapping(), $template->getParsedMapping());
    }

    public function testCreateUsesCommaWhenDelimiterEmpty(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'Bank', $this->validMapping(), '');

        $this->assertEquals(',', $template->getDelimiter());
    }

    public function testCreateStripsUnknownMappingKeys(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $mapping = $this->validMapping() + ['bogus' => 'Nope', 'skipFirstRow' => true];
        $template = $this->service->create('user1', 'Bank', $mapping);

        $parsed = $template->getParsedMapping();
        $this->assertArrayNotHasKey('bogus', $parsed);
        $this->assertTrue($parsed['skipFirstRow']);
    }

    public function testCreateRejectsEmptyName(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', '   ', $this->validMapping());
    }

    public function testCreateRejectsDuplicateName(): void {
        $this->mapper->method('nameExists')->willReturn(true);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Dupe', $this->validMapping());
    }

    public function testCreateRejectsMissingDate(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Bank', ['amount' => 'Amount', 'description' => 'Memo']);
    }

    public function testCreateRejectsMissingDescription(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Bank', ['date' => 'Date', 'amount' => 'Amount']);
    }

    public function testCreateRejectsBothAmountAndDualColumns(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Bank', [
            'date' => 'Date',
            'description' => 'Memo',
            'amount' => 'Amount',
            'incomeColumn' => 'Credit',
        ]);
    }

    public function testCreateAcceptsDualColumnsWithoutAmount(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'Bank', [
            'date' => 'Date',
            'description' => 'Memo',
            'incomeColumn' => 'Credit',
            'expenseColumn' => 'Debit',
        ]);

        $parsed = $template->getParsedMapping();
        $this->assertEquals('Credit', $parsed['incomeColumn']);
        $this->assertArrayNotHasKey('amount', $parsed);
    }

    public function testUpdateConvertsMappingToJson(): void {
        $existing = new ImportTemplate();
        $existing->setId(1);
        $existing->setUserId('user1');
        $existing->setName('Bank');
        $existing->setMappingFromArray($this->validMapping());
        $this->mapper->method('find')->willReturn($existing);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(fn (ImportTemplate $t) => $t);

        $newMapping = ['date' => 'Posted', 'amount' => 'Value', 'description' => 'Details'];
        $updated = $this->service->update(1, 'user1', ['mapping' => $newMapping]);

        $this->assertEquals($newMapping, $updated->getParsedMapping());
    }

    public function testUpdateRejectsDuplicateName(): void {
        $this->mapper->method('nameExists')->willReturn(true);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->update(1, 'user1', ['name' => 'Taken']);
    }
}

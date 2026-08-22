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

    // ── CSV templates ───────────────────────────────────────────────

    public function testCreateCsvPersistsTemplate(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', '  My Bank  ', 'csv', $this->validMapping(), [], ';', true, false, false, 7);

        $this->assertEquals('My Bank', $template->getName());
        $this->assertEquals('csv', $template->getFormat());
        $this->assertEquals(';', $template->getDelimiter());
        $this->assertTrue($template->getSkipFirstRow());
        $this->assertEquals(7, $template->getAccountId());
        $this->assertEquals($this->validMapping(), $template->getParsedMapping());
        $this->assertEquals([], $template->getParsedAccountMapping());
    }

    public function testCreateDefaultsToCsv(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'Bank', 'csv', $this->validMapping());

        $this->assertEquals('csv', $template->getFormat());
        $this->assertEquals(',', $template->getDelimiter());
    }

    public function testCreateRejectsUnsupportedFormat(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Bank', 'xml', $this->validMapping());
    }

    public function testCreateRejectsEmptyName(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', '   ', 'csv', $this->validMapping());
    }

    public function testCreateRejectsDuplicateName(): void {
        $this->mapper->method('nameExists')->willReturn(true);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Dupe', 'csv', $this->validMapping());
    }

    public function testCreateCsvRejectsMissingDate(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Bank', 'csv', ['amount' => 'Amount', 'description' => 'Memo']);
    }

    public function testCreateCsvRejectsBothAmountAndDualColumns(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Bank', 'csv', [
            'date' => 'Date',
            'description' => 'Memo',
            'amount' => 'Amount',
            'incomeColumn' => 'Credit',
        ]);
    }

    // #340: sanitizeMapping is a strict allowlist, so an optional target that
    // falls out of COLUMN_FIELDS is dropped silently — a saved template would
    // quietly stop mapping it with the whole suite still green.
    public function testCreateCsvRoundTripsEveryOptionalTarget(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $mapping = $this->validMapping() + [
            'notes' => 'Info',
            'vendor' => 'Payee',
            'reference' => 'Ref',
            'type' => 'Dr/Cr',
            'category' => 'Category',
            'account' => 'Account',
            'currency' => 'Currency',
        ];

        $template = $this->service->create('user1', 'Full mapping', 'csv', $mapping);

        $this->assertEquals($mapping, $template->getParsedMapping());
    }

    public function testCreateCsvSupportsArrayMappings(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $mapping = [
            'date' => 'Date',
            'amount' => 'Amount',
            'description' => ['Memo', 'Payee'],
            'notes' => ['Extra1', 'Extra2'],
        ];

        $template = $this->service->create('user1', 'Array Mapping', 'csv', $mapping);

        $this->assertEquals($mapping, $template->getParsedMapping());
    }

    public function testCreateCsvDropsArrayMappingsForNonTextFields(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Map either an amount column or separate income/expense columns, but not both');

        $mapping = [
            'date' => 'Date',
            'amount' => ['Amount', 'Credit'],
            'description' => 'Memo',
        ];

        $this->service->create('user1', 'Scalar-only mapping', 'csv', $mapping);
    }

    public function testCreateCsvDropsBlankOnlyArrayMappings(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $mapping = [
            'date' => 'Date',
            'amount' => 'Amount',
            'description' => ['   ', '', 'Memo'],
        ];

        $template = $this->service->create('user1', 'Blank array mapping', 'csv', $mapping);

        $this->assertSame(['date' => 'Date', 'amount' => 'Amount', 'description' => ['Memo']], $template->getParsedMapping());
    }

    public function testCreateCsvDropsUnknownMappingKeys(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'Bank', 'csv', $this->validMapping() + ['bogus' => 'X']);

        $this->assertArrayNotHasKey('bogus', $template->getParsedMapping());
    }

    // ── OFX/QIF account-routing templates ───────────────────────────

    public function testCreateOfxPersistsAccountRouting(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $routing = ['1234567' => 5, 'savings' => 9];
        $template = $this->service->create('user1', 'My OFX Bank', 'ofx', [], $routing, ',', false, false, true, null);

        $this->assertEquals('ofx', $template->getFormat());
        $this->assertEquals(['1234567' => 5, 'savings' => 9], $template->getParsedAccountMapping());
        $this->assertEquals([], $template->getParsedMapping());
        $this->assertFalse($template->getSkipDuplicates());
        $this->assertTrue($template->getApplyRules());
    }

    public function testCreateQifRequiresAccountMapping(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('user1', 'Empty QIF', 'qif', [], []);
    }

    public function testCreateOfxDropsInvalidRoutingEntries(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $routing = ['good' => 5, 'zero' => 0, 'negative' => -3, '' => 8];
        $template = $this->service->create('user1', 'OFX', 'ofx', [], $routing);

        $this->assertEquals(['good' => 5], $template->getParsedAccountMapping());
    }

    // #340: since #338 made the four text targets mappable for OFX/QIF, the
    // template has to carry them — it used to store [] for any non-CSV format,
    // so a non-default Notes choice was reset on every later import.
    public function testCreateOfxPersistsTextTargetMapping(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'OFX', 'ofx', [
            'description' => 'memo',
            'notes' => 'transactionType',
            'vendor' => 'name',
            'reference' => 'reference',
        ], ['1234567' => 5]);

        $this->assertEquals([
            'description' => 'memo',
            'notes' => 'transactionType',
            'vendor' => 'name',
            'reference' => 'reference',
        ], $template->getParsedMapping());
    }

    // Date/amount/type are structural in OFX and QIF, and these formats have no
    // per-row account, currency or dual-amount columns — storing a leftover CSV
    // selection would apply a mapping the format cannot honour.
    public function testCreateOfxDropsMappingKeysTheFormatCannotResolve(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'OFX', 'ofx', [
            'date' => 'Date',
            'amount' => 'Amount',
            'incomeColumn' => 'Credit',
            'account' => 'Account',
            'currency' => 'Currency',
            'notes' => 'memo',
        ], ['1234567' => 5]);

        $this->assertEquals(['notes' => 'memo'], $template->getParsedMapping());
    }

    // ImportController::applyTemplate uses "mapping is non-empty" to decide
    // whether the template has a mapping worth applying, so an OFX template
    // with no text targets must not be left holding stray boolean options.
    public function testCreateOfxWithNoTextTargetsStoresAnEmptyMapping(): void {
        $this->mapper->method('nameExists')->willReturn(false);
        $this->mapper->method('insert')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $template = $this->service->create('user1', 'OFX', 'ofx', [
            'skipFirstRow' => false,
            'applyRules' => true,
            'date' => 'Date',
        ], ['1234567' => 5]);

        $this->assertSame([], $template->getParsedMapping());
    }

    // The CSV rules demand date/amount/description; applying them to an OFX
    // template would reject every legitimate text-only mapping.
    public function testUpdateOfxMappingSkipsTheCsvRequiredColumnRules(): void {
        $existing = $this->makeOfxEntity();
        $this->mapper->method('find')->willReturn($existing);
        $this->mapper->method('update')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $updated = $this->service->update(1, 'user1', ['mapping' => ['notes' => 'transactionType']]);

        $this->assertEquals(['notes' => 'transactionType'], $updated->getParsedMapping());
    }

    // ── Updates ─────────────────────────────────────────────────────

    public function testUpdateConvertsMappingToJson(): void {
        $existing = $this->makeCsvEntity();
        $this->mapper->method('find')->willReturn($existing);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(fn (ImportTemplate $t) => $t);

        $newMapping = ['date' => 'Posted', 'amount' => 'Value', 'description' => 'Details'];
        $updated = $this->service->update(1, 'user1', ['mapping' => $newMapping]);

        $this->assertEquals($newMapping, $updated->getParsedMapping());
    }

    public function testUpdateConvertsAccountMappingToJson(): void {
        $existing = $this->makeOfxEntity();
        $this->mapper->method('find')->willReturn($existing);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(fn (ImportTemplate $t) => $t);

        $updated = $this->service->update(1, 'user1', ['accountMapping' => ['acct1' => 3]]);

        $this->assertEquals(['acct1' => 3], $updated->getParsedAccountMapping());
    }

    public function testUpdateIgnoresFormatChange(): void {
        $existing = $this->makeCsvEntity();
        $this->mapper->method('find')->willReturn($existing);
        $this->mapper->method('update')->willReturnCallback(fn (ImportTemplate $t) => $t);

        $updated = $this->service->update(1, 'user1', ['format' => 'ofx', 'name' => 'Renamed']);

        $this->assertEquals('csv', $updated->getFormat());
        $this->assertEquals('Renamed', $updated->getName());
    }

    public function testUpdateRejectsDuplicateName(): void {
        $this->mapper->method('nameExists')->willReturn(true);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->update(1, 'user1', ['name' => 'Taken']);
    }

    private function makeCsvEntity(): ImportTemplate {
        $e = new ImportTemplate();
        $e->setId(1);
        $e->setUserId('user1');
        $e->setName('Bank');
        $e->setFormat('csv');
        $e->setMappingFromArray($this->validMapping());
        return $e;
    }

    private function makeOfxEntity(): ImportTemplate {
        $e = new ImportTemplate();
        $e->setId(1);
        $e->setUserId('user1');
        $e->setName('OFX');
        $e->setFormat('ofx');
        $e->setAccountMappingFromArray(['old' => 2]);
        return $e;
    }
}

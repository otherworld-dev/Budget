<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\TransactionService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase {
    private ImportService $service;
    private IAppData $appData;
    private TransactionService $transactionService;
    private AccountMapper $accountMapper;
    private FileValidator $fileValidator;
    private ParserFactory $parserFactory;
    private TransactionNormalizer $normalizer;
    private DuplicateDetector $duplicateDetector;
    private ImportRuleApplicator $ruleApplicator;

    protected function setUp(): void {
        $this->appData = $this->createMock(IAppData::class);
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->fileValidator = $this->createMock(FileValidator::class);
        $this->parserFactory = $this->createMock(ParserFactory::class);
        $this->normalizer = $this->createMock(TransactionNormalizer::class);
        $this->duplicateDetector = $this->createMock(DuplicateDetector::class);
        $this->ruleApplicator = $this->createMock(ImportRuleApplicator::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            foreach ($params as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
            }
            return $text;
        });
        $this->service = new ImportService(
            $this->appData,
            $this->transactionService,
            $this->accountMapper,
            $this->fileValidator,
            $this->parserFactory,
            $this->normalizer,
            $this->duplicateDetector,
            $this->ruleApplicator,
            $l
        );
    }

    private function makeAccount(int $id, string $name): Account {
        $account = new Account();
        $account->setId($id);
        $account->setName($name);
        return $account;
    }

    private function mockImportFile(string $fileId, string $content): void {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn($content);

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);

        $this->appData->method('getFolder')->willReturn($folder);
    }

    // ===== getImportTemplates =====

    public function testGetImportTemplatesReturnsKnownBanks(): void {
        $templates = $this->service->getImportTemplates();

        $this->assertArrayHasKey('chase_checking', $templates);
        $this->assertArrayHasKey('bank_of_america', $templates);
        $this->assertArrayHasKey('wells_fargo', $templates);
        $this->assertEquals('Chase Checking', $templates['chase_checking']['name']);
        $this->assertEquals('csv', $templates['chase_checking']['format']);
        $this->assertArrayHasKey('mapping', $templates['chase_checking']);
    }

    // ===== getImportHistory =====

    public function testGetImportHistoryReturnsEmpty(): void {
        $result = $this->service->getImportHistory('user1');
        $this->assertEmpty($result);
    }

    // ===== executeImport =====

    public function testExecuteImportReturnsSuccessStructure(): void {
        $result = $this->service->executeImport('user1', 'import_123', 1, [1, 2, 3]);

        $this->assertEquals('import_123', $result['importId']);
        $this->assertEquals(1, $result['accountId']);
        $this->assertEquals(3, $result['imported']);
        $this->assertTrue($result['success']);
    }

    // ===== rollbackImport =====

    public function testRollbackImportReturnsSuccessStructure(): void {
        $result = $this->service->rollbackImport('user1', 1);

        $this->assertEquals(1, $result['importId']);
        $this->assertTrue($result['rolledBack']);
    }

    // ===== validateFile =====

    public function testValidateFileReturnsValidForParseable(): void {
        $this->mockImportFile('file.csv', "date,amount\n2025-01-01,100");
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '100'],
        ]);

        $result = $this->service->validateFile('user1', 'file.csv');

        $this->assertTrue($result['valid']);
        $this->assertEquals('csv', $result['format']);
        $this->assertEquals(1, $result['rowCount']);
        $this->assertContains('date', $result['columns']);
    }

    public function testValidateFileReturnsInvalidOnParseError(): void {
        $this->mockImportFile('bad.csv', 'garbage data');
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willThrowException(new \Exception('Parse error'));

        $result = $this->service->validateFile('user1', 'bad.csv');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Parse error', $result['error']);
    }

    // ===== previewImport - single account =====

    public function testPreviewSingleAccountImportRequiresAccountId(): void {
        $this->mockImportFile('file.csv', 'data');
        $this->parserFactory->method('detectFormat')->willReturn('csv');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Account ID is required');

        $this->service->previewImport('user1', 'file.csv', ['date' => 0]);
    }

    public function testPreviewSingleAccountImport(): void {
        $this->mockImportFile('file.csv', "date,amount,description\n2025-01-01,100,Test");
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '100', 'description' => 'Test'],
        ]);

        $account = $this->makeAccount(1, 'Checking');
        $this->accountMapper->method('find')->willReturn($account);

        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01',
            'amount' => 100.0,
            'description' => 'Test',
            'type' => 'credit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_001');
        $this->duplicateDetector->method('isDuplicate')->willReturn(false);
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);

        $result = $this->service->previewImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(1, $result['totalRows']);
        $this->assertEquals(1, $result['validTransactions']);
        $this->assertEquals(0, $result['duplicates']);
        $this->assertNotEmpty($result['transactions']);
    }

    public function testPreviewSingleAccountSkipsDuplicates(): void {
        $this->mockImportFile('file.csv', 'data');
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '100', 'description' => 'Test'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 100.0, 'description' => 'Test', 'type' => 'credit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_001');
        $this->duplicateDetector->method('isDuplicate')->willReturn(true); // Is duplicate

        $result = $this->service->previewImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(1, $result['duplicates']);
        $this->assertEquals(0, $result['validTransactions']);
    }

    // ===== processImport - single account =====

    public function testProcessSingleAccountImport(): void {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');


        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '100', 'description' => 'Payment'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 100.0, 'description' => 'Payment', 'type' => 'credit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_001');
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);
        $this->transactionService->expects($this->once())->method('create');

        $result = $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals(1, $result['totalProcessed']);
    }

    public function testProcessSingleAccountSkipsDuplicates(): void {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');


        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '100', 'description' => 'Dup'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 100.0, 'description' => 'Dup', 'type' => 'credit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_dup');
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(true);

        // Should NOT create transaction for duplicate
        $this->transactionService->expects($this->never())->method('create');

        $result = $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['skipped']);
    }

    public function testProcessImportCleansUpFile(): void {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');
        $file->expects($this->once())->method('delete'); // Cleanup happens

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([]);
        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));

        $this->service->processImport('user1', 'file.csv', [], 1);
    }

    public function testProcessImportWithoutRules(): void {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');


        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '50', 'description' => 'Test'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 50.0, 'description' => 'Test', 'type' => 'credit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_nr');
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);

        // applyRules should NOT be called when applyRules=false
        $this->ruleApplicator->expects($this->never())->method('applyRules');

        $result = $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1, null, true, false);
        $this->assertEquals(1, $result['imported']);
    }
}

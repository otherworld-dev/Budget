<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\Preset\PresetRegistry;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCA\Budget\Service\ImportAccountLinkService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\TagSetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionTagService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
        // Both import loops re-clamp immediately before the insert (#340);
        // the real implementation is exercised in TransactionNormalizerTest.
        $this->normalizer->method('clampTransactionText')->willReturnArgument(0);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            foreach ($params as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
            }
            return $text;
        });
        $transactionMapper = $this->createMock(TransactionMapper::class);

        $presetRegistry = new PresetRegistry();
        $categoryService = $this->createMock(CategoryService::class);
        $tagSetService = $this->createMock(TagSetService::class);
        $transactionTagService = $this->createMock(TransactionTagService::class);
        $accountService = $this->createMock(AccountService::class);
        $accountLinkService = $this->createMock(ImportAccountLinkService::class);

        $this->service = new ImportService(
            $this->appData,
            $this->transactionService,
            $transactionMapper,
            $this->accountMapper,
            $accountService,
            $this->fileValidator,
            $this->parserFactory,
            $this->normalizer,
            $this->duplicateDetector,
            $this->ruleApplicator,
            $presetRegistry,
            $categoryService,
            $tagSetService,
            $transactionTagService,
            $accountLinkService,
            $this->createMock(\OCA\Budget\Service\BillService::class),
            $l,
            $this->createMock(LoggerInterface::class)
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
        // categorizedCount is emitted and reflects the (uncategorized) row
        $this->assertSame(0, $result['categorizedCount']);
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

    // #340: the CSV branch of the import loop is a different call site from the
    // OFX one below, and nothing asserted it forwarded notes at all — the
    // existing single-account test matches no arguments, so dropping the notes
    // argument from create() would have left the suite green.
    public function testProcessSingleAccountImportStoresMappedNotes(): void {
        $this->mockImportFile('file.csv', 'csv data');
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['Date' => '2026-08-04', 'Amount' => '-12.34', 'Details' => 'Coffee shop', 'Info' => 'Kartenzahlung'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2026-08-04',
            'amount' => 12.34,
            'description' => 'Coffee shop',
            'type' => 'debit',
            'vendor' => 'ACME',
            'reference' => 'REF-9',
            'notes' => 'Kartenzahlung',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_notes');
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);

        $this->transactionService->expects($this->once())
            ->method('create')
            ->with(
                'user1',
                1,
                '2026-08-04',
                'Coffee shop',
                12.34,
                'debit',
                null,
                'ACME',
                'REF-9',
                'Kartenzahlung'
            );

        $result = $this->service->processImport(
            'user1',
            'file.csv',
            ['date' => 'Date', 'amount' => 'Amount', 'description' => 'Details', 'notes' => 'Info'],
            1
        );

        $this->assertEquals(1, $result['imported']);
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

    public function testProcessImportLinksDeferredTransfersById(): void {
        // Regression #314: findPotentialMatches returns Transaction entities,
        // but the deferred-link loop read $match['id'] (array access) — a PHP
        // Error that escaped the catch(\Exception) and surfaced on the import
        // screen after the rows were already saved.
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '100', 'description' => 'Transfer out'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 100.0, 'description' => 'Transfer out', 'type' => 'debit',
            '_deferred_link_transfer' => true,
        ]);
        $this->normalizer->method('generateImportId')->willReturn('imp_tr1');
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);

        $created = new Transaction();
        $created->setId(42);
        $this->transactionService->method('create')->willReturn($created);

        $match = new Transaction();
        $match->setId(99);
        $this->transactionService->method('findPotentialMatches')->willReturn([$match]);

        $this->transactionService->expects($this->once())
            ->method('linkTransactions')
            ->with(42, 99, 'user1');

        $result = $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(1, $result['imported']);
        $this->assertEmpty($result['errors']);
    }

    // ===== occurrence-aware duplicate detection (#276) =====

    public function testProcessImportsIdenticalRowsAsDistinctTransactions(): void {
        // Two legitimate transactions sharing date/amount/description must both
        // import: the second occurrence gets an _occ2-suffixed import ID.
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $row = ['date' => '2025-01-01', 'amount' => '3.50', 'description' => 'Coffee'];
        $this->parserFactory->method('parse')->willReturn([$row, $row]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 3.50, 'description' => 'Coffee', 'type' => 'debit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('hash_abc');

        $checkedIds = [];
        $this->duplicateDetector->method('isDuplicateByImportId')
            ->willReturnCallback(function ($accountId, $importId) use (&$checkedIds) {
                $checkedIds[] = $importId;
                return false;
            });
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);
        $this->transactionService->expects($this->exactly(2))->method('create');

        $result = $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(2, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertSame(['hash_abc', 'hash_abc_occ2'], $checkedIds);
    }

    public function testProcessReimportSkipsAllOccurrences(): void {
        // Re-importing the same file must skip both occurrences (idempotent).
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $row = ['date' => '2025-01-01', 'amount' => '3.50', 'description' => 'Coffee'];
        $this->parserFactory->method('parse')->willReturn([$row, $row]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 3.50, 'description' => 'Coffee', 'type' => 'debit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('hash_abc');
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(true);
        $this->transactionService->expects($this->never())->method('create');

        $result = $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(2, $result['skipped']);
    }

    public function testProcessImportWithSkipDuplicatesDisabledImportsEverything(): void {
        // With "skip duplicates" off, a row whose import ID already exists in
        // the DB must still import — its ID gets a _dupN suffix so neither the
        // create() guard nor the unique index rejects it (#275).
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $this->parserFactory->method('parse')->willReturn([
            ['date' => '2025-01-01', 'amount' => '3.50', 'description' => 'Coffee'],
        ]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 3.50, 'description' => 'Coffee', 'type' => 'debit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('hash_abc');

        // hash_abc is taken (previous import); hash_abc_dup2 is free
        $checkedIds = [];
        $this->duplicateDetector->method('isDuplicateByImportId')
            ->willReturnCallback(function ($accountId, $importId) use (&$checkedIds) {
                $checkedIds[] = $importId;
                return $importId === 'hash_abc';
            });
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);
        $this->transactionService->expects($this->once())->method('create');

        $result = $this->service->processImport(
            'user1', 'file.csv', ['date' => 'date'], 1, null, false /* skipDuplicates */
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertSame(['hash_abc', 'hash_abc_dup2'], $checkedIds);
    }

    public function testProcessImportLeavesFitidImportIdsUntouched(): void {
        // Bank-issued FITIDs are unique per transaction: a repeat genuinely is
        // the same transaction, so no occurrence suffix may be applied.
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('csv data');
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $row = ['date' => '2025-01-01', 'amount' => '3.50', 'description' => 'Coffee'];
        $this->parserFactory->method('parse')->willReturn([$row, $row]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 3.50, 'description' => 'Coffee', 'type' => 'debit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('ofx_fitid_42');

        $checkedIds = [];
        $this->duplicateDetector->method('isDuplicateByImportId')
            ->willReturnCallback(function ($accountId, $importId) use (&$checkedIds) {
                $checkedIds[] = $importId;
                return false;
            });
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);

        $this->service->processImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertSame(['ofx_fitid_42', 'ofx_fitid_42'], $checkedIds);
    }

    public function testPreviewFlagsRepeatedFitidAsDuplicate(): void {
        // A repeated FITID in one file is the same transaction twice — execute
        // will skip the second occurrence, so preview must count it too.
        $this->mockImportFile('file.csv', 'data');
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $row = ['date' => '2025-01-01', 'amount' => '3.50', 'description' => 'Coffee'];
        $this->parserFactory->method('parse')->willReturn([$row, $row]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 3.50, 'description' => 'Coffee', 'type' => 'debit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('ofx_fitid_42');
        $this->duplicateDetector->method('isDuplicate')->willReturn(false); // not in DB
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);

        $result = $this->service->previewImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(1, $result['validTransactions']);
        $this->assertEquals(1, $result['duplicates']);
    }

    public function testPreviewMarksIdenticalRowsAsDistinct(): void {
        // Preview must use the same occurrence-aware IDs as execute so the
        // preview's duplicate count matches what the import will actually do.
        $this->mockImportFile('file.csv', 'data');
        $this->parserFactory->method('detectFormat')->willReturn('csv');
        $row = ['date' => '2025-01-01', 'amount' => '3.50', 'description' => 'Coffee'];
        $this->parserFactory->method('parse')->willReturn([$row, $row]);

        $this->accountMapper->method('find')->willReturn($this->makeAccount(1, 'Checking'));
        $this->normalizer->method('mapRowToTransaction')->willReturn([
            'date' => '2025-01-01', 'amount' => 3.50, 'description' => 'Coffee', 'type' => 'debit',
        ]);
        $this->normalizer->method('generateImportId')->willReturn('hash_abc');

        $checkedIds = [];
        $this->duplicateDetector->method('isDuplicate')
            ->willReturnCallback(function ($accountId, $transaction, $importId) use (&$checkedIds) {
                $checkedIds[] = $importId;
                return false;
            });
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);

        $result = $this->service->previewImport('user1', 'file.csv', ['date' => 'date'], 1);

        $this->assertEquals(2, $result['validTransactions']);
        $this->assertEquals(0, $result['duplicates']);
        $this->assertSame(['hash_abc', 'hash_abc_occ2'], $checkedIds);
    }

    // ===== OFX/QIF column mapping (#338) =====

    /**
     * @param array $txn One parsed OFX row
     */
    private function mockOfxFile(array $txn): void {
        $this->mockImportFile('statement.ofx', 'ofx data');
        $this->parserFactory->method('detectFormat')->willReturn('ofx');
        $this->parserFactory->method('parseFull')->willReturn([
            'accounts' => [
                ['accountId' => '1234567', 'transactions' => [$txn]],
            ],
        ]);
        $this->accountMapper->method('find')->willReturn($this->makeAccount(7, 'Checking'));
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);
        $this->duplicateDetector->method('isDuplicate')->willReturn(false);
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);
        $this->normalizer->method('generateImportId')->willReturn('ofx_fitid_FIT1');
    }

    private function sampleOfxRow(): array {
        return [
            'date' => '2026-07-03',
            'rawAmount' => -42.17,
            'description' => 'POINT OF SALE PURCHASE',
            'memo' => 'SOBEYS #4471 HALIFAX NS',
            'id' => 'FIT1',
        ];
    }

    public function testProcessImportPassesColumnMappingToOfxMapper(): void {
        // #338: the multi-account path dropped the mapping entirely, so
        // choosing "description = memo" did nothing for OFX files.
        $this->mockOfxFile($this->sampleOfxRow());

        $seenMapping = null;
        $this->normalizer->method('mapOfxTransaction')
            ->willReturnCallback(function ($row, $mapping = []) use (&$seenMapping) {
                $seenMapping = $mapping;
                return ['date' => '2026-07-03', 'amount' => 42.17, 'type' => 'debit', 'description' => 'SOBEYS'];
            });
        $this->normalizer->method('ofxImportIdentity')->willReturnArgument(0);

        $this->service->processImport(
            'user1', 'statement.ofx', ['description' => 'memo'], null, ['1234567' => 7]
        );

        $this->assertSame(['description' => 'memo'], $seenMapping);
    }

    public function testPreviewImportPassesColumnMappingToOfxMapper(): void {
        // Preview must agree with execute or the user is shown one thing and
        // gets another.
        $this->mockOfxFile($this->sampleOfxRow());

        $seenMapping = null;
        $this->normalizer->method('mapOfxTransaction')
            ->willReturnCallback(function ($row, $mapping = []) use (&$seenMapping) {
                $seenMapping = $mapping;
                return ['date' => '2026-07-03', 'amount' => 42.17, 'type' => 'debit', 'description' => 'SOBEYS'];
            });
        $this->normalizer->method('ofxImportIdentity')->willReturnArgument(0);

        $this->service->previewImport(
            'user1', 'statement.ofx', ['description' => 'memo'], null, ['1234567' => 7]
        );

        $this->assertSame(['description' => 'memo'], $seenMapping);
    }

    public function testProcessImportDerivesImportIdFromUnmappedRow(): void {
        // The content-hash branch of generateImportId hashes the description.
        // If the mapped value reached it, changing the mapping would re-key
        // every row and re-import the whole statement (#338).
        $row = $this->sampleOfxRow();
        $this->mockOfxFile($row);

        $this->normalizer->method('mapOfxTransaction')->willReturn([
            'date' => '2026-07-03', 'amount' => 42.17, 'type' => 'debit', 'description' => 'SOBEYS',
        ]);
        $this->normalizer->expects($this->once())
            ->method('ofxImportIdentity')
            ->with($row)
            ->willReturn(['description' => 'POINT OF SALE PURCHASE']);

        $seenIdentity = null;
        $this->normalizer->method('generateImportId')
            ->willReturnCallback(function ($fileId, $index, $transaction) use (&$seenIdentity) {
                $seenIdentity = $transaction;
                return 'ofx_fitid_FIT1';
            });

        $this->service->processImport(
            'user1', 'statement.ofx', ['description' => 'memo'], null, ['1234567' => 7]
        );

        $this->assertSame(['description' => 'POINT OF SALE PURCHASE'], $seenIdentity);
    }

    public function testProcessImportStoresMappedDescriptionAndNotes(): void {
        // The memo used to be parsed, previewed, then dropped: create() is
        // handed $transaction['notes'], which nothing populated (#338).
        $this->mockOfxFile($this->sampleOfxRow());

        $this->normalizer->method('mapOfxTransaction')->willReturn([
            'date' => '2026-07-03',
            'amount' => 42.17,
            'type' => 'debit',
            'description' => 'SOBEYS #4471 HALIFAX NS',
            'notes' => 'POINT OF SALE PURCHASE',
        ]);
        $this->normalizer->method('ofxImportIdentity')->willReturnArgument(0);

        $this->transactionService->expects($this->once())
            ->method('create')
            ->with(
                'user1',
                7,
                '2026-07-03',
                'SOBEYS #4471 HALIFAX NS',
                42.17,
                'debit',
                null,
                null,
                null,
                'POINT OF SALE PURCHASE'
            );

        $this->service->processImport(
            'user1', 'statement.ofx', ['description' => 'memo'], null, ['1234567' => 7]
        );
    }

    // ===== QIF multi-account import, and the import source =====

    public function testProcessImportRoutesQifAccountsByTheirParsedIdentity(): void {
        // QifParser emitted no 'accountId', but the shared multi-account loop
        // reads exactly that key — so every QIF account was skipped and a QIF
        // import silently imported nothing at all.
        $this->mockImportFile('statement.qif', 'qif data');
        $this->parserFactory->method('detectFormat')->willReturn('qif');
        $this->parserFactory->method('parseFull')->willReturn([
            'accounts' => [
                [
                    'accountId' => 'My Checking',
                    'name' => 'My Checking',
                    'type' => 'bank',
                    'transactions' => [
                        ['date' => '2026-07-03', 'rawAmount' => -10.0, 'description' => 'Shop', 'id' => 'qif_abc'],
                    ],
                ],
            ],
        ]);
        $this->accountMapper->method('find')->willReturn($this->makeAccount(7, 'Checking'));
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);
        $this->ruleApplicator->method('applyRules')->willReturnArgument(1);
        $this->normalizer->method('mapOfxTransaction')->willReturn([
            'date' => '2026-07-03', 'amount' => 10.0, 'type' => 'debit', 'description' => 'Shop',
        ]);
        $this->normalizer->method('ofxImportIdentity')->willReturnArgument(0);
        $this->normalizer->method('generateImportId')->willReturn('ofx_fitid_qif_abc');

        $this->transactionService->expects($this->once())->method('create');

        $result = $this->service->processImport(
            'user1', 'statement.qif', [], null, ['My Checking' => 7]
        );

        $this->assertEquals(1, $result['imported']);
    }

    public function testProcessImportSetsOfxImportSource(): void {
        // Without a 'source' key CriteriaEvaluator treats the field as absent,
        // so every "Import Source" rule condition was false for OFX and QIF —
        // and true for every negated one.
        $this->mockOfxFile($this->sampleOfxRow());
        $this->normalizer->method('mapOfxTransaction')->willReturn([
            'date' => '2026-07-03', 'amount' => 42.17, 'type' => 'debit', 'description' => 'SOBEYS',
        ]);
        $this->normalizer->method('ofxImportIdentity')->willReturnArgument(0);

        $seenSource = null;
        $this->ruleApplicator->method('applyRules')
            ->willReturnCallback(function ($userId, $transaction) use (&$seenSource) {
                $seenSource = $transaction['source'] ?? null;
                return $transaction;
            });

        $this->service->processImport('user1', 'statement.ofx', [], null, ['1234567' => 7]);

        $this->assertSame('OFX Import', $seenSource);
    }

    public function testProcessImportSetsQifImportSource(): void {
        $this->mockImportFile('statement.qif', 'qif data');
        $this->parserFactory->method('detectFormat')->willReturn('qif');
        $this->parserFactory->method('parseFull')->willReturn([
            'accounts' => [
                [
                    'accountId' => 'My Checking',
                    'transactions' => [['date' => '2026-07-03', 'rawAmount' => -10.0, 'id' => 'qif_abc']],
                ],
            ],
        ]);
        $this->accountMapper->method('find')->willReturn($this->makeAccount(7, 'Checking'));
        $this->duplicateDetector->method('isDuplicateByImportId')->willReturn(false);
        $this->normalizer->method('mapOfxTransaction')->willReturn([
            'date' => '2026-07-03', 'amount' => 10.0, 'type' => 'debit', 'description' => 'Shop',
        ]);
        $this->normalizer->method('ofxImportIdentity')->willReturnArgument(0);
        $this->normalizer->method('generateImportId')->willReturn('ofx_fitid_qif_abc');

        $seenSource = null;
        $this->ruleApplicator->method('applyRules')
            ->willReturnCallback(function ($userId, $transaction) use (&$seenSource) {
                $seenSource = $transaction['source'] ?? null;
                return $transaction;
            });

        $this->service->processImport('user1', 'statement.qif', [], null, ['My Checking' => 7]);

        $this->assertSame('QIF Import', $seenSource);
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

    // ===== countCategorized (#285 audit) =====

    /**
     * The "Auto-categorized" tile must count over the WHOLE parsed set, not the
     * 50-row preview sample. countCategorized counts any transaction carrying a
     * resolved categoryId or a preset _categoryName (treating empty/zero/null
     * as uncategorized).
     */
    public function testCountCategorizedCountsFullSetByCategoryIdOrName(): void {
        $ref = new \ReflectionMethod(ImportService::class, 'countCategorized');
        $ref->setAccessible(true);

        $transactions = [
            ['categoryId' => 5],                       // categorized by id
            ['_categoryName' => 'Groceries'],          // categorized by preset name
            ['categoryId' => null, '_categoryName' => null], // uncategorized
            ['description' => 'no category fields'],    // uncategorized
            ['categoryId' => 0],                        // 0 is empty -> uncategorized
            ['_categoryName' => ''],                    // empty string -> uncategorized
        ];

        $this->assertSame(2, $ref->invoke($this->service, $transactions));
    }
}

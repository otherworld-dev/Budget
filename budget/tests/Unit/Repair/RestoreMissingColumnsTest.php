<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Repair;

use OCA\Budget\Repair\MigrationStepRunner;
use OCA\Budget\Repair\RestoreMissingColumns;
use OCA\Budget\Service\SchemaVersionService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's migrator diffs the whole database against a snapshot taken at
 * the start of each migration step and drops whatever the snapshot did not
 * have, so two app updates running at once (the Apps page's "Update all"
 * fires them in parallel) can remove a column another step just added, with
 * that step already recorded as done (#398, #333, #302, #289). This repair
 * runs after the app's own migrations on every update, enable and
 * maintenance:repair, and puts back what the check finds missing.
 */
class RestoreMissingColumnsTest extends TestCase {
    private SchemaVersionService $schema;
    private MigrationStepRunner $runner;
    private IOutput $output;
    /** @var string[] every info/warning line, in order */
    private array $log = [];

    protected function setUp(): void {
        $this->schema = $this->createMock(SchemaVersionService::class);
        $this->runner = $this->createMock(MigrationStepRunner::class);
        $this->output = $this->createMock(IOutput::class);
        $this->output->method('info')->willReturnCallback(function (string $m): void {
            $this->log[] = 'info: ' . $m;
        });
        $this->output->method('warning')->willReturnCallback(function (string $m): void {
            $this->log[] = 'warning: ' . $m;
        });
    }

    private function step(): RestoreMissingColumns {
        return new RestoreMissingColumns($this->schema, $this->runner);
    }

    public function testNothingMissingLeavesEverythingAlone(): void {
        $this->schema->method('getMissingColumns')->willReturn([]);
        $this->runner->expects($this->never())->method('execute');
        $this->schema->expects($this->never())->method('refresh');

        $this->step()->run($this->output);

        $this->assertSame([], $this->log, 'a clean schema is not worth a line on every update');
    }

    public function testAMissingColumnReRunsTheMigrationThatAddsIt(): void {
        $this->schema->method('getMissingColumns')
            ->willReturnOnConsecutiveCalls(['budget_recurring_income' => ['start_date']], []);
        $this->schema->method('migrationsNaming')
            ->with('budget_recurring_income', 'start_date')
            ->willReturn(['001000098Date20260828']);
        $this->runner->expects($this->once())
            ->method('execute')
            ->with('001000098Date20260828', $this->output);
        // The verified marker was recorded before the column went, so it
        // must not be trusted afterwards.
        $this->schema->expects($this->once())->method('refresh');

        $this->step()->run($this->output);

        $this->assertStringContainsString('001000098Date20260828', $this->log[0]);
        $this->assertStringContainsString('budget_recurring_income.start_date', $this->log[0]);
        $this->assertStringStartsWith('info: ', end($this->log));
    }

    public function testEachMigrationRunsOnceInVersionOrder(): void {
        $this->schema->method('getMissingColumns')
            ->willReturnOnConsecutiveCalls(['budget_bills' => ['paid_undo_state', 'amount_type']], []);
        $this->schema->method('migrationsNaming')->willReturnMap([
            ['budget_bills', 'paid_undo_state', ['001000099Date20260828', '001000094Date20260819']],
            ['budget_bills', 'amount_type', ['001000095Date20260819', '001000094Date20260819']],
        ]);
        $ran = [];
        $this->runner->method('execute')->willReturnCallback(function (string $version) use (&$ran): void {
            $ran[] = $version;
        });

        $this->step()->run($this->output);

        $this->assertSame(['001000094Date20260819', '001000095Date20260819', '001000099Date20260828'], $ran);
    }

    public function testAWholeTableReRunsEveryMigrationNamingIt(): void {
        $this->schema->method('getMissingColumns')
            ->willReturnOnConsecutiveCalls(['budget_projects' => [SchemaVersionService::WHOLE_TABLE]], []);
        $this->schema->method('migrationsNaming')
            ->with('budget_projects', null)
            ->willReturn(['001000103Date20260912']);
        $this->runner->expects($this->once())->method('execute')->with('001000103Date20260912', $this->output);

        $this->step()->run($this->output);

        $this->assertStringContainsString('budget_projects', $this->log[0]);
    }

    /** Nothing on the server adds it: say so rather than run something else. */
    public function testAColumnNoMigrationNamesIsReportedNotGuessed(): void {
        $this->schema->method('getMissingColumns')
            ->willReturnOnConsecutiveCalls(['budget_bills' => ['amount_type']], ['budget_bills' => ['amount_type']]);
        $this->schema->method('migrationsNaming')->willReturn([]);
        $this->runner->expects($this->never())->method('execute');

        $this->step()->run($this->output);

        $this->assertStringStartsWith('warning: ', $this->log[0]);
        $this->assertStringContainsString('budget_bills.amount_type', $this->log[0]);
        $this->assertStringContainsString('reinstall', $this->log[0]);
    }

    public function testAFailingMigrationDoesNotStopTheOthers(): void {
        $this->schema->method('getMissingColumns')
            ->willReturnOnConsecutiveCalls(['budget_bills' => ['amount_type']], []);
        $this->schema->method('migrationsNaming')->willReturn(['001000094Date20260819', '001000095Date20260819']);
        $ran = [];
        $this->runner->method('execute')->willReturnCallback(function (string $version) use (&$ran): void {
            $ran[] = $version;
            if ($version === '001000094Date20260819') {
                throw new \RuntimeException('boom');
            }
        });

        $this->step()->run($this->output);

        $this->assertSame(['001000094Date20260819', '001000095Date20260819'], $ran);
        $warnings = array_values(array_filter($this->log, static fn(string $l): bool => str_starts_with($l, 'warning: ')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('001000094Date20260819', $warnings[0]);
        $this->assertStringContainsString('boom', $warnings[0]);
    }

    /** The step looks again afterwards, from the live schema, and says what it found. */
    public function testWhatIsStillMissingAfterwardsIsReported(): void {
        $this->schema->method('getMissingColumns')
            ->willReturnOnConsecutiveCalls(
                ['budget_bills' => ['amount_type']],
                ['budget_bills' => ['amount_type']]
            );
        $this->schema->method('migrationsNaming')->willReturn(['001000094Date20260819']);

        $this->step()->run($this->output);

        $last = end($this->log);
        $this->assertStringStartsWith('warning: ', $last);
        $this->assertStringContainsString('budget_bills.amount_type', $last);
    }
}

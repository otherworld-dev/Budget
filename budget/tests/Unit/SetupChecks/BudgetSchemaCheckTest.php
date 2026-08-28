<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\SetupChecks;

use OCA\Budget\Service\SchemaVersionService;
use OCA\Budget\SetupChecks\BudgetSchemaCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * SchemaVersionService::getWarning() (#333) was only ever read by
 * PageController, so an admin never saw it unless they happened to be logged
 * in and looking at the app itself. This surfaces the same warning as a
 * proper Nextcloud setup check, so it shows up in Settings > Administration
 * > Overview like any other admin-facing health signal.
 */
class BudgetSchemaCheckTest extends TestCase {
    private SchemaVersionService $schemaVersionService;
    private IL10N $l;
    private BudgetSchemaCheck $check;

    protected function setUp(): void {
        $this->schemaVersionService = $this->createMock(SchemaVersionService::class);
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnCallback(
            static fn(string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
        );

        $this->check = new BudgetSchemaCheck($this->schemaVersionService, $this->l);
    }

    public function testGetCategoryIsDatabase(): void {
        $this->assertSame('database', $this->check->getCategory());
    }

    public function testGetNameIsANonEmptyTranslatedString(): void {
        $this->assertIsString($this->check->getName());
        $this->assertNotSame('', $this->check->getName());
    }

    public function testRunSucceedsWhenNoWarning(): void {
        $this->schemaVersionService->method('getWarning')->willReturn(null);

        $result = $this->check->run();

        $this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
    }

    /**
     * Missing migrations break saves (#333), so a pending schema is an error,
     * not a mere warning — same severity family as other setup checks that
     * flag something actively broken.
     */
    public function testRunReportsErrorCarryingTheMessageWhenMigrationsArePending(): void {
        $this->schemaVersionService->method('getWarning')->willReturn([
            'message' => 'A database update that came with this version of Budget was never applied (1 change is missing). Saving may fail until it is finished.',
            'command' => 'occ app:disable budget && occ app:enable budget',
        ]);

        $result = $this->check->run();

        $this->assertNotSame(SetupResult::SUCCESS, $result->getSeverity());
        $this->assertSame(
            'A database update that came with this version of Budget was never applied (1 change is missing). Saving may fail until it is finished.',
            $result->getDescription()
        );
    }

    /**
     * Companion to BackgroundJobRegistrationTest's approach: a setup check
     * class that exists but is never registered with the bootstrap context
     * just silently never runs.
     */
    public function testIsRegisteredInApplicationBootstrap(): void {
        $appPhp = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

        $this->assertStringContainsString(
            'registerSetupCheck(BudgetSchemaCheck::class)',
            $appPhp,
            'BudgetSchemaCheck must be registered via $context->registerSetupCheck() in Application::register()'
        );
    }
}

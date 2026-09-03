<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\SchemaVersionService;
use PHPUnit\Framework\TestCase;

/**
 * SchemaVersionService::TABLE_ENTITIES is what the schema check walks, so a
 * table left out of it is simply never checked — the same silent failure
 * mode the backup registry had (#351), and the reason that one has a test
 * too. Every Mapper declares its table and entity in one place, its
 * constructor, so the registry is held to exactly that.
 */
class SchemaVersionRegistryTest extends TestCase {
    /** @return array<string, string> table => entity class, read off the mappers */
    private function mapperRegistry(): array {
        $registry = [];
        foreach (glob(__DIR__ . '/../../../lib/Db/*Mapper.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match("/parent::__construct\\(\\\$db,\\s*'([a-z_]+)',\\s*([A-Za-z]+)::class/", $source, $m) !== 1) {
                $this->fail(basename($file) . ' does not declare its table and entity the way every other mapper does');
            }
            $registry[$m[1]] = 'OCA\\Budget\\Db\\' . $m[2];
        }
        ksort($registry);

        return $registry;
    }

    public function testEveryMapperTableIsInTheRegistryAndNothingElseIs(): void {
        $expected = $this->mapperRegistry();
        $actual = SchemaVersionService::TABLE_ENTITIES;
        ksort($actual);

        $this->assertNotEmpty($expected);
        $this->assertSame(
            $expected,
            $actual,
            'SchemaVersionService::TABLE_ENTITIES must list exactly the tables the mappers write, with their entities'
        );
    }

    public function testEveryRegisteredEntityExistsAndCanBeConstructed(): void {
        foreach (SchemaVersionService::TABLE_ENTITIES as $table => $class) {
            $this->assertTrue(class_exists($class), "$table points at a class that does not exist: $class");
            // entityColumns() instantiates it to derive column names
            $this->assertInstanceOf(\OCP\AppFramework\Db\Entity::class, new $class());
        }
    }

    /**
     * The two known non-column properties must not be demanded of the
     * database: no migration names them, so the migration filter drops them.
     */
    public function testKnownTransientPropertiesAreNotExpectedColumns(): void {
        $migrations = '';
        foreach (glob(__DIR__ . '/../../../lib/Migration/Version*.php') ?: [] as $file) {
            $migrations .= file_get_contents($file);
        }

        foreach (['tags', 'category_name'] as $transient) {
            $this->assertStringNotContainsString(
                "'" . $transient . "'",
                $migrations,
                "'$transient' is an entity property that is not a column; if a migration now names it, revisit TagSet/TransactionSplit"
            );
        }
    }
}

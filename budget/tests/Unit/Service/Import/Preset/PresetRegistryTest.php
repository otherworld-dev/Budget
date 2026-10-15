<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Preset;

use OCA\Budget\Service\Import\Preset\ImportPresetInterface;
use OCA\Budget\Service\Import\Preset\PresetRegistry;
use OCA\Budget\Service\Import\Preset\ToshlPreset;
use PHPUnit\Framework\TestCase;

class PresetRegistryTest extends TestCase {
	private PresetRegistry $registry;

	protected function setUp(): void {
		$this->registry = new PresetRegistry();
	}

	// ===== Constructor Registration =====

	public function testConstructorRegistersToshlPreset(): void {
		$preset = $this->registry->get('toshl');
		$this->assertNotNull($preset, 'ToshlPreset should be registered automatically');
		$this->assertInstanceOf(ToshlPreset::class, $preset);
	}

	// ===== get() =====

	public function testGetReturnsToshlPreset(): void {
		$preset = $this->registry->get('toshl');
		$this->assertInstanceOf(ToshlPreset::class, $preset);
		$this->assertSame('toshl', $preset->getId());
	}

	public function testGetReturnsNullForNonexistentPreset(): void {
		$result = $this->registry->get('nonexistent');
		$this->assertNull($result);
	}

	public function testGetReturnsNullForEmptyString(): void {
		$result = $this->registry->get('');
		$this->assertNull($result);
	}

	// ===== getAll() =====

	public function testGetAllReturnsArrayOfPresets(): void {
		$presets = $this->registry->getAll();
		$this->assertIsArray($presets);
		$this->assertCount(6, $presets);
		$this->assertContainsOnlyInstancesOf(ImportPresetInterface::class, $presets);
	}

	public function testGetAllReturnsNumericallyIndexedArray(): void {
		$presets = $this->registry->getAll();
		$this->assertSame(0, array_key_first($presets));
	}

	// ===== toArray() =====

	public function testToArrayReturnsCorrectlyFormattedArray(): void {
		$result = $this->registry->toArray();
		$this->assertIsArray($result);
		$this->assertArrayHasKey('toshl', $result);

		$entry = $result['toshl'];
		$this->assertSame('toshl', $entry['id']);
		$this->assertSame('Toshl Finance', $entry['name']);
		$this->assertSame('Import expenses, income, and categories from Toshl Finance CSV export', $entry['description']);
		$this->assertSame('csv', $entry['format']);
		$this->assertTrue($entry['isPreset']);
	}

	public function testToArrayContainsMapping(): void {
		$result = $this->registry->toArray();
		$entry = $result['toshl'];

		$this->assertArrayHasKey('mapping', $entry);
		$this->assertIsArray($entry['mapping']);
		$this->assertArrayHasKey('date', $entry['mapping']);
		$this->assertArrayHasKey('description', $entry['mapping']);
	}

	public function testToArrayContainsOptions(): void {
		$result = $this->registry->toArray();
		$entry = $result['toshl'];

		$this->assertArrayHasKey('options', $entry);
		$this->assertIsArray($entry['options']);
	}

	public function testToArrayHasAllRequiredKeys(): void {
		$result = $this->registry->toArray();
		$entry = $result['toshl'];

		$expectedKeys = ['id', 'name', 'description', 'format', 'mapping', 'options', 'isPreset'];
		foreach ($expectedKeys as $key) {
			$this->assertArrayHasKey($key, $entry, "toArray entry should contain key '$key'");
		}
	}

	// ===== App-export presets =====

	public function testRegistersEveryAppExportPreset(): void {
		foreach (['firefly-iii', 'ynab', 'actual-budget', 'mint', 'monarch-money'] as $id) {
			$this->assertNotNull($this->registry->get($id), $id);
			$this->assertArrayHasKey($id, $this->registry->toArray());
		}
	}

	/**
	 * @dataProvider fixtureHeaderProvider
	 */
	public function testDetectsEachAppFromItsExportHeader(string $fixture, string $delimiter, string $expected): void {
		$content = (string)file_get_contents(__DIR__ . '/../../../../fixtures/import/' . $fixture);
		$header = str_getcsv(strtok($content, "\r\n"), $delimiter, '"', '');
		$this->assertSame($expected, $this->registry->detect($header));
	}

	public static function fixtureHeaderProvider(): array {
		return [
			'Firefly III' => ['firefly-iii-export.csv', ',', 'firefly-iii'],
			'Firefly III 6.0' => ['firefly-iii-export-v6.0.csv', ',', 'firefly-iii'],
			'YNAB' => ['ynab-register.csv', ',', 'ynab'],
			'YNAB tab-separated' => ['ynab-register-tab.csv', "\t", 'ynab'],
			'YNAB 4' => ['ynab4-register.csv', ',', 'ynab'],
			'Actual Budget' => ['actual-budget-export.csv', ',', 'actual-budget'],
			'Mint' => ['mint-transactions.csv', ',', 'mint'],
			'Monarch Money' => ['monarch-transactions.csv', ',', 'monarch-money'],
		];
	}

	public function testDetectsToshlOnlyFromItsExactEnglishHeader(): void {
		$toshl = ['Date', 'Account', 'Category', 'Tags', 'Expense', 'Income', 'Currency', 'In Main Currency', 'Main Currency', 'Description'];
		$this->assertSame('toshl', $this->registry->detect($toshl));
		$this->assertSame('toshl', $this->registry->detect(array_map('strtoupper', $toshl)));
		$this->assertNull($this->registry->detect(array_merge($toshl, ['Extra'])));
	}

	public function testDetectsNothingInAnOrdinaryBankExport(): void {
		$this->assertNull($this->registry->detect(['Date', 'Description', 'Amount', 'Balance']));
		$this->assertNull($this->registry->detect(['Account', 'Date', 'Payee', 'Amount']));
		$this->assertNull($this->registry->detect([]));
	}

	public function testHeaderMatchingIgnoresCaseAndPadding(): void {
		$this->assertSame('mint', $this->registry->detect([' date', 'DESCRIPTION', 'Original Description ', 'Amount', 'transaction type', 'Category', 'Account Name']));
	}
}

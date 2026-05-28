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
		$this->assertCount(1, $presets);
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
}

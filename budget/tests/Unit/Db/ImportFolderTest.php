<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ImportFolder;
use PHPUnit\Framework\TestCase;

class ImportFolderTest extends TestCase {
	public function testSerializesForTheSettingsPage(): void {
		$folder = new ImportFolder();
		$folder->setId(7);
		$folder->setUserId('alice');
		$folder->setName('Conto Mario');
		$folder->setAccountId(3);
		$folder->setTemplateId(null);
		$folder->setMode('auto');
		$folder->setEnabled(true);
		$folder->setCreatedAt('2026-10-12 09:00:00');

		$this->assertSame([
			'id' => 7,
			'name' => 'Conto Mario',
			'accountId' => 3,
			'templateId' => null,
			'mode' => 'auto',
			'enabled' => true,
			'createdAt' => '2026-10-12 09:00:00',
			'updatedAt' => null,
		], $folder->jsonSerialize());
	}

	public function testARowReadFromTheDatabaseWithoutAFlagCountsAsEnabled(): void {
		$folder = new ImportFolder();
		$this->assertTrue($folder->jsonSerialize()['enabled']);
		$this->assertSame('auto', $folder->jsonSerialize()['mode']);
	}
}

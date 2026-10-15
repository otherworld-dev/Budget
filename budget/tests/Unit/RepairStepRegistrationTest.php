<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit;

use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;

/**
 * Every repair step in lib/Repair/ must be listed in appinfo/info.xml, and
 * every step info.xml lists must exist: Nextcloud resolves them by name at
 * update time, and a step that is not registered simply never runs, which
 * for RestoreMissingColumns would leave every affected instance unrepaired
 * with nothing to say so (#398).
 */
class RepairStepRegistrationTest extends TestCase {
	private const REPAIR_DIR = __DIR__ . '/../../lib/Repair';
	private const INFO_XML = __DIR__ . '/../../appinfo/info.xml';

	public function testEveryRepairStepIsRegisteredAsPostMigration(): void {
		$infoXml = file_get_contents(self::INFO_XML);
		$registered = [];
		if (preg_match('#<post-migration>(.*?)</post-migration>#s', $infoXml, $m) === 1
			&& preg_match_all('#<step>(.*?)</step>#', $m[1], $steps) > 0) {
			$registered = $steps[1];
		}

		$steps = [];
		foreach (glob(self::REPAIR_DIR . '/*.php') ?: [] as $file) {
			$fqcn = 'OCA\\Budget\\Repair\\' . pathinfo($file, PATHINFO_FILENAME);
			if (is_subclass_of($fqcn, IRepairStep::class)) {
				$steps[] = $fqcn;
			}
		}

		$this->assertNotEmpty($steps, 'No repair step found in lib/Repair');
		$this->assertSame([], array_values(array_diff($steps, $registered)), 'Repair steps missing from info.xml <post-migration>');
		$this->assertSame([], array_values(array_diff($registered, $steps)), 'info.xml names a repair step that does not exist');
	}
}

<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verify that image/asset references in PHP source code
 * point to files that actually exist in the img/ directory.
 */
class AssetReferenceTest extends TestCase {
	private const LIB_DIR = __DIR__ . '/../../lib';
	private const IMG_DIR = __DIR__ . '/../../img';

	/**
	 * Scan all PHP files in lib/ for imagePath() calls and verify
	 * the referenced image files exist.
	 */
	public function testAllImagePathReferencesExist(): void {
		$violations = [];
		$phpFiles = $this->getPhpFiles(self::LIB_DIR);

		foreach ($phpFiles as $file) {
			$content = file_get_contents($file);
			$relPath = str_replace(realpath(self::LIB_DIR) . DIRECTORY_SEPARATOR, '', realpath($file));

			// Match imagePath(Application::APP_ID, 'filename') or imagePath('budget', 'filename')
			preg_match_all(
				'/imagePath\(\s*(?:Application::APP_ID|[\'"]budget[\'"])\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
				$content,
				$matches
			);

			foreach ($matches[1] as $imageName) {
				$imagePath = self::IMG_DIR . '/' . $imageName;
				if (!file_exists($imagePath)) {
					$violations[] = sprintf(
						'Missing image "%s" referenced in %s',
						$imageName, $relPath
					);
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"Image references point to non-existent files in img/:\n- " . implode("\n- ", $violations)
		);
	}

	private function getPhpFiles(string $dir): array {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		$files = [];
		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}
}

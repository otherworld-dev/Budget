<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against broken front-end asset references.
 *
 * Every `script('budget', 'name')` / `style('budget', 'name')` call in a
 * template — and every `Util::addScript()` / `Util::addStyle()` call in a
 * controller — must point at a bundle the build actually produces
 * (js/name.js or css/name.css). A mismatch (e.g. a half-finished bundle
 * rename) otherwise fails silently as a 404 that is only visible in the
 * browser, and only on a clean checkout where the stale, git-ignored
 * artifact is absent.
 *
 * This is exactly the failure that left a dangling `style('budget',
 * 'budget-main')` after the bundle was renamed to `budget-app`.
 */
class AssetReferencesTest extends TestCase {
	private string $appRoot;

	protected function setUp(): void {
		$this->appRoot = dirname(__DIR__, 2);
	}

	/**
	 * @return array<string, array{0: string, 1: string}> label => [ref-name, relative-path]
	 */
	private function collectReferences(): array {
		$refs = [];

		// Templates: script('budget', 'x') and style('budget', 'x')
		foreach (glob($this->appRoot . '/templates/*.php') as $template) {
			$contents = file_get_contents($template);
			$name = basename($template);

			if (preg_match_all("/\\bscript\\(\\s*'budget'\\s*,\\s*'([^']+)'\\s*\\)/", $contents, $m)) {
				foreach ($m[1] as $ref) {
					$refs["$name: script('$ref')"] = [$ref, "js/$ref.js"];
				}
			}
			if (preg_match_all("/\\bstyle\\(\\s*'budget'\\s*,\\s*'([^']+)'\\s*\\)/", $contents, $m)) {
				foreach ($m[1] as $ref) {
					$refs["$name: style('$ref')"] = [$ref, "css/$ref.css"];
				}
			}
		}

		// Controllers: Util::addScript(APP_ID, 'x') and Util::addStyle(APP_ID, 'x')
		foreach (glob($this->appRoot . '/lib/Controller/*.php') as $controller) {
			$contents = file_get_contents($controller);
			$name = basename($controller);

			if (preg_match_all("/addScript\\(\\s*[^,]+,\\s*'([^']+)'\\s*\\)/", $contents, $m)) {
				foreach ($m[1] as $ref) {
					$refs["$name: addScript('$ref')"] = [$ref, "js/$ref.js"];
				}
			}
			if (preg_match_all("/addStyle\\(\\s*[^,]+,\\s*'([^']+)'\\s*\\)/", $contents, $m)) {
				foreach ($m[1] as $ref) {
					$refs["$name: addStyle('$ref')"] = [$ref, "css/$ref.css"];
				}
			}
		}

		return $refs;
	}

	public function testTemplatesAndControllersOnlyReferenceExistingBundles(): void {
		$refs = $this->collectReferences();

		// Sanity: the scan must actually find the known entry points,
		// otherwise a future syntax change would make this test vacuous.
		$this->assertNotEmpty($refs, 'No asset references were found to validate — has the scan regex gone stale?');

		$missing = [];
		foreach ($refs as $label => [$ref, $relPath]) {
			if (!file_exists($this->appRoot . '/' . $relPath)) {
				$missing[] = "$label -> $relPath";
			}
		}

		$this->assertSame(
			[],
			$missing,
			"Front-end asset reference(s) point at files that do not exist:\n  " . implode("\n  ", $missing)
				. "\n\nEither the bundle was renamed (update the script()/style() call) or the build "
				. 'artifact is missing (run `npm run build`).'
		);
	}

	public function testNoLingeringBudgetMainReferences(): void {
		// The bundle was renamed budget-main -> budget-app; nothing user-facing
		// should still reference the old name.
		$files = array_merge(
			glob($this->appRoot . '/templates/*.php'),
			glob($this->appRoot . '/lib/Controller/*.php')
		);

		$offenders = [];
		foreach ($files as $file) {
			$contents = file_get_contents($file);
			if (preg_match("/(?:script|style|addScript|addStyle)\\([^)]*'budget-main'/", $contents)) {
				$offenders[] = basename($file);
			}
		}

		$this->assertSame([], $offenders, "Stale 'budget-main' bundle reference(s) found in: " . implode(', ', $offenders));
	}
}

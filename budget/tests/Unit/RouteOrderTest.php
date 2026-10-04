<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's router takes the first route that matches, and a {param}
 * without requirements matches any single segment. A literal path declared
 * after a {param} route with the same verb and prefix is never reached:
 * GET /api/accounts/summary and /api/accounts/banking-institutions sat
 * behind /api/accounts/{id} and answered 404 "Account not found", so the
 * institution autocomplete on the account form never showed a suggestion.
 */
class RouteOrderTest extends TestCase {
	private const ROUTES = __DIR__ . '/../../appinfo/routes.php';

	public function testNoRouteIsHiddenBehindAnEarlierOne(): void {
		$routes = require self::ROUTES;

		$hidden = [];
		foreach (['routes', 'ocs'] as $block) {
			$list = $routes[$block] ?? [];
			foreach ($list as $i => $route) {
				$verb = strtoupper($route['verb'] ?? 'GET');
				foreach (array_slice($list, 0, $i) as $earlier) {
					if (strtoupper($earlier['verb'] ?? 'GET') !== $verb) {
						continue;
					}
					// A URL only this route should answer, its params filled in
					if (preg_match(self::pattern($earlier), self::sample($route)) === 1) {
						$hidden[] = "{$block}: {$verb} {$route['url']} ({$route['name']}) is answered by {$earlier['url']} ({$earlier['name']})";
						break;
					}
				}
			}
		}

		$this->assertSame([], $hidden);
	}

	/** The routes this test exists for, reachable again */
	public function testTheAccountLiteralsComeBeforeTheAccountId(): void {
		$urls = array_map(
			static fn (array $r): string => strtoupper($r['verb'] ?? 'GET') . ' ' . $r['url'],
			(require self::ROUTES)['routes']
		);
		$show = array_search('GET /api/accounts/{id}', $urls, true);

		$this->assertLessThan($show, array_search('GET /api/accounts/summary', $urls, true));
		$this->assertLessThan($show, array_search('GET /api/accounts/banking-institutions', $urls, true));
	}

	private static function pattern(array $route): string {
		$requirements = $route['requirements'] ?? [];
		$parts = preg_split('/(\{[^}]+\})/', $route['url'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$regex = '';
		foreach ($parts as $part) {
			$regex .= preg_match('/^\{([^}]+)\}$/', $part, $m) === 1
				? '(?:' . ($requirements[$m[1]] ?? '[^/]+') . ')'
				: preg_quote($part, '#');
		}

		return '#^' . $regex . '$#';
	}

	private static function sample(array $route): string {
		return (string)preg_replace('/\{[^}]+\}/', 'p1aceh0lder', $route['url']);
	}
}

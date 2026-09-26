<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

/**
 * Turns a rule's regex pattern into the PCRE string that preg_match takes.
 *
 * A rule's pattern is either bare (`amazon|ebay`), matched case-insensitively,
 * or a full `/pattern/flags` literal whose flags are used as written. The
 * builder, the JSON editor and the rule API all save through this, and rules
 * run through it, so what is accepted and what matches can't drift apart.
 */
final class RegexPattern {
	/**
	 * @return string|null The PCRE pattern, or null for an empty pattern.
	 *                     A non-null result can still be an invalid regex:
	 *                     callers check preg_match for false.
	 */
	public static function toPcre(string $pattern): ?string {
		$trimmed = trim($pattern);
		if ($trimmed === '') {
			return null;
		}

		if (preg_match('#^/(.*)/([a-zA-Z]*)$#s', $trimmed) === 1) {
			return $trimmed;
		}

		// A bare pattern is used as saved: its spaces are part of it, and
		// trimming them changed what existing rules like "^TFR " matched.
		return '/' . $pattern . '/i';
	}

	public static function isValid(string $pattern): bool {
		$pcre = self::toPcre($pattern);
		return $pcre !== null && @preg_match($pcre, '') !== false;
	}
}

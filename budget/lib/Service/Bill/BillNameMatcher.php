<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

/**
 * Whether a bank row's text names a bill, for bills with no auto-detect
 * pattern of their own.
 *
 * A bill's pre-booked payment carries the bill's name as its payee, and the
 * bank's own row of the same payment carries the bank's text: "Octopus
 * Energy" against "OCTOPUS ENERGY", "Claude.ai" against "ANTHROPIC* CLAUDE",
 * "Dwr Cymru Welsh Wa" against "DWR CYMRU WELSH WATER". Both are lowercased
 * and cut into words at anything that isn't a letter or a digit. The bill
 * matches when every word of its name is in the text, or one of them of
 * four letters or more is, and isn't a word every bank row uses. A word
 * matches a whole word, or one is the start of the other when the shorter
 * has three letters or more, since banks cut payees short. A word matching
 * on its own has to be there whole or cut short by the bank.
 *
 * Whole words, never substrings: a bill called "Ee" is "EE LIMITED", not
 * "COFFEE". This is the loose half of the match; the caller holds the
 * amount to the penny and the date to a few days, and leaves a row that
 * could be two payments to the user.
 */
final class BillNameMatcher {
	/** Words too common in bank text to name a payee on their own */
	private const GENERIC = [
		'limited', 'bank', 'card', 'payment', 'payments', 'direct', 'debit', 'transfer',
		'online', 'from', 'account', 'services', 'standing', 'order', 'bill', 'bills',
	];

	public static function matches(string $billName, string ...$texts): bool {
		$name = self::words($billName);
		if ($name === []) {
			return false;
		}
		$words = [];
		foreach ($texts as $text) {
			$words = [...$words, ...self::words($text)];
		}
		if ($words === []) {
			return false;
		}

		$all = true;
		foreach ($name as $word) {
			$found = self::contains($words, $word, false);
			if ($found && mb_strlen($word) >= 4 && !in_array($word, self::GENERIC, true)
				&& self::contains($words, $word, true)) {
				return true;
			}
			$all = $all && $found;
		}
		return $all;
	}

	/** @return string[] */
	public static function words(string $text): array {
		$plain = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text, 'UTF-8')));
		return $plain === '' ? [] : explode(' ', $plain);
	}

	/**
	 * @param string[] $words the bank text's words
	 * @param bool $alone the word has to name the payee by itself: only the
	 *                    bank cutting it short counts, never the bill's word
	 *                    starting a longer one ("plan" isn't "PLANET")
	 */
	private static function contains(array $words, string $word, bool $alone): bool {
		foreach ($words as $candidate) {
			if ($candidate === $word) {
				return true;
			}
			$bankShorter = mb_strlen($candidate) < mb_strlen($word);
			[$short, $long] = $bankShorter ? [$candidate, $word] : [$word, $candidate];
			if (($bankShorter || !$alone) && mb_strlen($short) >= ($alone ? 4 : 3) && str_starts_with($long, $short)) {
				return true;
			}
		}
		return false;
	}
}

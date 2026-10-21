<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\SettingMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The per-user money-in / money-out colour setting (#432).
 *
 * Two settings hold it: `amount_color_scheme` (default | colorblind | custom)
 * and `amount_color_custom` (JSON with exactly four #rrggbb colours). The
 * on-screen colours are applied by the frontend; this class owns validation,
 * the colour-blind preset and the one place the server draws amounts, the PDF
 * export.
 *
 * The preset values are mirrored in the frontend, and a test cross-checks
 * them. Every stored value is untrusted (old or tampered backups), so anything
 * that doesn't parse falls back to Default rather than throwing.
 */
class AmountColours {

	public const SETTING_SCHEME = 'amount_color_scheme';
	public const SETTING_CUSTOM = 'amount_color_custom';

	public const SCHEMES = ['default', 'colorblind', 'custom'];

	/** Every value is at least 4.5:1 on its mode's surfaces (white, #171717, #292929). */
	public const COLORBLIND = [
		'light' => ['positive' => '#0b62a4', 'negative' => '#a84800', 'warning' => '#8a6a00'],
		'dark' => ['positive' => '#6cb4ee', 'negative' => '#f5a35c', 'warning' => '#f0e442'],
	];

	/** The preset's positive/negative colours as canonical custom JSON. */
	public const DEFAULT_CUSTOM_JSON = '{"lightPositive":"#0b62a4","lightNegative":"#a84800","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}';

	/** The key order of the canonical custom JSON. */
	private const CUSTOM_KEYS = ['lightPositive', 'lightNegative', 'darkPositive', 'darkNegative'];

	/** What the PDF has always drawn negative amounts in. */
	private const PDF_DEFAULT_NEGATIVE = [200, 0, 0];

	private const MIN_CONTRAST = 4.5;

	/** Lightness drop per darkening step, in HSL percentage points. */
	private const DARKEN_STEP = 2.0;

	public function __construct(
		private SettingMapper $settings,
	) {
	}

	public static function normalizeScheme(?string $value): string {
		return in_array($value, self::SCHEMES, true) ? $value : 'default';
	}

	/**
	 * The four colours, lowercase, in canonical key order, or null when the
	 * JSON is anything other than exactly those four #rrggbb strings.
	 *
	 * @return array{lightPositive: string, lightNegative: string, darkPositive: string, darkNegative: string}|null
	 */
	public static function parseCustom(?string $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}
		$data = json_decode($json, true);
		if (!is_array($data) || count($data) !== count(self::CUSTOM_KEYS)) {
			return null;
		}
		$out = [];
		foreach (self::CUSTOM_KEYS as $key) {
			if (!isset($data[$key]) || !is_string($data[$key])
				|| preg_match('/^#[0-9a-fA-F]{6}$/D', $data[$key]) !== 1) {
				return null;
			}
			$out[$key] = strtolower($data[$key]);
		}
		return $out;
	}

	/**
	 * @throws \InvalidArgumentException when the JSON is not a valid custom palette
	 */
	public static function validateCustom(string $json): string {
		$parsed = self::parseCustom($json);
		if ($parsed === null) {
			throw new \InvalidArgumentException('Custom colours must be four #rrggbb values: ' . implode(', ', self::CUSTOM_KEYS));
		}
		return json_encode($parsed, JSON_THROW_ON_ERROR);
	}

	/** WCAG 2.x contrast ratio between two #rrggbb colours (1.0 to 21.0). */
	public static function contrastRatio(string $hexA, string $hexB): float {
		$a = self::relativeLuminance(self::hexToRgb($hexA));
		$b = self::relativeLuminance(self::hexToRgb($hexB));
		return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
	}

	/**
	 * The colour the PDF draws negative amounts in. Default (and any invalid
	 * custom value) stays the old red; a custom colour is darkened, hue kept,
	 * until it reads on white paper.
	 *
	 * @return array{0: int, 1: int, 2: int}
	 */
	public static function pdfNegativeRgb(string $scheme, ?string $customJson): array {
		switch (self::normalizeScheme($scheme)) {
			case 'colorblind':
				return self::hexToRgb(self::COLORBLIND['light']['negative']);
			case 'custom':
				$custom = self::parseCustom($customJson);
				if ($custom === null) {
					return self::PDF_DEFAULT_NEGATIVE;
				}
				return self::darkenForWhite($custom['lightNegative']);
			default:
				return self::PDF_DEFAULT_NEGATIVE;
		}
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	public function pdfNegativeRgbForUser(string $userId): array {
		return self::pdfNegativeRgb(
			self::normalizeScheme($this->read($userId, self::SETTING_SCHEME)),
			// A palette that was never saved is the preset, as the settings API reports it
			$this->read($userId, self::SETTING_CUSTOM) ?? self::DEFAULT_CUSTOM_JSON,
		);
	}

	private function read(string $userId, string $key): ?string {
		try {
			return $this->settings->findByKey($userId, $key)->getValue();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Lower the HSL lightness in small steps until the colour has 4.5:1
	 * contrast on white. Always ends: black passes.
	 *
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function darkenForWhite(string $hex): array {
		// An already-readable colour is returned exactly as chosen, not
		// round-tripped through HSL.
		if (self::contrastRatio($hex, '#ffffff') >= self::MIN_CONTRAST) {
			return self::hexToRgb($hex);
		}
		[$h, $s, $l] = self::rgbToHsl(self::hexToRgb($hex));
		do {
			$l = max(0.0, $l - self::DARKEN_STEP);
			$rgb = self::hslToRgb($h, $s, $l);
		} while ($l > 0.0 && self::contrastRatio(self::rgbToHex($rgb), '#ffffff') < self::MIN_CONTRAST);
		return $rgb;
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function hexToRgb(string $hex): array {
		$hex = ltrim($hex, '#');
		return [
			(int)hexdec(substr($hex, 0, 2)),
			(int)hexdec(substr($hex, 2, 2)),
			(int)hexdec(substr($hex, 4, 2)),
		];
	}

	/**
	 * @param array{0: int, 1: int, 2: int} $rgb
	 */
	private static function rgbToHex(array $rgb): string {
		return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
	}

	/**
	 * @param array{0: int, 1: int, 2: int} $rgb
	 */
	private static function relativeLuminance(array $rgb): float {
		$lin = array_map(static function (int $c): float {
			$v = $c / 255;
			return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
		}, $rgb);
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	/**
	 * @param array{0: int, 1: int, 2: int} $rgb
	 * @return array{0: float, 1: float, 2: float} hue 0-360, saturation 0-100, lightness 0-100
	 */
	private static function rgbToHsl(array $rgb): array {
		[$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, $rgb);
		$max = max($r, $g, $b);
		$min = min($r, $g, $b);
		$l = ($max + $min) / 2;
		$d = $max - $min;
		if ($d == 0.0) {
			return [0.0, 0.0, $l * 100];
		}
		$s = $d / (1 - abs(2 * $l - 1));
		if ($max === $r) {
			$h = fmod(($g - $b) / $d, 6);
		} elseif ($max === $g) {
			$h = ($b - $r) / $d + 2;
		} else {
			$h = ($r - $g) / $d + 4;
		}
		$h *= 60;
		if ($h < 0) {
			$h += 360;
		}
		return [$h, $s * 100, $l * 100];
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function hslToRgb(float $h, float $s, float $l): array {
		$s /= 100;
		$l /= 100;
		$c = (1 - abs(2 * $l - 1)) * $s;
		$x = $c * (1 - abs(fmod($h / 60, 2) - 1));
		$m = $l - $c / 2;
		[$r, $g, $b] = match (true) {
			$h < 60 => [$c, $x, 0.0],
			$h < 120 => [$x, $c, 0.0],
			$h < 180 => [0.0, $c, $x],
			$h < 240 => [0.0, $x, $c],
			$h < 300 => [$x, 0.0, $c],
			default => [$c, 0.0, $x],
		};
		return [
			(int)round(($r + $m) * 255),
			(int)round(($g + $m) * 255),
			(int)round(($b + $m) * 255),
		];
	}
}

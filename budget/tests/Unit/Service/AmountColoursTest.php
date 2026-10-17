<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Service\AmountColours;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountColoursTest extends TestCase {

	private function setting(string $value): Setting {
		$s = new Setting();
		$s->setValue($value);
		return $s;
	}

	public function testNormalizeSchemeFallsBackToDefault(): void {
		$this->assertSame('default', AmountColours::normalizeScheme(null));
		$this->assertSame('default', AmountColours::normalizeScheme(''));
		$this->assertSame('default', AmountColours::normalizeScheme('pink'));
		$this->assertSame('default', AmountColours::normalizeScheme('Custom'));
		$this->assertSame('custom', AmountColours::normalizeScheme('custom'));
		$this->assertSame('colorblind', AmountColours::normalizeScheme('colorblind'));
		$this->assertSame('default', AmountColours::normalizeScheme('default'));
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function malformedCustomProvider(): array {
		$ok = ['lightPositive' => '#0b62a4', 'lightNegative' => '#a84800', 'darkPositive' => '#6cb4ee', 'darkNegative' => '#f5a35c'];
		return [
			'null' => [null],
			'empty' => [''],
			'missing key' => [json_encode(array_slice($ok, 0, 3))],
			'short hex' => [json_encode(['lightPositive' => '#fff'] + $ok)],
			'colour name' => [json_encode(['lightPositive' => 'red'] + $ok)],
			'bad hex digit' => [json_encode(['lightPositive' => '#12345g'] + $ok)],
			'no hash' => [json_encode(['lightPositive' => '0b62a4'] + $ok)],
			'eight digit hex' => [json_encode(['lightPositive' => '#0b62a4ff'] + $ok)],
			'extra key' => [json_encode($ok + ['extra' => '#000000'])],
			'non-string value' => [json_encode(['lightPositive' => 123456] + $ok)],
			'not json' => ['not json'],
			'json scalar' => ['"#0b62a4"'],
			'json list' => ['["#0b62a4"]'],
			'script' => ['<script>'],
		];
	}

	#[DataProvider('malformedCustomProvider')]
	public function testParseCustomRejectsMalformed(?string $json): void {
		$this->assertNull(AmountColours::parseCustom($json));
	}

	public function testParseCustomReturnsLowercaseInFixedOrder(): void {
		$parsed = AmountColours::parseCustom(json_encode([
			'darkNegative' => '#F5A35C',
			'lightPositive' => '#0B62A4',
			'darkPositive' => '#6CB4EE',
			'lightNegative' => '#A84800',
		]));
		$this->assertSame([
			'lightPositive' => '#0b62a4',
			'lightNegative' => '#a84800',
			'darkPositive' => '#6cb4ee',
			'darkNegative' => '#f5a35c',
		], $parsed);
	}

	public function testValidateCustomCanonicalises(): void {
		$out = AmountColours::validateCustom(json_encode([
			'darkNegative' => '#F5A35C',
			'lightPositive' => '#0B62A4',
			'darkPositive' => '#6CB4EE',
			'lightNegative' => '#A84800',
		]));
		$this->assertSame(
			'{"lightPositive":"#0b62a4","lightNegative":"#a84800","darkPositive":"#6cb4ee","darkNegative":"#f5a35c"}',
			$out
		);
	}

	public function testValidateCustomThrowsOnBadInput(): void {
		$this->expectException(\InvalidArgumentException::class);
		AmountColours::validateCustom('{"lightPositive":"red"}');
	}

	public function testValidateCustomThrowsOnNonJson(): void {
		$this->expectException(\InvalidArgumentException::class);
		AmountColours::validateCustom('<script>');
	}

	public function testDefaultCustomJsonIsThePreset(): void {
		$c = AmountColours::COLORBLIND;
		$this->assertSame([
			'lightPositive' => $c['light']['positive'],
			'lightNegative' => $c['light']['negative'],
			'darkPositive' => $c['dark']['positive'],
			'darkNegative' => $c['dark']['negative'],
		], AmountColours::parseCustom(AmountColours::DEFAULT_CUSTOM_JSON));
		$this->assertSame(
			AmountColours::DEFAULT_CUSTOM_JSON,
			AmountColours::validateCustom(AmountColours::DEFAULT_CUSTOM_JSON)
		);
	}

	public function testContrastRatioKnownValues(): void {
		$this->assertEqualsWithDelta(21.0, AmountColours::contrastRatio('#000000', '#ffffff'), 0.001);
		$this->assertEqualsWithDelta(1.0, AmountColours::contrastRatio('#777777', '#777777'), 0.001);
		$this->assertEqualsWithDelta(
			AmountColours::contrastRatio('#ffffff', '#000000'),
			AmountColours::contrastRatio('#000000', '#ffffff'),
			0.0001
		);
		// #767676 on white is the well-known 4.54:1 grey.
		$this->assertEqualsWithDelta(4.54, AmountColours::contrastRatio('#767676', '#ffffff'), 0.01);
	}

	public function testPresetMeetsContrast(): void {
		foreach (AmountColours::COLORBLIND['light'] as $name => $hex) {
			$this->assertGreaterThanOrEqual(4.5, AmountColours::contrastRatio($hex, '#ffffff'), "light $name");
		}
		foreach (AmountColours::COLORBLIND['dark'] as $name => $hex) {
			$this->assertGreaterThanOrEqual(4.5, AmountColours::contrastRatio($hex, '#171717'), "dark $name on #171717");
			$this->assertGreaterThanOrEqual(4.5, AmountColours::contrastRatio($hex, '#292929'), "dark $name on #292929");
		}
	}

	public function testPdfNegativeDefaultIsUnchanged(): void {
		$this->assertSame([200, 0, 0], AmountColours::pdfNegativeRgb('default', null));
		$this->assertSame([200, 0, 0], AmountColours::pdfNegativeRgb('custom', 'not json'));
		$this->assertSame([200, 0, 0], AmountColours::pdfNegativeRgb('custom', null));
		$this->assertSame([200, 0, 0], AmountColours::pdfNegativeRgb('pink', null));
	}

	public function testPdfNegativeColourblind(): void {
		$this->assertSame([168, 72, 0], AmountColours::pdfNegativeRgb('colorblind', null));
	}

	private function customJson(string $lightNegative): string {
		return json_encode([
			'lightPositive' => '#0b62a4',
			'lightNegative' => $lightNegative,
			'darkPositive' => '#6cb4ee',
			'darkNegative' => '#f5a35c',
		]);
	}

	public function testPdfNegativeDarkensFaintCustom(): void {
		[$r, $g, $b] = AmountColours::pdfNegativeRgb('custom', $this->customJson('#fefefe'));
		$hex = sprintf('#%02x%02x%02x', $r, $g, $b);
		$this->assertGreaterThanOrEqual(4.5, AmountColours::contrastRatio($hex, '#ffffff'));
		$this->assertNotSame('#fefefe', $hex);

		$this->assertSame([168, 72, 0], AmountColours::pdfNegativeRgb('custom', $this->customJson('#a84800')));
	}

	public function testPdfNegativeDarkeningKeepsTheHue(): void {
		// A pale red must stay red-dominant after darkening.
		[$r, $g, $b] = AmountColours::pdfNegativeRgb('custom', $this->customJson('#ff9999'));
		$hex = sprintf('#%02x%02x%02x', $r, $g, $b);
		$this->assertGreaterThanOrEqual(4.5, AmountColours::contrastRatio($hex, '#ffffff'));
		$this->assertGreaterThan($g, $r);
		$this->assertEqualsWithDelta($g, $b, 2);
	}

	public function testPdfNegativeDarkensPureWhiteAndTerminates(): void {
		[$r, $g, $b] = AmountColours::pdfNegativeRgb('custom', $this->customJson('#ffffff'));
		$hex = sprintf('#%02x%02x%02x', $r, $g, $b);
		$this->assertGreaterThanOrEqual(4.5, AmountColours::contrastRatio($hex, '#ffffff'));
	}

	public function testForUserReadsSettings(): void {
		$mapper = $this->createMock(SettingMapper::class);
		$mapper->method('findByKey')->willReturnCallback(
			fn (string $userId, string $key) => match ($key) {
				'amount_color_scheme' => $this->setting('colorblind'),
				default => throw new DoesNotExistException('none'),
			}
		);
		$this->assertSame([168, 72, 0], (new AmountColours($mapper))->pdfNegativeRgbForUser('alice'));
	}

	public function testForUserReadsCustomSettings(): void {
		$mapper = $this->createMock(SettingMapper::class);
		$mapper->method('findByKey')->willReturnCallback(
			fn (string $userId, string $key) => match ($key) {
				'amount_color_scheme' => $this->setting('custom'),
				'amount_color_custom' => $this->setting($this->customJson('#a84800')),
				default => throw new DoesNotExistException('none'),
			}
		);
		$this->assertSame([168, 72, 0], (new AmountColours($mapper))->pdfNegativeRgbForUser('alice'));
	}

	public function testForUserMissingSettingsIsDefault(): void {
		$mapper = $this->createMock(SettingMapper::class);
		$mapper->method('findByKey')->willThrowException(new DoesNotExistException('none'));
		$this->assertSame([200, 0, 0], (new AmountColours($mapper))->pdfNegativeRgbForUser('alice'));
	}

	public function testForUserGarbageStoredValuesAreDefault(): void {
		$mapper = $this->createMock(SettingMapper::class);
		$mapper->method('findByKey')->willReturnCallback(
			fn (string $userId, string $key) => match ($key) {
				'amount_color_scheme' => $this->setting('custom'),
				default => $this->setting('<script>'),
			}
		);
		$this->assertSame([200, 0, 0], (new AmountColours($mapper))->pdfNegativeRgbForUser('alice'));
	}
}

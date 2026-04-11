<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\ValidationService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ValidationServiceTest extends TestCase {
	private ValidationService $service;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
			}
			return $text;
		});
		$this->service = new ValidationService($l);
	}

	// ── validateStringLength ────────────────────────────────────────

	public function testValidateStringLengthValid(): void {
		$result = $this->service->validateStringLength('hello', 'Field', 255);
		$this->assertTrue($result['valid']);
		$this->assertNull($result['error']);
		$this->assertSame('hello', $result['sanitized']);
	}

	public function testValidateStringLengthTrims(): void {
		$result = $this->service->validateStringLength('  hello  ', 'Field', 255);
		$this->assertTrue($result['valid']);
		$this->assertSame('hello', $result['sanitized']);
	}

	public function testValidateStringLengthTooLong(): void {
		$result = $this->service->validateStringLength(str_repeat('a', 256), 'Name', 255);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('must not exceed 255', $result['error']);
	}

	public function testValidateStringLengthTooShort(): void {
		$result = $this->service->validateStringLength('ab', 'Name', 255, 3);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('at least 3', $result['error']);
	}

	public function testValidateStringLengthNullOptional(): void {
		$result = $this->service->validateStringLength(null, 'Field', 255, 0, false);
		$this->assertTrue($result['valid']);
		$this->assertNull($result['sanitized']);
	}

	public function testValidateStringLengthNullRequired(): void {
		$result = $this->service->validateStringLength(null, 'Name', 255, 0, true);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('required', $result['error']);
	}

	public function testValidateStringLengthEmptyRequired(): void {
		$result = $this->service->validateStringLength('', 'Name', 255, 0, true);
		$this->assertFalse($result['valid']);
	}

	public function testValidateStringLengthMultibyteCharacters(): void {
		// 4 multibyte characters (emojis, CJK, etc.)
		$result = $this->service->validateStringLength('日本語テスト', 'Field', 10);
		$this->assertTrue($result['valid']);
		// mb_strlen counts 6, not byte length
	}

	// ── validateName ────────────────────────────────────────────────

	public function testValidateNameValid(): void {
		$result = $this->service->validateName('My Account');
		$this->assertTrue($result['valid']);
	}

	public function testValidateNameRequiredByDefault(): void {
		$result = $this->service->validateName(null);
		$this->assertFalse($result['valid']);
	}

	public function testValidateNameOptional(): void {
		$result = $this->service->validateName(null, false);
		$this->assertTrue($result['valid']);
	}

	// ── validateDescription ─────────────────────────────────────────

	public function testValidateDescriptionOptionalByDefault(): void {
		$result = $this->service->validateDescription(null);
		$this->assertTrue($result['valid']);
	}

	public function testValidateDescriptionTooLong(): void {
		$result = $this->service->validateDescription(str_repeat('x', 1001));
		$this->assertFalse($result['valid']);
	}

	// ── validateNotes ───────────────────────────────────────────────

	public function testValidateNotesValid(): void {
		$result = $this->service->validateNotes('Some notes here');
		$this->assertTrue($result['valid']);
	}

	public function testValidateNotesTooLong(): void {
		$result = $this->service->validateNotes(str_repeat('x', 2001));
		$this->assertFalse($result['valid']);
	}

	// ── validateVendor ──────────────────────────────────────────────

	public function testValidateVendorValid(): void {
		$result = $this->service->validateVendor('Amazon');
		$this->assertTrue($result['valid']);
		$this->assertSame('Amazon', $result['sanitized']);
	}

	public function testValidateVendorNull(): void {
		$result = $this->service->validateVendor(null);
		$this->assertTrue($result['valid']);
	}

	public function testValidateVendorTooLong(): void {
		$result = $this->service->validateVendor(str_repeat('x', 256));
		$this->assertFalse($result['valid']);
	}

	// ── validateReference ───────────────────────────────────────────

	public function testValidateReferenceValid(): void {
		$result = $this->service->validateReference('REF-001');
		$this->assertTrue($result['valid']);
	}

	public function testValidateReferenceNull(): void {
		$result = $this->service->validateReference(null);
		$this->assertTrue($result['valid']);
	}

	public function testValidateReferenceTooLong(): void {
		$result = $this->service->validateReference(str_repeat('x', 256));
		$this->assertFalse($result['valid']);
	}

	// ── validateIcon ────────────────────────────────────────────────

	public function testValidateIconValid(): void {
		$result = $this->service->validateIcon('cart');
		$this->assertTrue($result['valid']);
	}

	public function testValidateIconNull(): void {
		$result = $this->service->validateIcon(null);
		$this->assertTrue($result['valid']);
	}

	public function testValidateIconTooLong(): void {
		$result = $this->service->validateIcon(str_repeat('x', 51));
		$this->assertFalse($result['valid']);
	}

	// ── validateColor ───────────────────────────────────────────────

	public function testValidateColorValid6Digit(): void {
		$result = $this->service->validateColor('#FF5733');
		$this->assertTrue($result['valid']);
		$this->assertSame('#FF5733', $result['sanitized']);
	}

	public function testValidateColorValid3Digit(): void {
		$result = $this->service->validateColor('#FFF');
		$this->assertTrue($result['valid']);
	}

	public function testValidateColorLowercase(): void {
		$result = $this->service->validateColor('#ff5733');
		$this->assertTrue($result['valid']);
	}

	public function testValidateColorInvalidFormat(): void {
		$result = $this->service->validateColor('red');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('hex code', $result['error']);
	}

	public function testValidateColorMissingHash(): void {
		$result = $this->service->validateColor('FF5733');
		$this->assertFalse($result['valid']);
	}

	public function testValidateColorNull(): void {
		$result = $this->service->validateColor(null);
		$this->assertTrue($result['valid']);
		$this->assertNull($result['sanitized']);
	}

	public function testValidateColorInvalidLength(): void {
		$result = $this->service->validateColor('#FFFF');
		$this->assertFalse($result['valid']);
	}

	// ── validatePattern ─────────────────────────────────────────────

	public function testValidatePatternValid(): void {
		$result = $this->service->validatePattern('AMAZON*');
		$this->assertTrue($result['valid']);
	}

	public function testValidatePatternValidRegex(): void {
		$result = $this->service->validatePattern('/^AMAZON.*/i');
		$this->assertTrue($result['valid']);
	}

	public function testValidatePatternInvalidRegex(): void {
		$result = $this->service->validatePattern('/[invalid/');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('regular expression', $result['error']);
	}

	public function testValidatePatternRequired(): void {
		$result = $this->service->validatePattern(null, true);
		$this->assertFalse($result['valid']);
	}

	public function testValidatePatternOptional(): void {
		$result = $this->service->validatePattern(null, false);
		$this->assertTrue($result['valid']);
	}

	// ── validateDate ────────────────────────────────────────────────

	public function testValidateDateValid(): void {
		$result = $this->service->validateDate('2024-03-15');
		$this->assertTrue($result['valid']);
		$this->assertSame('2024-03-15', $result['formatted']);
	}

	public function testValidateDateInvalidFormat(): void {
		$result = $this->service->validateDate('15/03/2024');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('YYYY-MM-DD', $result['error']);
	}

	public function testValidateDateInvalidDay(): void {
		// Feb 30 doesn't exist
		$result = $this->service->validateDate('2024-02-30');
		$this->assertFalse($result['valid']);
	}

	public function testValidateDateLeapYear(): void {
		$result = $this->service->validateDate('2024-02-29');
		$this->assertTrue($result['valid']);
	}

	public function testValidateDateNotLeapYear(): void {
		$result = $this->service->validateDate('2023-02-29');
		$this->assertFalse($result['valid']);
	}

	public function testValidateDateNullOptional(): void {
		$result = $this->service->validateDate(null);
		$this->assertTrue($result['valid']);
		$this->assertNull($result['formatted']);
	}

	public function testValidateDateNullRequired(): void {
		$result = $this->service->validateDate(null, 'Date', true);
		$this->assertFalse($result['valid']);
	}

	// ── validateAmount ──────────────────────────────────────────────

	public function testValidateAmountValid(): void {
		$result = $this->service->validateAmount(42.50);
		$this->assertTrue($result['valid']);
		$this->assertEqualsWithDelta(42.50, $result['value'], 0.001);
	}

	public function testValidateAmountStringNumeric(): void {
		$result = $this->service->validateAmount('123.45');
		$this->assertTrue($result['valid']);
		$this->assertEqualsWithDelta(123.45, $result['value'], 0.001);
	}

	public function testValidateAmountZero(): void {
		$result = $this->service->validateAmount(0);
		$this->assertTrue($result['valid']);
		$this->assertEqualsWithDelta(0.0, $result['value'], 0.001);
	}

	public function testValidateAmountNegative(): void {
		$result = $this->service->validateAmount(-500.00);
		$this->assertTrue($result['valid']);
	}

	public function testValidateAmountNotNumeric(): void {
		$result = $this->service->validateAmount('abc');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('number', $result['error']);
	}

	public function testValidateAmountOverflow(): void {
		$result = $this->service->validateAmount(9999999999999.99);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('maximum', $result['error']);
	}

	public function testValidateAmountNullRequired(): void {
		$result = $this->service->validateAmount(null);
		$this->assertFalse($result['valid']);
	}

	public function testValidateAmountNullOptional(): void {
		$result = $this->service->validateAmount(null, 'Amount', false);
		$this->assertTrue($result['valid']);
		$this->assertNull($result['value']);
	}

	public function testValidateAmountEmptyStringRequired(): void {
		$result = $this->service->validateAmount('');
		$this->assertFalse($result['valid']);
	}

	public function testValidateAmountCustomFieldName(): void {
		$result = $this->service->validateAmount(null, 'Budget limit');
		$this->assertStringContainsString('Budget limit', $result['error']);
	}

	// ── validateIban ────────────────────────────────────────────────

	public function testValidateIbanValidUK(): void {
		// Valid UK IBAN
		$result = $this->service->validateIban('GB29 NWBK 6016 1331 9268 19');
		$this->assertTrue($result['valid']);
		$this->assertSame('GB29NWBK60161331926819', $result['formatted']);
	}

	public function testValidateIbanValidDE(): void {
		$result = $this->service->validateIban('DE89370400440532013000');
		$this->assertTrue($result['valid']);
	}

	public function testValidateIbanTooShort(): void {
		$result = $this->service->validateIban('GB29NWBK6016');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('15-34', $result['error']);
	}

	public function testValidateIbanTooLong(): void {
		$result = $this->service->validateIban('GB' . str_repeat('1', 33));
		$this->assertFalse($result['valid']);
	}

	public function testValidateIbanInvalidFormat(): void {
		$result = $this->service->validateIban('1234567890123456');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('Invalid IBAN format', $result['error']);
	}

	public function testValidateIbanInvalidChecksum(): void {
		// Valid format but bad checksum (changed last digit)
		$result = $this->service->validateIban('GB29NWBK60161331926810');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('checksum', $result['error']);
	}

	public function testValidateIbanCaseInsensitive(): void {
		$result = $this->service->validateIban('gb29nwbk60161331926819');
		$this->assertTrue($result['valid']);
	}

	// ── validateRoutingNumber ───────────────────────────────────────

	public function testValidateRoutingNumberValid(): void {
		// Chase routing number (valid ABA checksum)
		$result = $this->service->validateRoutingNumber('021000021');
		$this->assertTrue($result['valid']);
		$this->assertSame('021000021', $result['formatted']);
	}

	public function testValidateRoutingNumberWrongLength(): void {
		$result = $this->service->validateRoutingNumber('1234');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('9 digits', $result['error']);
	}

	public function testValidateRoutingNumberInvalidChecksum(): void {
		$result = $this->service->validateRoutingNumber('123456789');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('checksum', $result['error']);
	}

	public function testValidateRoutingNumberStripsNonDigits(): void {
		$result = $this->service->validateRoutingNumber('021-000-021');
		$this->assertTrue($result['valid']);
	}

	// ── validateSortCode ────────────────────────────────────────────

	public function testValidateSortCodeValid(): void {
		$result = $this->service->validateSortCode('601613');
		$this->assertTrue($result['valid']);
		$this->assertSame('60-16-13', $result['formatted']);
	}

	public function testValidateSortCodeWithDashes(): void {
		$result = $this->service->validateSortCode('60-16-13');
		$this->assertTrue($result['valid']);
		$this->assertSame('60-16-13', $result['formatted']);
	}

	public function testValidateSortCodeWrongLength(): void {
		$result = $this->service->validateSortCode('1234');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('6 digits', $result['error']);
	}

	// ── validateSwiftBic ────────────────────────────────────────────

	public function testValidateSwiftBicValid8Char(): void {
		$result = $this->service->validateSwiftBic('NWBKGB2L');
		$this->assertTrue($result['valid']);
		$this->assertSame('NWBKGB2L', $result['formatted']);
	}

	public function testValidateSwiftBicValid11Char(): void {
		$result = $this->service->validateSwiftBic('NWBKGB2LXXX');
		$this->assertTrue($result['valid']);
	}

	public function testValidateSwiftBicInvalidFormat(): void {
		$result = $this->service->validateSwiftBic('INVALID');
		$this->assertFalse($result['valid']);
	}

	public function testValidateSwiftBicCaseInsensitive(): void {
		$result = $this->service->validateSwiftBic('nwbkgb2l');
		$this->assertTrue($result['valid']);
	}

	public function testValidateSwiftBicWrongLength(): void {
		$result = $this->service->validateSwiftBic('NWBKGB2LXX');
		$this->assertFalse($result['valid']);
	}

	// ── validateAccountNumber ───────────────────────────────────────

	public function testValidateAccountNumberValid(): void {
		$result = $this->service->validateAccountNumber('12345678');
		$this->assertTrue($result['valid']);
	}

	public function testValidateAccountNumberEmpty(): void {
		$result = $this->service->validateAccountNumber('');
		$this->assertFalse($result['valid']);
	}

	public function testValidateAccountNumberTooShort(): void {
		$result = $this->service->validateAccountNumber('123');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('too short', $result['error']);
	}

	public function testValidateAccountNumberTooLong(): void {
		$result = $this->service->validateAccountNumber(str_repeat('1', 21));
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('too long', $result['error']);
	}

	// ── validateCurrency ────────────────────────────────────────────

	public function testValidateCurrencyValid(): void {
		$result = $this->service->validateCurrency('USD');
		$this->assertTrue($result['valid']);
		$this->assertSame('USD', $result['formatted']);
	}

	public function testValidateCurrencyValidGBP(): void {
		$result = $this->service->validateCurrency('GBP');
		$this->assertTrue($result['valid']);
	}

	public function testValidateCurrencyInvalid(): void {
		$result = $this->service->validateCurrency('XYZ');
		$this->assertFalse($result['valid']);
	}

	public function testValidateCurrencyLowercase(): void {
		$result = $this->service->validateCurrency('eur');
		$this->assertTrue($result['valid']);
		$this->assertSame('EUR', $result['formatted']);
	}

	public function testValidateCurrencyCrypto(): void {
		$result = $this->service->validateCurrency('BTC');
		$this->assertTrue($result['valid']);
	}

	// ── validateAccountType ─────────────────────────────────────────

	public function testValidateAccountTypeValid(): void {
		$result = $this->service->validateAccountType('checking');
		$this->assertTrue($result['valid']);
	}

	public function testValidateAccountTypeInvalid(): void {
		$result = $this->service->validateAccountType('piggybank');
		$this->assertFalse($result['valid']);
	}

	// ── validateFrequency ───────────────────────────────────────────

	public function testValidateFrequencyValid(): void {
		$result = $this->service->validateFrequency('monthly');
		$this->assertTrue($result['valid']);
	}

	public function testValidateFrequencyInvalid(): void {
		$result = $this->service->validateFrequency('bimonthly');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('Must be one of', $result['error']);
	}

	// ── validateTransactionType ─────────────────────────────────────

	public function testValidateTransactionTypeDebit(): void {
		$result = $this->service->validateTransactionType('debit');
		$this->assertTrue($result['valid']);
	}

	public function testValidateTransactionTypeCredit(): void {
		$result = $this->service->validateTransactionType('credit');
		$this->assertTrue($result['valid']);
	}

	public function testValidateTransactionTypeInvalid(): void {
		$result = $this->service->validateTransactionType('refund');
		$this->assertFalse($result['valid']);
	}

	// ── getBankingFieldRequirements ──────────────────────────────────

	public function testGetBankingFieldRequirementsUSD(): void {
		$result = $this->service->getBankingFieldRequirements('USD');
		$this->assertTrue($result['routing_number']);
		$this->assertFalse($result['sort_code']);
		$this->assertFalse($result['iban']);
	}

	public function testGetBankingFieldRequirementsGBP(): void {
		$result = $this->service->getBankingFieldRequirements('GBP');
		$this->assertFalse($result['routing_number']);
		$this->assertTrue($result['sort_code']);
		$this->assertTrue($result['iban']);
	}

	public function testGetBankingFieldRequirementsEUR(): void {
		$result = $this->service->getBankingFieldRequirements('EUR');
		$this->assertFalse($result['routing_number']);
		$this->assertFalse($result['sort_code']);
		$this->assertTrue($result['iban']);
	}

	public function testGetBankingFieldRequirementsUnknownCurrency(): void {
		$result = $this->service->getBankingFieldRequirements('JPY');
		$this->assertFalse($result['routing_number']);
		$this->assertFalse($result['sort_code']);
		$this->assertFalse($result['iban']);
	}

	// ── getBankingInstitutions ───────────────────────────────────────

	public function testGetBankingInstitutionsReturnsRegions(): void {
		$result = $this->service->getBankingInstitutions();
		$this->assertArrayHasKey('US', $result);
		$this->assertArrayHasKey('UK', $result);
		$this->assertArrayHasKey('EU', $result);
		$this->assertArrayHasKey('CA', $result);
		$this->assertNotEmpty($result['US']);
	}
}

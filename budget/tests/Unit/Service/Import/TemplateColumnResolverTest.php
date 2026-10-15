<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\TemplateColumnResolver;
use PHPUnit\Framework\TestCase;

class TemplateColumnResolverTest extends TestCase {
	private const HEADERS = ['Data contabile', 'Data valuta', 'Descrizione', 'Importo', 'Note'];

	private function resolve(array $mapping, array $headers = self::HEADERS, array $formats = ['Column: {number}']): array {
		return (new TemplateColumnResolver())->resolve($mapping, $headers, $formats);
	}

	public function testHeaderNamesBecomeColumnIndexes(): void {
		$result = $this->resolve(['date' => 'Data contabile', 'amount' => 'Importo', 'description' => 'Descrizione', 'notes' => 'Note']);
		$this->assertSame(['date' => '0', 'amount' => '3', 'description' => '2', 'notes' => '4'], $result['mapping']);
		$this->assertSame([], $result['missing']);
	}

	public function testHeadersAreMatchedTrimmedAsTheImportPageShowsThem(): void {
		$result = $this->resolve(['date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione'], [' Data ', 'Descrizione', 'Importo ']);
		$this->assertSame(['date' => '0', 'amount' => '2', 'description' => '1'], $result['mapping']);
	}

	public function testAStoredIndexIsUsedAsItIs(): void {
		$result = $this->resolve(['date' => 0, 'amount' => 3, 'description' => 2], ['', '', '', '']);
		$this->assertSame(['date' => '0', 'amount' => '3', 'description' => '2'], $result['mapping']);
	}

	public function testTextTargetsTakeSeveralColumns(): void {
		$result = $this->resolve(['date' => 'Data contabile', 'amount' => 'Importo', 'description' => ['Descrizione', 'Note', 'Gone']]);
		$this->assertSame(['2', '4'], $result['mapping']['description']);
		$this->assertSame([], $result['missing']);
	}

	public function testABlankOrRepeatedHeaderResolvesByItsLabelInEitherLanguage(): void {
		// The import page labels a blank or repeated header "Column: N", in
		// the language of whoever saved the template
		$headers = ['Data', 'Importo', '', 'Importo'];
		$result = $this->resolve(
			['date' => 'Data', 'amount' => 'Colonna: 4', 'description' => 'Column: 3'],
			$headers,
			['Colonna: {number}', 'Column: {number}']
		);
		$this->assertSame(['date' => '0', 'amount' => '3', 'description' => '2'], $result['mapping']);
	}

	public function testTheOldColumnNFormStillResolves(): void {
		$result = $this->resolve(['date' => 'Column 1', 'amount' => 'Column 4', 'description' => 'Column 3']);
		$this->assertSame(['date' => '0', 'amount' => '3', 'description' => '2'], $result['mapping']);
	}

	public function testRequiredColumnsThatAreNotInTheFileAreNamed(): void {
		$result = $this->resolve(['date' => 'Booking date', 'amount' => 'Amount', 'description' => 'Descrizione', 'vendor' => 'Payee']);
		$this->assertSame(['Booking date', 'Amount'], $result['missing']);
		$this->assertArrayNotHasKey('vendor', $result['mapping']);
	}

	public function testIncomeAndExpenseColumnsStandInForAmount(): void {
		$result = $this->resolve(
			['date' => 'Data', 'incomeColumn' => 'Entrate', 'expenseColumn' => 'Uscite', 'description' => 'Descrizione'],
			['Data', 'Descrizione', 'Entrate', 'Uscite']
		);
		$this->assertSame('2', $result['mapping']['incomeColumn']);
		$this->assertSame('3', $result['mapping']['expenseColumn']);
		$this->assertSame([], $result['missing']);
	}

	public function testATemplateWithNoAmountAtAllIsMissingOne(): void {
		$result = $this->resolve(['date' => 'Data contabile', 'description' => 'Descrizione']);
		$this->assertSame(['amount'], $result['missing']);
	}

	public function testAnIndexPastTheLastColumnDoesNotResolve(): void {
		$result = $this->resolve(['date' => 0, 'amount' => 9, 'description' => 2]);
		$this->assertSame(['Column: 10'], $result['missing']);
	}

	public function testAHeaderRowOfTensOfThousandsOfColumnsIsCheap(): void {
		// One line of separators makes a header row this wide; labelling it
		// must not compare every column with every other
		$headers = array_fill(0, 20000, '');
		$start = microtime(true);
		$result = $this->resolve(['date' => 0, 'amount' => 1, 'description' => 'Column: 3'], $headers);

		$this->assertLessThan(0.5, microtime(true) - $start);
		$this->assertSame(['date' => '0', 'amount' => '1', 'description' => '2'], $result['mapping']);
	}

	public function testColumnsPastTheThousandthAreNotConsidered(): void {
		$headers = array_fill(0, 1500, 'x');
		$headers[1200] = 'Importo';
		$result = $this->resolve(['date' => 0, 'amount' => 'Importo', 'description' => 1200], $headers);

		$this->assertSame(['Importo', 'Column: 1201'], $result['missing']);
	}

	public function testOptionsAreNotColumns(): void {
		$result = $this->resolve(['date' => 0, 'amount' => 3, 'description' => 2, 'skipFirstRow' => true, 'applyRules' => false, 'bogus' => 'x']);
		$this->assertSame(['date' => '0', 'amount' => '3', 'description' => '2'], $result['mapping']);
	}
}

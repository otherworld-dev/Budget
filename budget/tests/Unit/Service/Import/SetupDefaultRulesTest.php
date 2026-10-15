<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Service\Import\CriteriaEvaluator;
use OCA\Budget\Service\Import\SetupDefaultRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The rules "Create default categories" makes. Now that they set a
 * category, their old patterns matched anywhere in a description: an OFX
 * import filed "Waterstones" (a bookshop) under Utilities (V3-4). They now
 * match whole words, and the alternatives that caught other things are gone.
 */
class SetupDefaultRulesTest extends TestCase {
	/** The first default rule matching a description, in the order they run */
	private function firstRule(string $description): ?string {
		$evaluator = new CriteriaEvaluator($this->createMock(LoggerInterface::class));
		foreach (SetupDefaultRules::DEFINITIONS as $name => $definition) {
			$criteria = ['field' => 'description', 'pattern' => $definition['pattern'], 'matchType' => 'regex'];
			if ($evaluator->evaluate($criteria, ['description' => $description], 1)) {
				return $name;
			}
		}
		return null;
	}

	public static function descriptions(): array {
		return [
			// Caught by a word inside another word
			'a bookshop' => ['WATERSTONES BOOKS', null],
			'a phone plan' => ['VODAFONE MOBILE PLAN', null],
			'cashback' => ['CASHBACK REWARD', null],
			'a pub' => ['THE GASTROPUB', null],
			'a holiday' => ['LAS VEGAS HOTEL', null],
			'an electrical shop' => ['CURRYS ELECTRICAL', null],
			'a card payout' => ['STRIPE PAYOUT', null],
			'a seafood shop' => ['SHELLFISH MARKET', null],
			// Still caught
			'a petrol station' => ['SHELL GARAGE KIOSK', 'Gas Stations'],
			'a gas station' => ['QUIKTRIP GAS STATION', 'Gas Stations'],
			'BP' => ['BP 4412 LONDON', 'Gas Stations'],
			'a gas bill' => ['BRITISH GAS DD', 'Utilities'],
			'a water bill' => ['THAMES WATER', 'Utilities'],
			'electricity' => ['OCTOPUS ELECTRICITY', 'Utilities'],
			'a supermarket' => ['TESCO SUPERMARKET', 'Grocery Stores'],
			'Whole Foods' => ['WHOLE FOODS MARKET', 'Grocery Stores'],
			'McDonalds' => ['MCDONALDS 123', 'Restaurants'],
			'McDonald\'s' => ["MCDONALD'S 123", 'Restaurants'],
			'a coffee shop' => ['COSTA COFFEE', 'Restaurants'],
			'restaurants' => ['FINE RESTAURANTS LTD', 'Restaurants'],
			'a cash machine' => ['ATM WITHDRAWAL 22', 'ATM Withdrawals'],
			'Amazon' => ['AMAZON.CO.UK MKTPLACE', 'Online Shopping'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('descriptions')]
	public function testTheDefaultRulesMatchWholeWords(string $description, ?string $rule): void {
		$this->assertSame($rule, $this->firstRule($description));
	}

	/**
	 * A default rule made before these patterns, still exactly as setup made
	 * it, is given them; one the user changed is left alone.
	 */
	public function testAnUntouchedCategorisedDefaultGetsTheNewPattern(): void {
		$rule = $this->categorisedDefault('Utilities');

		$this->assertTrue(SetupDefaultRules::refreshPattern($rule));

		$pattern = SetupDefaultRules::DEFINITIONS['Utilities']['pattern'];
		$this->assertSame($pattern, $rule->getPattern());
		$this->assertSame($pattern, $rule->getParsedCriteria()['root']['conditions'][0]['pattern']);
		$this->assertSame(4, $rule->getParsedActions()['actions'][0]['value']);
	}

	public static function rulesTheUserChanged(): array {
		return [
			'another category action as well' => [fn (ImportRule $r) => $r->setActionsFromArray(['version' => 2, 'stopProcessing' => true, 'actions' => [
				['type' => 'set_category', 'value' => 4, 'behavior' => 'always', 'priority' => 100],
				['type' => 'set_vendor', 'value' => 'X'],
			]])],
			'a priority' => [fn (ImportRule $r) => $r->setPriority(5)],
			'its criteria' => [fn (ImportRule $r) => $r->setCriteriaFromArray(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'regex', 'pattern' => 'water', 'negate' => true],
			]]])],
			'switched off' => [fn (ImportRule $r) => $r->setActive(false)],
			'carries on to later rules' => [fn (ImportRule $r) => $r->setStopProcessing(false)],
			'in a group' => [fn (ImportRule $r) => $r->setGroupName('Home')],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('rulesTheUserChanged')]
	public function testADefaultTheUserChangedKeepsItsPattern(callable $change): void {
		$rule = $this->categorisedDefault('Utilities');
		$change($rule);

		$this->assertFalse(SetupDefaultRules::refreshPattern($rule));
		$this->assertSame(SetupDefaultRules::RULES['Utilities'], $rule->getPattern());
	}

	/** A default as setup made it before whole-word patterns, with a category */
	private function categorisedDefault(string $name): ImportRule {
		$pattern = SetupDefaultRules::RULES[$name];
		$rule = new ImportRule();
		$rule->setName($name);
		$rule->setPattern($pattern);
		$rule->setField('description');
		$rule->setMatchType('regex');
		$rule->setPriority(0);
		$rule->setActive(true);
		$rule->setApplyOnImport(true);
		$rule->setStopProcessing(true);
		$rule->setSchemaVersion(2);
		$rule->setCriteriaFromArray(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
			['type' => 'condition', 'field' => 'description', 'matchType' => 'regex', 'pattern' => $pattern, 'negate' => false],
		]]]);
		$rule->setActionsFromArray(['version' => 2, 'stopProcessing' => true, 'actions' => [
			['type' => 'set_category', 'value' => 4, 'behavior' => 'always', 'priority' => 100],
		]]);
		return $rule;
	}
}

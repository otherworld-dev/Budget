<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionRecurringContribution;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountClosureService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The single gate an account passes through to be closed (#372).
 *
 * "Closed" promises two things to the rest of the app: the balance is zero and
 * nothing will post into the account again. The guard is what makes both true
 * at the moment of closing — the dropdowns hiding closed accounts keep them
 * true afterwards. Every refusal names what is in the way so the user can fix
 * it once, rather than discovering a stale bill months later.
 */
class AccountClosureServiceTest extends TestCase {
    private AccountClosureService $service;

    // Seeded-map stubs: a per-test re-stub cannot override a setUp() stub, so
    // every mapper reads from one of these arrays instead.
    private bool $futureRows = false;
    /** @var Bill[] */
    private array $bills = [];
    /** @var RecurringIncome[] */
    private array $incomes = [];
    /** @var PensionRecurringContribution[] */
    private array $contributions = [];
    /** @var array<int, PensionAccount> */
    private array $pensions = [];
    /** @var BankConnection[] */
    private array $connections = [];
    /** @var array<int, BankAccountMapping[]> */
    private array $mappings = [];
    /** @var ImportRule[] */
    private array $rules = [];

    protected function setUp(): void {
        $transactionMapper = $this->createMock(TransactionMapper::class);
        $transactionMapper->method('hasRowsAfterDate')->willReturnCallback(fn() => $this->futureRows);

        $billMapper = $this->createMock(BillMapper::class);
        $billMapper->method('findActive')->willReturnCallback(fn() => $this->bills);

        $incomeMapper = $this->createMock(RecurringIncomeMapper::class);
        $incomeMapper->method('findActive')->willReturnCallback(fn() => $this->incomes);

        $contributionMapper = $this->createMock(PensionRecurringContributionMapper::class);
        $contributionMapper->method('findActive')->willReturnCallback(fn() => $this->contributions);

        $pensionMapper = $this->createMock(PensionAccountMapper::class);
        $pensionMapper->method('find')->willReturnCallback(function (int $id) {
            if (!isset($this->pensions[$id])) {
                throw new DoesNotExistException('no pension');
            }
            return $this->pensions[$id];
        });

        $connectionMapper = $this->createMock(BankConnectionMapper::class);
        $connectionMapper->method('findAll')->willReturnCallback(fn() => $this->connections);

        $mappingMapper = $this->createMock(BankAccountMappingMapper::class);
        $mappingMapper->method('findEnabledByConnection')
            ->willReturnCallback(fn(int $connectionId) => $this->mappings[$connectionId] ?? []);

        $ruleMapper = $this->createMock(ImportRuleMapper::class);
        $ruleMapper->method('findActive')->willReturnCallback(fn() => $this->rules);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            foreach ($params as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
            }
            return $text;
        });

        $this->service = new AccountClosureService(
            $transactionMapper,
            $billMapper,
            $incomeMapper,
            $contributionMapper,
            $pensionMapper,
            $connectionMapper,
            $mappingMapper,
            $ruleMapper,
            $l
        );
    }

    // ── fixtures ────────────────────────────────────────────────────

    private function account(float $balance = 0.0, string $currency = 'GBP'): Account {
        $account = new Account();
        $account->setId(7);
        $account->setUserId('alice');
        $account->setName('Old current');
        $account->setType('checking');
        $account->setBalance($balance);
        $account->setCurrency($currency);
        return $account;
    }

    private function bill(string $name, ?int $accountId, ?int $destinationId = null, bool $transfer = false): Bill {
        $bill = new Bill();
        $bill->setUserId('alice');
        $bill->setName($name);
        $bill->setAccountId($accountId);
        $bill->setDestinationAccountId($destinationId);
        $bill->setIsTransfer($transfer);
        $bill->setIsActive(true);
        return $bill;
    }

    private function income(string $name, ?int $accountId): RecurringIncome {
        $income = new RecurringIncome();
        $income->setUserId('alice');
        $income->setName($name);
        $income->setAccountId($accountId);
        $income->setIsActive(true);
        return $income;
    }

    private function contribution(int $pensionId, ?int $sourceAccountId): PensionRecurringContribution {
        $c = new PensionRecurringContribution();
        $c->setUserId('alice');
        $c->setPensionId($pensionId);
        $c->setSourceAccountId($sourceAccountId);
        $c->setIsActive(true);
        return $c;
    }

    private function pension(int $id, string $name): PensionAccount {
        $p = new PensionAccount();
        $p->setId($id);
        $p->setName($name);
        return $p;
    }

    private function connection(int $id): BankConnection {
        $c = new BankConnection();
        $c->setId($id);
        return $c;
    }

    private function mapping(?int $budgetAccountId, string $externalName): BankAccountMapping {
        $m = new BankAccountMapping();
        $m->setBudgetAccountId($budgetAccountId);
        $m->setExternalAccountName($externalName);
        $m->setEnabled(true);
        return $m;
    }

    private function rule(string $name, array $actions): ImportRule {
        $rule = new ImportRule();
        $rule->setUserId('alice');
        $rule->setName($name);
        $rule->setActive(true);
        $rule->setActionsFromArray(['version' => 2, 'actions' => $actions]);
        return $rule;
    }

    // ── the balance ─────────────────────────────────────────────────

    public function testPassesAnEmptyZeroBalanceAccount(): void {
        $this->expectNotToPerformAssertions();

        $this->service->assertClosable($this->account(0.0));
    }

    public function testRefusesANonZeroBalanceAndNamesIt(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/12\.34/');

        $this->service->assertClosable($this->account(12.34));
    }

    public function testRefusesANegativeBalanceToo(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/5\.00/');

        $this->service->assertClosable($this->account(-5.0));
    }

    /** Dust below what the currency can express is nothing, not a balance. */
    public function testIgnoresDustBelowTheCurrencyPrecision(): void {
        $this->expectNotToPerformAssertions();

        $this->service->assertClosable($this->account(0.004, 'USD'));
    }

    public function testChecksTheBalanceBeforeAnythingElse(): void {
        $this->bills[] = $this->bill('Comcast', 7);

        try {
            $this->service->assertClosable($this->account(5.0));
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('5.00', $e->getMessage());
            $this->assertStringNotContainsString('Comcast', $e->getMessage());
        }
    }

    // ── future-dated rows ───────────────────────────────────────────

    public function testRefusesWhenTransactionsAreDatedAfterToday(): void {
        $this->futureRows = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/after today/');

        $this->service->assertClosable($this->account());
    }

    // ── things that would post into the account ─────────────────────

    public function testRefusesWhenAnActiveBillPaysFromIt(): void {
        $this->bills[] = $this->bill('Comcast', 7);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Comcast/');

        $this->service->assertClosable($this->account());
    }

    public function testRefusesWhenAnActiveTransferPaysIntoIt(): void {
        $this->bills[] = $this->bill('Savings top-up', 3, 7, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Savings top-up/');

        $this->service->assertClosable($this->account());
    }

    public function testIgnoresBillsOnOtherAccounts(): void {
        $this->bills[] = $this->bill('Comcast', 3);
        $this->bills[] = $this->bill('Elsewhere', 3, 4, true);

        $this->expectNotToPerformAssertions();

        $this->service->assertClosable($this->account());
    }

    public function testRefusesWhenRecurringIncomeIsPaidIntoIt(): void {
        $this->incomes[] = $this->income('Salary', 7);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Salary/');

        $this->service->assertClosable($this->account());
    }

    public function testRefusesWhenAPensionContributionDrawsFromItAndNamesThePension(): void {
        $this->pensions[2] = $this->pension(2, 'Aviva workplace');
        $this->contributions[] = $this->contribution(2, 7);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Aviva workplace/');

        $this->service->assertClosable($this->account());
    }

    public function testStillRefusesWhenTheContributionsPensionCannotBeLoaded(): void {
        $this->contributions[] = $this->contribution(99, 7);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->assertClosable($this->account());
    }

    public function testRefusesWhenABankSyncMappingFeedsIt(): void {
        $this->connections[] = $this->connection(5);
        $this->mappings[5] = [$this->mapping(7, 'Main Current')];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Main Current/');

        $this->service->assertClosable($this->account());
    }

    public function testRefusesWhenARuleRoutesTransactionsIntoIt(): void {
        $this->rules[] = $this->rule('Route Amazon', [['type' => 'set_account', 'value' => 7]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Route Amazon/');

        $this->service->assertClosable($this->account());
    }

    public function testIgnoresRulesRoutingElsewhere(): void {
        $this->rules[] = $this->rule('Route Amazon', [['type' => 'set_account', 'value' => 3]]);
        $this->rules[] = $this->rule('Just a category', [['type' => 'set_category', 'value' => 7]]);

        $this->expectNotToPerformAssertions();

        $this->service->assertClosable($this->account());
    }

    // ── the grouped listing ─────────────────────────────────────────

    public function testFindOpenReferencesGroupsNamesByKindAndOmitsEmptyGroups(): void {
        $this->bills[] = $this->bill('Comcast', 7);
        $this->bills[] = $this->bill('Savings top-up', 3, 7, true);
        $this->incomes[] = $this->income('Salary', 7);

        $this->assertSame([
            'bills' => ['Comcast'],
            'transfers' => ['Savings top-up'],
            'income' => ['Salary'],
        ], $this->service->findOpenReferences($this->account()));
    }

    public function testFindOpenReferencesIsEmptyForAnUnusedAccount(): void {
        $this->assertSame([], $this->service->findOpenReferences($this->account()));
    }
}

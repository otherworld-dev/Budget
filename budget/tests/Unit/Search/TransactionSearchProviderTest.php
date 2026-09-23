<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Search;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Search\TransactionSearchProvider;
use OCA\Budget\Service\GranularShareService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

class TransactionSearchProviderTest extends TestCase {
	private TransactionSearchProvider $provider;
	private TransactionMapper $transactionMapper;
	private GranularShareService $granularShareService;
	private IUser $user;

	protected function setUp(): void {
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/budget/');
		$urlGenerator->method('imagePath')->willReturn('/apps/budget/img/app-dark.svg');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $p) => 'https://nc.test' . $p);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->provider = new TransactionSearchProvider(
			$this->transactionMapper,
			$this->granularShareService,
			$urlGenerator,
			$l
		);

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
	}

	private function makeQuery(string $term, int $limit = 5, ?string $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}

	private function makeTransaction(int $id, string $description, float $amount, string $type = 'debit'): Transaction {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setDescription($description);
		$tx->setAmount($amount);
		$tx->setType($type);
		$tx->setDate('2026-06-01');
		return $tx;
	}

	public function testStableId(): void {
		$this->assertSame('budget-transactions', $this->provider->getId());
	}

	public function testOrderBoostedInsideApp(): void {
		$this->assertSame(-1, $this->provider->getOrder('budget.page.index', []));
		$this->assertSame(25, $this->provider->getOrder('files.view.index', []));
	}

	public function testShortTermReturnsNoResultsWithoutQuerying(): void {
		$this->transactionMapper->expects($this->never())->method('search');

		$result = $this->provider->search($this->user, $this->makeQuery('a'));

		$this->assertCount(0, $result->jsonSerialize()['entries']);
	}

	public function testSearchPassesVisibleAccountScope(): void {
		$this->granularShareService->method('getVisibleAccountIds')->with('alice')->willReturn([3, 7]);
		$this->transactionMapper->expects($this->once())
			->method('search')
			->with('alice', 'rent', 5, 0, [3, 7])
			->willReturn([$this->makeTransaction(1, 'Rent June', 900.0)]);

		$result = $this->provider->search($this->user, $this->makeQuery('rent'));
		$data = $result->jsonSerialize();

		$this->assertFalse($data['isPaginated']);
		$this->assertCount(1, $data['entries']);
		$entry = $data['entries'][0]->jsonSerialize();
		$this->assertSame('Rent June', $entry['title']);
		$this->assertStringContainsString('-900.00', $entry['subline']);
		$this->assertStringContainsString('#/transactions?search=rent', $entry['resourceUrl']);
	}

	public function testFullPageIsPaginatedWithAdvancingCursor(): void {
		$this->granularShareService->method('getVisibleAccountIds')->willReturn([1]);
		$transactions = array_map(
			fn (int $i) => $this->makeTransaction($i, "Coffee {$i}", 3.5),
			range(1, 5)
		);
		$this->transactionMapper->method('search')
			->with('alice', 'coffee', 5, 10, [1])
			->willReturn($transactions);

		$result = $this->provider->search($this->user, $this->makeQuery('coffee', 5, '10'));
		$data = $result->jsonSerialize();

		$this->assertTrue($data['isPaginated']);
		$this->assertSame(15, $data['cursor']);
	}
}

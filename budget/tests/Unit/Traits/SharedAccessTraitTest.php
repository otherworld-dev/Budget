<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Traits;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * SharedAccessTrait drives what a controller lets a user see and change.
 *
 * Runs against a real GranularShareService over a small fixed "world", so the
 * read/write split is the one production computes, not a restatement of mocks:
 *
 *   alice owns accounts 1, 2 and category 50
 *   bob   owns accounts 10, 11, 12 and category 60
 *   bob → alice, accepted: account 10 read, account 11 write, category 60 read
 *   carol → alice, still pending: account 20 write (must count for nothing)
 */
class SharedAccessTraitTest extends TestCase {
	private const ACCOUNTS = [
		'alice' => [1, 2],
		'bob' => [10, 11, 12],
		'carol' => [20],
	];
	private const CATEGORIES = [
		'alice' => [50],
		'bob' => [60],
	];

	/** shareId => [type => [entityId => permission]] */
	private const SHARE_ITEMS = [
		7 => [
			ShareItem::TYPE_ACCOUNT => [10 => ShareItem::PERMISSION_READ, 11 => ShareItem::PERMISSION_WRITE],
			ShareItem::TYPE_CATEGORY => [60 => ShareItem::PERMISSION_READ],
		],
		8 => [
			ShareItem::TYPE_ACCOUNT => [20 => ShareItem::PERMISSION_WRITE],
		],
	];

	private function makeService(): GranularShareService {
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findAll')->willReturnCallback(fn(string $userId) => array_map(
			function (int $id) {
				$a = new Account();
				$a->setId($id);
				return $a;
			},
			self::ACCOUNTS[$userId] ?? []
		));

		$categoryMapper = $this->createMock(CategoryMapper::class);
		$categoryMapper->method('findAll')->willReturnCallback(fn(string $userId) => array_map(
			function (int $id) {
				$c = new Category();
				$c->setId($id);
				return $c;
			},
			self::CATEGORIES[$userId] ?? []
		));

		$shareMapper = $this->createMock(ShareMapper::class);
		$shareMapper->method('findByRecipient')->willReturnCallback(function (string $userId) {
			if ($userId !== 'alice') {
				return [];
			}
			return [
				$this->makeShare(7, 'bob', 'alice', Share::STATUS_ACCEPTED),
				$this->makeShare(8, 'carol', 'alice', Share::STATUS_PENDING),
			];
		});

		$shareItemMapper = $this->createMock(ShareItemMapper::class);
		$shareItemMapper->method('findSharedEntityIds')->willReturnCallback(
			fn(int $shareId, string $type) => array_keys(self::SHARE_ITEMS[$shareId][$type] ?? [])
		);
		$shareItemMapper->method('getEntityPermission')->willReturnCallback(
			fn(int $shareId, string $type, int $id) => self::SHARE_ITEMS[$shareId][$type][$id] ?? null
		);

		return new GranularShareService(
			$shareMapper,
			$shareItemMapper,
			$accountMapper,
			$this->createMock(BillMapper::class),
			$categoryMapper,
			$this->createMock(RecurringIncomeMapper::class),
			$this->createMock(SavingsGoalMapper::class),
			$this->createMock(ImportRuleMapper::class),
			$this->createMock(IL10N::class)
		);
	}

	private function makeShare(int $id, string $owner, string $recipient, string $status): Share {
		$share = new Share();
		$share->setId($id);
		$share->setOwnerUserId($owner);
		$share->setSharedWithUserId($recipient);
		$share->setStatus($status);
		return $share;
	}

	private function controllerFor(string $userId): SharedAccessTraitTestController {
		return new SharedAccessTraitTestController($userId, $this->makeService());
	}

	private static function sorted(array $ids): array {
		sort($ids);
		return $ids;
	}

	// ── identity ────────────────────────────────────────────────────

	public function testEffectiveUserIdIsAlwaysTheAuthenticatedUser(): void {
		$this->assertSame('alice', $this->controllerFor('alice')->callGetEffectiveUserId());
	}

	// ── read scope ──────────────────────────────────────────────────

	public function testVisibleAccountsAreOwnPlusAcceptedSharesRegardlessOfPermission(): void {
		$ids = $this->controllerFor('alice')->callGetVisibleAccountIds();

		$this->assertSame([1, 2, 10, 11], self::sorted($ids));
	}

	public function testAPendingShareGrantsNoVisibility(): void {
		$ids = $this->controllerFor('alice')->callGetVisibleAccountIds();

		$this->assertNotContains(20, $ids);
	}

	public function testAccountsTheOwnerDidNotShareStayInvisible(): void {
		$this->assertNotContains(12, $this->controllerFor('alice')->callGetVisibleAccountIds());
	}

	public function testAUserWithNoSharesSeesOnlyTheirOwnAccounts(): void {
		$this->assertSame([10, 11, 12], self::sorted($this->controllerFor('bob')->callGetVisibleAccountIds()));
	}

	public function testAUserWithNothingSeesNothing(): void {
		$controller = $this->controllerFor('nobody');

		$this->assertSame([], $controller->callGetVisibleAccountIds());
		$this->assertSame([], $controller->callGetWritableAccountIds());
		$this->assertSame([], $controller->callGetVisibleCategoryIds());
	}

	public function testVisibleCategoriesIncludeReadOnlySharedOnes(): void {
		$this->assertSame([50, 60], self::sorted($this->controllerFor('alice')->callGetVisibleCategoryIds()));
	}

	public function testOtherVisibleIdListsAreEmptyWhenNothingOfThatTypeExists(): void {
		$controller = $this->controllerFor('alice');

		$this->assertSame([], $controller->callGetVisibleBillIds());
		$this->assertSame([], $controller->callGetVisibleRecurringIncomeIds());
		$this->assertSame([], $controller->callGetVisibleSavingsGoalIds());
		$this->assertSame([], $controller->callGetVisibleImportRuleIds());
	}

	public function testCanAccessOwnAndSharedButNotForeignEntities(): void {
		$controller = $this->controllerFor('alice');

		$this->assertTrue($controller->callCanAccessEntity(ShareItem::TYPE_ACCOUNT, 1));
		$this->assertTrue($controller->callCanAccessEntity(ShareItem::TYPE_ACCOUNT, 10));
		$this->assertTrue($controller->callCanAccessEntity(ShareItem::TYPE_ACCOUNT, 11));
		$this->assertFalse($controller->callCanAccessEntity(ShareItem::TYPE_ACCOUNT, 12));
		$this->assertFalse($controller->callCanAccessEntity(ShareItem::TYPE_ACCOUNT, 20));
		$this->assertFalse($controller->callCanAccessEntity(ShareItem::TYPE_ACCOUNT, 999));
	}

	// ── write scope ─────────────────────────────────────────────────

	public function testWritableAccountsDropReadOnlySharesButKeepWriteShares(): void {
		$ids = $this->controllerFor('alice')->callGetWritableAccountIds();

		$this->assertSame([1, 2, 11], self::sorted($ids));
		$this->assertNotContains(10, $ids);
	}

	public function testWritableAccountsExcludeAPendingWriteShare(): void {
		$this->assertNotContains(20, $this->controllerFor('alice')->callGetWritableAccountIds());
	}

	public function testOwnerCanWriteEveryOwnAccount(): void {
		$this->assertSame([10, 11, 12], self::sorted($this->controllerFor('bob')->callGetWritableAccountIds()));
	}

	public function testOwnerCanWriteTheirOwnAccount(): void {
		$this->controllerFor('alice')->callRequireWriteAccess(ShareItem::TYPE_ACCOUNT, 1);
		$this->addToAssertionCount(1);
	}

	public function testOwnerCanStillWriteAnAccountTheyHaveSharedReadOnly(): void {
		// Sharing account 10 read-only with alice restricts alice, not bob.
		$this->controllerFor('bob')->callRequireWriteAccess(ShareItem::TYPE_ACCOUNT, 10);
		$this->addToAssertionCount(1);
	}

	public function testRecipientOfAWriteShareCanWrite(): void {
		$this->controllerFor('alice')->callRequireWriteAccess(ShareItem::TYPE_ACCOUNT, 11);
		$this->addToAssertionCount(1);
	}

	public function testRecipientOfAReadOnlyShareIsRefusedWriteAccess(): void {
		$this->expectException(ReadOnlyShareException::class);

		$this->controllerFor('alice')->callRequireWriteAccess(ShareItem::TYPE_ACCOUNT, 10);
	}

	public function testReadOnlyCategoryShareIsRefusedWriteAccess(): void {
		$this->expectException(ReadOnlyShareException::class);

		$this->controllerFor('alice')->callRequireWriteAccess(ShareItem::TYPE_CATEGORY, 60);
	}

	public function testAPendingWriteShareGrantsNoWriteAccess(): void {
		$this->expectException(ReadOnlyShareException::class);

		$this->controllerFor('alice')->callRequireWriteAccess(ShareItem::TYPE_ACCOUNT, 20);
	}

	public function testWriteAccessToAnEntityNobodySharedIsRefused(): void {
		$this->expectException(ReadOnlyShareException::class);

		$this->controllerFor('alice')->callRequireWriteAccess(ShareItem::TYPE_ACCOUNT, 12);
	}

	// ── getEffectiveAccountIds (report scope) ───────────────────────

	public function testEffectiveAccountIdsIncludeSharedByDefault(): void {
		$this->assertSame([1, 2, 10, 11], self::sorted($this->controllerFor('alice')->callGetEffectiveAccountIds()));
	}

	public function testEffectiveAccountIdsCanBeNarrowedToOwnAccounts(): void {
		$this->assertSame([1, 2], self::sorted($this->controllerFor('alice')->callGetEffectiveAccountIds(true)));
	}

	// ── resolveAccountScope ─────────────────────────────────────────

	public function testResolveScopeWithNoSelectionUsesEveryVisibleAccount(): void {
		[$accountId, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, null);

		$this->assertNull($accountId);
		$this->assertSame([1, 2, 10, 11], self::sorted($scope));
	}

	public function testResolveScopeTreatsAnEmptySelectionAsNoSelection(): void {
		[$accountId, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, []);

		$this->assertNull($accountId);
		$this->assertSame([1, 2, 10, 11], self::sorted($scope));
	}

	public function testResolveScopePassesASingleAccountIdThrough(): void {
		[$accountId, $scope] = $this->controllerFor('alice')->callResolveAccountScope(2, null);

		$this->assertSame(2, $accountId);
		$this->assertSame([1, 2, 10, 11], self::sorted($scope));
	}

	public function testResolveScopeMultiSelectClearsTheSingleAccountId(): void {
		[$accountId, $scope] = $this->controllerFor('alice')->callResolveAccountScope(2, [1, 10]);

		$this->assertNull($accountId);
		$this->assertSame([1, 10], self::sorted($scope));
	}

	public function testResolveScopeDropsSelectedAccountsTheUserCannotSee(): void {
		// 12 is bob's but unshared, 20 is behind a pending share, 999 doesn't exist.
		[, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, [1, 12, 20, 999]);

		$this->assertSame([1], $scope);
	}

	public function testResolveScopeFallsBackToAllVisibleWhenNothingSelectedIsAccessible(): void {
		[$accountId, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, [12, 999]);

		$this->assertNull($accountId);
		$this->assertSame([1, 2, 10, 11], self::sorted($scope));
	}

	public function testResolveScopeAcceptsStringIdsFromTheQueryString(): void {
		[, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, ['2', '11']);

		$this->assertSame([2, 11], self::sorted($scope));
	}

	public function testResolveScopeReturnsAListWithoutGaps(): void {
		[, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, [12, 11, 1]);

		$this->assertSame(array_values($scope), $scope);
	}

	public function testResolveScopeExcludingSharedIntersectsWithOwnAccountsOnly(): void {
		[, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, [1, 10, 11], true);

		$this->assertSame([1], $scope);
	}

	public function testResolveScopeExcludingSharedWithNoSelectionIsOwnAccounts(): void {
		[$accountId, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, null, true);

		$this->assertNull($accountId);
		$this->assertSame([1, 2], self::sorted($scope));
	}

	public function testResolveScopeExcludingSharedFallsBackToOwnWhenOnlySharedWereSelected(): void {
		[, $scope] = $this->controllerFor('alice')->callResolveAccountScope(null, [10, 11], true);

		$this->assertSame([1, 2], self::sorted($scope));
	}

	public function testResolveScopeForAUserWithNoAccountsIsEmpty(): void {
		[$accountId, $scope] = $this->controllerFor('nobody')->callResolveAccountScope(null, [1, 2]);

		$this->assertNull($accountId);
		$this->assertSame([], $scope);
	}
}

/**
 * Minimal controller-shaped host for the trait: a `$userId` property plus
 * public wrappers for the protected API.
 */
class SharedAccessTraitTestController {
	use SharedAccessTrait;

	public function __construct(
		protected string $userId,
		GranularShareService $service,
	) {
		$this->setGranularShareService($service);
	}

	public function callGetEffectiveUserId(): string {
		return $this->getEffectiveUserId();
	}

	public function callGetVisibleAccountIds(): array {
		return $this->getVisibleAccountIds();
	}

	public function callGetWritableAccountIds(): array {
		return $this->getWritableAccountIds();
	}

	public function callGetEffectiveAccountIds(bool $excludeShared = false): array {
		return $this->getEffectiveAccountIds($excludeShared);
	}

	public function callResolveAccountScope(?int $accountId, ?array $accountIds, bool $excludeShared = false): array {
		return $this->resolveAccountScope($accountId, $accountIds, $excludeShared);
	}

	public function callGetVisibleCategoryIds(): array {
		return $this->getVisibleCategoryIds();
	}

	public function callGetVisibleBillIds(): array {
		return $this->getVisibleBillIds();
	}

	public function callGetVisibleRecurringIncomeIds(): array {
		return $this->getVisibleRecurringIncomeIds();
	}

	public function callGetVisibleSavingsGoalIds(): array {
		return $this->getVisibleSavingsGoalIds();
	}

	public function callGetVisibleImportRuleIds(): array {
		return $this->getVisibleImportRuleIds();
	}

	public function callRequireWriteAccess(string $entityType, int $entityId): void {
		$this->requireWriteAccess($entityType, $entityId);
	}

	public function callCanAccessEntity(string $entityType, int $entityId): bool {
		return $this->canAccessEntity($entityType, $entityId);
	}
}

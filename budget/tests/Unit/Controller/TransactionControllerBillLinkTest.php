<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\TransactionController;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\Export\TransactionCsvExporter;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * POST and DELETE /api/transactions/{id}/bill: file a transaction under one
 * of the user's bills as a past payment, or take it out again (#399).
 *
 * The row can sit in an account shared with the user, so it is looked up
 * among the accounts they can see and refused with a reason when the share
 * is read-only, as Link transfer does (#368); the service then works within
 * the accounts they can write to.
 */
class TransactionControllerBillLinkTest extends TestCase {
	private const ROUTES = __DIR__ . '/../../../appinfo/routes.php';

	/** @var TransactionService&\PHPUnit\Framework\MockObject\MockObject */
	private $service;
	private IL10N $l;

	protected function setUp(): void {
		$this->service = $this->createMock(TransactionService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));
	}

	private function controller(array $visibleAccountIds, array $writableAccountIds): TransactionController {
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->willReturn($visibleAccountIds);
		$shares->method('getWritableAccountIds')->willReturn($writableAccountIds);
		$shares->method('requireWriteAccess')->willReturnCallback(
			function (string $userId, string $type, int $id) use ($writableAccountIds): void {
				if ($type === 'account' && !in_array($id, $writableAccountIds, true)) {
					throw new ReadOnlyShareException();
				}
			}
		);

		return new TransactionController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->createMock(TransactionSplitService::class),
			$this->createMock(TransactionTagService::class),
			$this->createMock(ValidationService::class),
			$shares,
			new TransactionCsvExporter($this->l),
			$this->l,
			'terje',
			$this->createMock(LoggerInterface::class)
		);
	}

	private function rowIn(int $accountId, ?int $billId = null): Transaction {
		$row = new Transaction();
		$row->setId(1586);
		$row->setAccountId($accountId);
		$row->setBillId($billId);
		return $row;
	}

	// ── routes ──────────────────────────────────────────────────────

	public function testTheBillRoutesAreRegistered(): void {
		$routes = (require self::ROUTES)['routes'];
		$byVerb = [];
		foreach ($routes as $i => $route) {
			if ($route['url'] === '/api/transactions/{id}/bill') {
				$byVerb[$route['verb']] = [$i, $route['name']];
			}
		}

		$this->assertSame('transaction#linkBill', $byVerb['POST'][1] ?? null);
		$this->assertSame('transaction#unlinkBill', $byVerb['DELETE'][1] ?? null);
		$this->assertTrue(method_exists(TransactionController::class, 'linkBill'));
		$this->assertTrue(method_exists(TransactionController::class, 'unlinkBill'));

		// After the plain {id} routes they would otherwise shadow, and next to
		// the transfer link they sit with in the row menu
		$names = array_column($routes, 'name');
		$this->assertGreaterThan(array_search('transaction#destroy', $names, true), $byVerb['DELETE'][0]);
		$this->assertGreaterThan(array_search('transaction#unlink', $names, true), $byVerb['POST'][0]);
	}

	// ── Link to bill ────────────────────────────────────────────────

	public function testLinkingWorksWithinTheAccountsTheUserCanWriteTo(): void {
		$this->service->method('findForAccounts')->with(1586, [3, 20, 21])->willReturn($this->rowIn(20));
		$this->service->expects($this->once())->method('attachToBill')
			->with(1586, 120, 'terje', [3, 20])
			->willReturn($this->rowIn(20, 120));

		$response = $this->controller([3, 20, 21], [3, 20])->linkBill(1586, 120);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(120, $response->getData()->getBillId());
	}

	public function testLinkingARowSharedReadOnlyIsForbidden(): void {
		$this->service->method('findForAccounts')->willReturn($this->rowIn(21));
		$this->service->expects($this->never())->method('attachToBill');

		$response = $this->controller([3, 20, 21], [3, 20])->linkBill(1586, 120);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('This shared item is read-only', $response->getData()['error']);
	}

	public function testLinkingARowTheUserCannotSeeIsNotFound(): void {
		$this->service->method('findForAccounts')->willThrowException(
			new DoesNotExistException('Did expect one result but found none when executing: query "SELECT `t`.* FROM `oc_budget_transactions`"')
		);
		$this->service->expects($this->never())->method('attachToBill');

		$response = $this->controller([3], [3])->linkBill(1586, 120);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Transaction not found', $response->getData()['error']);
	}

	public function testARefusalSaysWhy(): void {
		$this->service->method('findForAccounts')->willReturn($this->rowIn(3, 47));
		$this->service->method('attachToBill')->willThrowException(
			new \InvalidArgumentException('That transaction already pays another bill')
		);

		$response = $this->controller([3], [3])->linkBill(1586, 120);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('That transaction already pays another bill', $response->getData()['error']);
	}

	// ── Unlink from bill ────────────────────────────────────────────

	public function testUnlinkingWorksWithinTheAccountsTheUserCanWriteTo(): void {
		$this->service->method('findForAccounts')->with(1586, [3, 20])->willReturn($this->rowIn(20, 120));
		$this->service->expects($this->once())->method('detachFromBill')
			->with(1586, 'terje', [3, 20])
			->willReturn($this->rowIn(20));

		$response = $this->controller([3, 20], [3, 20])->unlinkBill(1586);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()->getBillId());
	}

	public function testUnlinkingARowSharedReadOnlyIsForbidden(): void {
		$this->service->method('findForAccounts')->willReturn($this->rowIn(21, 120));
		$this->service->expects($this->never())->method('detachFromBill');

		$response = $this->controller([3, 21], [3])->unlinkBill(1586);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUnlinkingTheLatestPaymentPointsToMarkUnpaid(): void {
		$this->service->method('findForAccounts')->willReturn($this->rowIn(3, 90));
		$this->service->method('detachFromBill')->willThrowException(
			new \InvalidArgumentException("This is the bill's latest payment. Use Mark Unpaid on the bill to undo it.")
		);

		$response = $this->controller([3], [3])->unlinkBill(1586);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Mark Unpaid', $response->getData()['error']);
	}

	public function testUnlinkingARowTheUserCannotSeeIsNotFound(): void {
		$this->service->method('findForAccounts')->willThrowException(new DoesNotExistException('nope'));

		$response = $this->controller([3], [3])->unlinkBill(1586);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}

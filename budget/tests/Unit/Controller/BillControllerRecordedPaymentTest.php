<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Db\Bill;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mark Paid asks about the payment a bill already has before anything else
 * (#399). It opened the "Existing Transaction Found" dialog first, and two
 * of that dialog's choices never reached the question: a re-opened bill got
 * an unrelated row linked as its second payment, or was marked paid today
 * with a warning that no transaction was recorded. The page now looks the
 * payment up first, and is told only what Mark Paid would refuse over:
 * checked as Mark Paid is checked, looked for as the person paying.
 */
class BillControllerRecordedPaymentTest extends TestCase {
	private const CEMBRAPAY = ['id' => 1350727, 'date' => '2026-09-27', 'amount' => 151.67];

	private BillService $service;
	private GranularShareService $shares;
	private IRequest $request;
	/** @var array<string, mixed> the request's parameters */
	private array $params = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->service = $this->createMock(BillService::class);
		$this->shares = $this->createMock(GranularShareService::class);
	}

	/** @param string $user the person asking; the bill is user1's unless the share says otherwise */
	private function controller(string $user = 'user1'): BillController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []) => vsprintf($text, $parameters));
		return new BillController(
			$this->request,
			$this->service,
			new ValidationService($l),
			$this->shares,
			$this->createMock(\OCA\Budget\Service\Bill\BillSuggestionService::class),
			$this->createMock(\OCA\Budget\Service\UpcomingBillsService::class),
			$l,
			$user,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function billOnAccount(int $accountId): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setAccountId($accountId);
		$bill->setIsTransfer(false);
		return $bill;
	}

	public function testItNamesThePaymentTheLedgerAlreadyHas(): void {
		// The re-opened CembraPay bill, due 2026-09-28, paid on the 27th
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->params = ['dueDate' => '2026-09-28'];
		$this->service->expects($this->once())->method('recordedPaymentForPendingOccurrence')
			->with(1, 'user1', '2026-09-28', 'user1')
			->willReturn(self::CEMBRAPAY);

		$response = $this->controller()->recordedPayment(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['existing' => self::CEMBRAPAY], $response->getData());
	}

	public function testItSaysSoWhenThereIsNone(): void {
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->params = ['dueDate' => '2026-10-28'];
		$this->service->method('recordedPaymentForPendingOccurrence')->willReturn(null);

		$response = $this->controller()->recordedPayment(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['existing' => null], $response->getData());
	}

	public function testWithoutADateItLooksAtTheOccurrenceTheBillIsDueOn(): void {
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->service->expects($this->once())->method('recordedPaymentForPendingOccurrence')
			->with(1, 'user1', null, 'user1')
			->willReturn(null);

		$this->assertSame(Http::STATUS_OK, $this->controller()->recordedPayment(1)->getStatus());
	}

	public function testADateThatIsNotTextIsLeftOut(): void {
		// dueDate[]=x in the query string
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->params = ['dueDate' => ['2026-09-28']];
		$this->service->expects($this->once())->method('recordedPaymentForPendingOccurrence')
			->with(1, 'user1', null, 'user1')
			->willReturn(null);

		$this->assertSame(Http::STATUS_OK, $this->controller()->recordedPayment(1)->getStatus());
	}

	public function testThePaymentIsLookedForAsThePersonPaying(): void {
		// Someone the bill is shared with is only told of a payment in an
		// account they can write to; the lookup itself is the owner's
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->service->method('find')->willReturn($this->billOnAccount(3));
		$this->params = ['dueDate' => '2026-09-28'];
		$this->service->expects($this->once())->method('recordedPaymentForPendingOccurrence')
			->with(1, 'user1', '2026-09-28', 'bob')
			->willReturn(self::CEMBRAPAY);

		$response = $this->controller('bob')->recordedPayment(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['existing' => self::CEMBRAPAY], $response->getData());
	}

	public function testSomeoneWhoCannotPayTheBillIsNotTold(): void {
		// A bill shared read-only: Mark Paid would refuse, and the payment's
		// date and amount are no business of theirs
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->shares->method('requireWriteAccess')->willReturnCallback(function (string $user, string $type) {
			if ($type === 'bill') {
				throw new ReadOnlyShareException();
			}
		});
		$this->service->expects($this->never())->method('recordedPaymentForPendingOccurrence');

		$response = $this->controller('bob')->recordedPayment(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('existing', $response->getData());
	}

	public function testSomeoneWhoCannotWriteToTheBillsAccountIsNotTold(): void {
		// The bill is shared with write, its account read-only: Mark Paid
		// refuses that too
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->service->method('find')->willReturn($this->billOnAccount(3));
		$this->shares->method('requireWriteAccess')->willReturnCallback(function (string $user, string $type, int $id) {
			if ($type === 'account' && $id === 3) {
				throw new ReadOnlyShareException();
			}
		});
		$this->service->expects($this->never())->method('recordedPaymentForPendingOccurrence');

		$response = $this->controller('bob')->recordedPayment(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('existing', $response->getData());
	}

	public function testABillTheyCannotSeeIsNotFound(): void {
		$this->shares->method('resolveOwner')->willReturn(null);
		$this->service->expects($this->never())->method('recordedPaymentForPendingOccurrence');

		$response = $this->controller('bob')->recordedPayment(1);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Bill not found', $response->getData()['error']);
	}

	public function testABillThatIsGoneIsNotFound(): void {
		$this->shares->method('resolveOwner')->willReturn('user1');
		$this->service->method('recordedPaymentForPendingOccurrence')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Bill 1 not found'));

		$response = $this->controller()->recordedPayment(1);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testItIsAWebRouteOfTheBill(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php');

		$this->assertContains(
			['name' => 'bill#recordedPayment', 'url' => '/api/bills/{id}/recorded-payment', 'verb' => 'GET'],
			$routes['routes']
		);
		$this->assertNotContains('bill#recordedPayment', array_column($routes['ocs'] ?? [], 'name'));
	}
}

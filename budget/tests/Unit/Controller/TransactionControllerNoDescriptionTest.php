<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\TransactionController;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Export\TransactionCsvExporter;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A transaction with a vendor and no description (#399).
 *
 * Mark Received books its credit with the income's name as the vendor and
 * no description, and Mark Paid does the same for a bill's payment. The
 * reporter was told to move the Swisscom credit to 3 Oct in the edit form,
 * and the form wouldn't send it: Description was required. Sent anyway, the
 * empty description was stored as null, which the column doesn't take, and
 * the save failed. A description or a vendor now names a transaction, and a
 * description left out is stored empty.
 */
class TransactionControllerNoDescriptionTest extends TestCase {
	private const ACCOUNT = 3;

	/** @var TransactionService&\PHPUnit\Framework\MockObject\MockObject */
	private $service;
	private TransactionController $controller;
	/** The parameters of the request being made */
	private array $params = [];
	/** @var list<array> the updates the service was asked to save */
	private array $saved = [];

	protected function setUp(): void {
		$this->service = $this->createMock(TransactionService::class);
		$this->service->method('update')->willReturnCallback(function (int $id, string $userId, array $updates) {
			$this->saved[] = $updates;
			return new Transaction();
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getOwnAccountIds')->willReturn([self::ACCOUNT]);
		$shares->method('canAccess')->willReturn(true);

		$this->controller = new TransactionController(
			$request,
			$this->service,
			$this->createMock(TransactionSplitService::class),
			$this->createMock(TransactionTagService::class),
			new ValidationService($l),
			$shares,
			new TransactionCsvExporter($l),
			$l,
			'terje',
			$this->createMock(LoggerInterface::class)
		);
	}

	/** The credit Mark Received booked for Gutschrift Swisscom Abo Leon on 19 Oct */
	private function swisscomCredit(?string $vendor = 'Gutschrift Swisscom Abo Leon'): Transaction {
		$row = new Transaction();
		$row->setId(1700);
		$row->setAccountId(self::ACCOUNT);
		$row->setDate('2026-10-19');
		$row->setDescription('');
		$row->setVendor($vendor);
		$row->setAmount(14.90);
		$row->setType('credit');
		$row->setStatus('cleared');
		$row->setNotes('Auto-generated from income: Gutschrift Swisscom Abo Leon');
		$this->service->method('find')->willReturn($row);
		return $row;
	}

	/** PUT /api/transactions/{id} with $params, as the request carries them */
	private function put(int $id, array $params) {
		$this->params = $params;
		return $this->controller->update($id, ...array_intersect_key($params, array_flip([
			'date', 'description', 'amount', 'type', 'categoryId', 'accountId', 'vendor', 'reference', 'notes', 'reconciled', 'status', 'excludedFromForecast',
		])));
	}

	/** What the edit form sends for the credit with its date moved to 3 Oct */
	private static function formSave(array $changes = []): array {
		return $changes + [
			'date' => '2026-10-03',
			'accountId' => self::ACCOUNT,
			'type' => 'credit',
			'amount' => 14.9,
			'description' => '',
			'vendor' => 'Gutschrift Swisscom Abo Leon',
			'categoryId' => null,
			'notes' => 'Auto-generated from income: Gutschrift Swisscom Abo Leon',
			'excludedFromForecast' => false,
		];
	}

	public function testTheEditFormSavesADateChangeOnACreditWithNoDescription(): void {
		$this->swisscomCredit();

		$response = $this->put(1700, self::formSave());

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->saved);
		$this->assertSame('2026-10-03', $this->saved[0]['date']);
		// Stored empty, never null: the column is NOT NULL
		$this->assertSame('', $this->saved[0]['description']);
		$this->assertSame('Gutschrift Swisscom Abo Leon', $this->saved[0]['vendor']);
	}

	/** The stored vendor counts when the request doesn't change it */
	public function testAnEmptyDescriptionIsKeptOnARowWithAVendor(): void {
		$this->swisscomCredit();

		foreach (['', '   '] as $description) {
			$this->saved = [];
			$response = $this->put(1700, ['description' => $description, 'date' => '2026-10-03']);

			$this->assertSame(Http::STATUS_OK, $response->getStatus());
			$this->assertSame([['description' => '', 'date' => '2026-10-03']], $this->saved);
		}
	}

	/** A row named by nothing at all reads as a blank line in the list */
	public function testClearingTheDescriptionOfARowWithNoVendorIsRefused(): void {
		$this->swisscomCredit(null);

		foreach (['', '   '] as $description) {
			$response = $this->put(1700, ['description' => $description]);

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame('Description is required', $response->getData()['error']);
		}
		$this->assertSame([], $this->saved);
	}

	public function testClearingTheDescriptionAndTheVendorIsRefused(): void {
		$this->swisscomCredit();

		$response = $this->put(1700, self::formSave(['vendor' => '']));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Description is required', $response->getData()['error']);
		$this->assertSame([], $this->saved);
	}

	/** The list edits the vendor cell on its own, with no description sent */
	public function testClearingTheVendorOfARowWithNoDescriptionIsRefused(): void {
		$this->swisscomCredit();

		foreach (['', '   ', null] as $vendor) {
			$response = $this->put(1700, ['vendor' => $vendor]);

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame('Description is required', $response->getData()['error']);
		}
		$this->assertSame([], $this->saved);
	}

	public function testTheVendorOfARowWithADescriptionCanBeCleared(): void {
		$this->swisscomCredit()->setDescription('Gutschrift Swisscom');

		$this->assertSame(Http::STATUS_OK, $this->put(1700, ['vendor' => ''])->getStatus());
		$this->assertCount(1, $this->saved);
	}

	/** An edit that leaves the description alone is never held up by it */
	public function testAnEditWithoutTheDescriptionIsUnchanged(): void {
		$this->swisscomCredit(null);

		$this->assertSame(Http::STATUS_OK, $this->put(1700, ['date' => '2026-10-03'])->getStatus());
		$this->assertSame([['date' => '2026-10-03']], $this->saved);
	}

	public function testANewTransactionWithAVendorNeedsNoDescription(): void {
		$created = null;
		$this->service->expects($this->once())->method('create')->willReturnCallback(
			function (string $userId, int $accountId, string $date, string $description, float $amount, string $type, ?int $categoryId, ?string $vendor) use (&$created) {
				$created = [$userId, $accountId, $date, $description, $amount, $type, $categoryId, $vendor];
				return new Transaction();
			}
		);

		$response = $this->controller->create(self::ACCOUNT, '2026-10-03', '', 14.9, 'credit', null, 'Gutschrift Swisscom Abo Leon');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['terje', self::ACCOUNT, '2026-10-03', '', 14.9, 'credit', null, 'Gutschrift Swisscom Abo Leon'], $created);
	}

	public function testANewTransactionNeedsADescriptionOrAVendor(): void {
		$this->service->expects($this->never())->method('create');

		foreach ([['', null], ['', '  '], ['  ', null]] as [$description, $vendor]) {
			$response = $this->controller->create(self::ACCOUNT, '2026-10-03', $description, 14.9, 'credit', null, $vendor);

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame('Description is required', $response->getData()['error']);
		}
	}

	public function testADescriptionStillSavesAsItIs(): void {
		$this->service->expects($this->once())->method('create')
			->with('terje', self::ACCOUNT, '2026-10-03', $this->identicalTo('Monatsrechnung'), 100.0, 'debit', null, null)
			->willReturn(new Transaction());

		$response = $this->controller->create(self::ACCOUNT, '2026-10-03', ' Monatsrechnung ', 100.0, 'debit');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}
}

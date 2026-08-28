<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Traits;

use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Http;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Test the ApiErrorHandlerTrait via a concrete test class that uses it.
 */
class ApiErrorHandlerTraitTest extends TestCase {
	private ApiErrorHandlerTraitTestClass $subject;

	protected function setUp(): void {
		$this->subject = new ApiErrorHandlerTraitTestClass();
		// Use a real null logger so logError() is exercised without noise.
		$this->subject->callSetLogger(new NullLogger());
	}

	// ── generic (non-database) errors ──────────────────────────────

	public function testGenericErrorReturnsGenericMessageWithoutDetail(): void {
		$response = $this->subject->callHandleError(
			new \RuntimeException('internal boom'),
			'Failed to create transaction'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Failed to create transaction', $data['error']);
		$this->assertArrayNotHasKey('detail', $data);
	}

	public function testGenericErrorRespectsCustomStatusCode(): void {
		$response = $this->subject->callHandleError(
			new \RuntimeException('boom'),
			'Server error',
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── a schema that is behind gets an actionable hint ────────────

	/**
	 * A missing column means the app's files were updated but Nextcloud never
	 * ran the app upgrade, so its migrations never applied. The driver detail
	 * says "Unknown column", which is true and useless to the person reading it.
	 * Reproduced on MariaDB: `occ upgrade` does not fix it (that upgrades the
	 * server) and `occ app:update` does not either (that checks the app store) --
	 * disabling and re-enabling the app is what runs the pending migrations
	 * (#333).
	 */
	public function testMissingColumnAddsAHintNamingTheFix(): void {
		$response = $this->subject->callHandleError(
			new DbException("An exception occurred while executing a query: "
				. "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'amount_type' in 'INSERT INTO'"),
			'Failed to create bill'
		);

		$data = $response->getData();
		$this->assertArrayHasKey('hint', $data);
		$this->assertStringContainsString('occ app:disable budget', $data['hint']);
		$this->assertStringContainsString('occ app:enable budget', $data['hint']);
		// The raw driver detail is still there for whoever wants it.
		$this->assertStringContainsString("Unknown column 'amount_type'", $data['detail']);
	}

	public function testMissingTableAlsoGetsTheHint(): void {
		$response = $this->subject->callHandleError(
			new DbException("SQLSTATE[42S02]: Base table or view not found: 1146 "
				. "Table 'nextcloud.oc_budget_idem_keys' doesn't exist"),
			'Failed to save'
		);

		$this->assertArrayHasKey('hint', $response->getData());
	}

	public function testSqliteAndPostgresPhrasingsAreRecognised(): void {
		foreach ([
			'SQLSTATE[HY000]: General error: 1 no such column: amount_type',
			'SQLSTATE[42703]: Undefined column: 7 ERROR:  column "amount_type" does not exist',
			'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "oc_budget_bills" does not exist',
		] as $message) {
			$data = $this->subject->callHandleError(new DbException($message), 'Failed')->getData();
			$this->assertArrayHasKey('hint', $data, 'no hint for: ' . $message);
		}
	}

	/**
	 * A constraint violation is the user's data being wrong, not the schema
	 * being behind. Telling them to reinstall the app would be wrong.
	 */
	public function testAnOrdinaryDatabaseErrorGetsNoHint(): void {
		$response = $this->subject->callHandleError(
			new DbException("SQLSTATE[23000]: Integrity constraint violation: 1048 "
				. "Column 'status' cannot be null"),
			'Failed to create transaction'
		);

		$data = $response->getData();
		$this->assertArrayHasKey('detail', $data);
		$this->assertArrayNotHasKey('hint', $data);
	}

	public function testANonDatabaseErrorGetsNoHint(): void {
		$data = $this->subject->callHandleError(new \RuntimeException('boom'), 'Failed')->getData();
		$this->assertArrayNotHasKey('hint', $data);
	}

	// ── database errors surface a sanitised detail ─────────────────

	public function testDatabaseErrorAddsDetail(): void {
		$dbMessage = "An exception occurred while executing a query: "
			. "SQLSTATE[42S22]: Column not found: 1054 Unknown column "
			. "'excluded_from_forecast' in 'field list'";
		$response = $this->subject->callHandleError(
			new DbException($dbMessage),
			'Failed to create transaction'
		);

		$data = $response->getData();
		$this->assertSame('Failed to create transaction', $data['error']);
		$this->assertArrayHasKey('detail', $data);
		// The user-facing detail keeps the driver portion...
		$this->assertStringStartsWith('SQLSTATE[42S22]', $data['detail']);
		$this->assertStringContainsString("Unknown column 'excluded_from_forecast'", $data['detail']);
		// ...and strips the "executing a query" SQL prefix.
		$this->assertStringNotContainsString('executing a query', $data['detail']);
	}

	public function testDatabaseErrorExtractedFromPreviousException(): void {
		$db = new DbException("SQLSTATE[HY000]: General error: 1364 Field 'status' doesn't have a default value");
		$wrapped = new \RuntimeException('Service failed', 0, $db);

		$response = $this->subject->callHandleError($wrapped, 'Failed to create transaction');

		$data = $response->getData();
		$this->assertArrayHasKey('detail', $data);
		$this->assertStringContainsString("Field 'status'", $data['detail']);
	}

	public function testDatabaseErrorWithoutSqlstateMarkerKeepsFullMessage(): void {
		$response = $this->subject->callHandleError(
			new DbException('Connection refused'),
			'Failed to create transaction'
		);

		$data = $response->getData();
		$this->assertSame('Connection refused', $data['detail']);
	}

	public function testDatabaseErrorDetailIsTruncated(): void {
		$long = 'SQLSTATE[42S22]: ' . str_repeat('x', 500);
		$response = $this->subject->callHandleError(
			new DbException($long),
			'Failed to create transaction'
		);

		$detail = $response->getData()['detail'];
		$this->assertLessThanOrEqual(300, strlen($detail));
		$this->assertStringEndsWith('...', $detail);
	}

	// ── read-only share short-circuit ──────────────────────────────

	public function testReadOnlyShareExceptionReturns403WithoutDetail(): void {
		$response = $this->subject->callHandleError(
			new ReadOnlyShareException('nope'),
			'Failed to create transaction'
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('This shared item is read-only', $data['error']);
		$this->assertArrayNotHasKey('detail', $data);
	}

	// ── not-found helper ───────────────────────────────────────────

	public function testHandleNotFoundError(): void {
		$response = $this->subject->callHandleNotFoundError(
			new \RuntimeException('missing'),
			'Transaction'
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Transaction not found', $response->getData()['error']);
	}

	public function testHandleNotFoundErrorOnDbExceptionStillAddsDetail(): void {
		$response = $this->subject->callHandleNotFoundError(
			new DbException("SQLSTATE[42S02]: Base table or view not found"),
			'Transaction'
		);

		$data = $response->getData();
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString('Base table or view not found', $data['detail']);
	}

	// ── validation helper (always exposes the message) ─────────────

	public function testHandleValidationErrorExposesMessage(): void {
		$response = $this->subject->callHandleValidationError(
			new \InvalidArgumentException('Amount must be positive')
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Amount must be positive', $response->getData()['error']);
	}
}

/**
 * Concrete class that uses ApiErrorHandlerTrait for testing.
 * Exposes protected methods via public wrappers.
 */
class ApiErrorHandlerTraitTestClass {
	use ApiErrorHandlerTrait;

	public function callSetLogger(LoggerInterface $logger): void {
		$this->setLogger($logger);
	}

	public function callHandleError(
		\Throwable $e,
		string $genericMessage = 'An error occurred',
		int $statusCode = Http::STATUS_BAD_REQUEST,
		array $context = []
	): \OCP\AppFramework\Http\DataResponse {
		return $this->handleError($e, $genericMessage, $statusCode, $context);
	}

	public function callHandleNotFoundError(
		\Throwable $e,
		string $entityType = 'Resource',
		array $context = []
	): \OCP\AppFramework\Http\DataResponse {
		return $this->handleNotFoundError($e, $entityType, $context);
	}

	public function callHandleValidationError(
		\Throwable $e,
		array $context = []
	): \OCP\AppFramework\Http\DataResponse {
		return $this->handleValidationError($e, $context);
	}
}

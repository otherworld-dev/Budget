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

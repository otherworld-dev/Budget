<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

/**
 * Trait for handling API errors securely.
 *
 * Logs full exception details server-side while returning
 * generic error messages to clients to prevent information disclosure.
 */
trait ApiErrorHandlerTrait {
    protected ?LoggerInterface $logger = null;

    /**
     * Set the logger instance for error logging.
     */
    protected function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }

    /**
     * Create a safe error response that doesn't expose internal details.
     *
     * @param \Throwable $e The exception to handle
     * @param string $genericMessage Generic message to show to user
     * @param int $statusCode HTTP status code
     * @param array $context Additional context for logging
     * @return DataResponse
     */
    protected function handleError(
        \Throwable $e,
        string $genericMessage = 'An error occurred',
        int $statusCode = Http::STATUS_BAD_REQUEST,
        array $context = []
    ): DataResponse {
        // Log the full error details server-side
        $this->logError($e, $context);

        // Return generic message to client
        return new DataResponse(
            ['error' => $genericMessage],
            $statusCode
        );
    }

    /**
     * Handle not found errors.
     */
    protected function handleNotFoundError(
        \Throwable $e,
        string $entityType = 'Resource',
        array $context = []
    ): DataResponse {
        return $this->handleError(
            $e,
            "{$entityType} not found",
            Http::STATUS_NOT_FOUND,
            $context
        );
    }

    /**
     * Handle validation errors - these can show the actual message
     * since they contain user-facing validation feedback.
     */
    protected function handleValidationError(
        \Throwable $e,
        array $context = []
    ): DataResponse {
        // Validation errors are safe to expose
        return new DataResponse(
            ['error' => $e->getMessage()],
            Http::STATUS_BAD_REQUEST
        );
    }

    /**
     * Log error details server-side.
     */
    private function logError(\Throwable $e, array $context = []): void {
        if ($this->logger === null) {
            // Fallback to error_log if no logger configured
            error_log(sprintf(
                '[Budget App Error] %s: %s in %s:%d | Context: %s',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($context)
            ));
            return;
        }

        $this->logger->error(
            'Budget app error: ' . $e->getMessage(),
            array_merge([
                'exception' => $e,
                'app' => 'budget',
            ], $context)
        );
    }
}

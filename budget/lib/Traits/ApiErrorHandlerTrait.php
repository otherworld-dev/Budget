<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
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
     * Get the IL10N instance for translations, if available.
     * Controllers using this trait should have $this->l set via constructor injection.
     */
    protected function getL10N(): ?IL10N {
        return property_exists($this, 'l') ? $this->l : null;
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
        // Read-only share violations return 403 with a clear message
        if ($e instanceof \OCA\Budget\Exception\ReadOnlyShareException) {
            $l = $this->getL10N();
            $message = $l !== null
                ? $l->t('This shared item is read-only')
                : 'This shared item is read-only';
            return new DataResponse(['error' => $message], Http::STATUS_FORBIDDEN);
        }

        // Log the full error details server-side
        $this->logError($e, $context);

        $body = ['error' => $genericMessage];

        // Database errors are otherwise invisible on managed Nextcloud instances
        // where admins cannot read nextcloud.log. Surface a sanitised detail of
        // the driver error (e.g. a missing column) so the cause is diagnosable
        // from the browser's network tab. The generic, translated message is
        // still what the UI shows; this only adds a separate diagnostic field.
        $dbDetail = $this->extractDbErrorDetail($e);
        if ($dbDetail !== null) {
            $body['detail'] = $dbDetail;
        }

        return new DataResponse($body, $statusCode);
    }

    /**
     * Extract a safe, human-readable detail string from a database exception.
     *
     * Returns null for non-database errors. The result is limited to the
     * driver's error message (which describes schema/constraint problems such
     * as a missing column) with the executed SQL and any bound parameters
     * stripped, so no stack trace or query bindings are ever exposed.
     */
    private function extractDbErrorDetail(\Throwable $e): ?string {
        $dbException = null;
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof \OCP\DB\Exception) {
                $dbException = $cursor;
                break;
            }
        }
        if ($dbException === null) {
            return null;
        }

        $message = $dbException->getMessage();

        // Doctrine prefixes the message with the executed SQL (and, on older
        // versions, the bound parameters). Keep only the driver portion that
        // starts at the SQLSTATE marker, which drops any data values.
        $pos = strpos($message, 'SQLSTATE');
        if ($pos !== false) {
            $message = substr($message, $pos);
        }

        $message = trim($message);
        if ($message === '') {
            return null;
        }

        // Hard cap so a pathological driver message can't bloat the response.
        if (strlen($message) > 300) {
            $message = substr($message, 0, 297) . '...';
        }

        return $message;
    }

    /**
     * Handle not found errors.
     */
    protected function handleNotFoundError(
        \Throwable $e,
        string $entityType = 'Resource',
        array $context = []
    ): DataResponse {
        $l = $this->getL10N();
        $message = $l !== null
            ? $l->t('%1$s not found', [$entityType])
            : "{$entityType} not found";

        return $this->handleError(
            $e,
            $message,
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

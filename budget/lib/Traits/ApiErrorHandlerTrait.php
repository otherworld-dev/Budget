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

            $hint = $this->schemaBehindHint($dbDetail);
            if ($hint !== null) {
                $body['hint'] = $hint;
            }
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
     * Turn a missing column or table into the instruction that fixes it.
     *
     * A missing column means the app's files were updated but Nextcloud never
     * ran the app upgrade, so the migrations that add it never applied — the
     * recorded version stays behind while the code moves on. Reads do not
     * notice (the mapper selects *), so the first sign is an INSERT failing
     * with "Unknown column", which is accurate and no use to the person
     * reading it.
     *
     * Reproduced on MariaDB while chasing #333: `occ upgrade` does not fix it,
     * because that upgrades the SERVER; `occ app:update` does not either,
     * because that looks for a newer release in the app store. Disabling and
     * re-enabling the app is what makes Nextcloud run the pending migrations.
     *
     * Deliberately narrow: a constraint violation or a bad value is the data
     * being wrong, not the schema being behind, and telling someone to
     * reinstall the app over a null column would be worse than saying nothing.
     */
    private function schemaBehindHint(string $detail): ?string {
        $schemaErrors = [
            'SQLSTATE[42S22]',  // MySQL/MariaDB: column not found
            'SQLSTATE[42S02]',  // MySQL/MariaDB: table not found
            'SQLSTATE[42703]',  // PostgreSQL: undefined column
            'SQLSTATE[42P01]',  // PostgreSQL: undefined table
        ];
        $isSchemaError = false;
        foreach ($schemaErrors as $sqlState) {
            if (str_contains($detail, $sqlState)) {
                $isSchemaError = true;
                break;
            }
        }
        // SQLite reports both as a generic HY000, so match on its wording -
        // and it has two: SELECT/UPDATE say "no such column", INSERT says
        // "table X has no column named Y". The INSERT one is exactly the
        // failed save this hint exists for, and it slipped through until a
        // browser run against a dropped column showed the bare SQL (#333).
        if (!$isSchemaError
            && (stripos($detail, 'no such column') !== false
                || stripos($detail, 'has no column named') !== false
                || stripos($detail, 'no such table') !== false)) {
            $isSchemaError = true;
        }
        if (!$isSchemaError) {
            return null;
        }

        $l = $this->getL10N();

        // Ask the schema check what is actually missing and which migration
        // to re-run: the generic disable/enable line below was followed to the
        // letter on the #333 instance and fixed nothing, because its migration
        // was already recorded as applied. recheck() ignores the "verified"
        // marker — a save just failed on the schema, so it is stale.
        try {
            $warning = \OCP\Server::get(\OCA\Budget\Service\SchemaVersionService::class)->recheck();
        } catch (\Throwable $e) {
            $warning = null;
        }
        if ($warning !== null && ($warning['command'] ?? '') !== '') {
            $fix = $l !== null
                ? $l->t('An administrator can finish it by running: %1$s', [$warning['command']])
                : 'An administrator can finish it by running: ' . $warning['command'];
            return trim($warning['message'] . ' ' . implode(' ', $warning['details'] ?? []) . ' ' . $fix);
        }

        // The literal is repeated rather than built in a variable: xgettext can
        // only extract a string literal passed straight to t(), and a variable
        // here silently left the hint out of the translation template.
        return $l !== null
            ? $l->t('The database is missing something this version of Budget needs, which means the update did not finish. An administrator can complete it by running: occ app:disable budget && occ app:enable budget')
            : 'The database is missing something this version of Budget needs, which means the update did not finish. An administrator can complete it by running: occ app:disable budget && occ app:enable budget';
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

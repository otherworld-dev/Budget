<?php

declare(strict_types=1);

namespace OCA\Budget\SetupChecks;

use OCA\Budget\Service\SchemaVersionService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Surfaces SchemaVersionService::getWarning() (#333) in the admin overview
 * (Settings > Administration > Overview). Before this, nothing but
 * PageController read it, so an admin who never opened the app itself as a
 * logged-in user would never see that its migrations had never actually run
 * — the same "Unknown column" trap #333 describes, just invisible to the one
 * person who could fix it with occ.
 *
 * An error, not a warning: a pending migration means saves can start failing
 * at any moment (#333), not just a cosmetic issue.
 */
class BudgetSchemaCheck implements ISetupCheck {
    public function __construct(
        private SchemaVersionService $schemaVersionService,
        private IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'database';
    }

    public function getName(): string {
        return $this->l->t('Budget database migrations');
    }

    public function run(): SetupResult {
        $warning = $this->schemaVersionService->getWarning();
        if ($warning === null) {
            return SetupResult::success($this->l->t('Budget\'s database schema is up to date.'));
        }

        // The overview is the one place the administrator who can run occ
        // actually looks, so it carries what is missing and the fix, not
        // just the headline (#333).
        $lines = [$warning['message']];
        foreach ($warning['details'] ?? [] as $detail) {
            $lines[] = '- ' . $detail;
        }
        if (($warning['command'] ?? '') !== '') {
            $lines[] = $this->l->t('An administrator can finish it by running: %1$s', [$warning['command']]);
        }

        return SetupResult::error(implode("\n", $lines));
    }
}

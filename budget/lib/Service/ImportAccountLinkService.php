<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportAccountLink;
use OCA\Budget\Db\ImportAccountLinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Silent auto-remember of OFX/QIF account routing.
 *
 * After a multi-account import, {@see remember()} records which destination
 * account each source account was routed to; on the next upload {@see recall()}
 * provides those routings so the importer can pre-fill the destination
 * (so QIF gets auto-fill too, and OFX when its built-in match misses).
 *
 * Uniqueness of (user_id, format, source_key) is enforced here via
 * find-then-upsert.
 */
class ImportAccountLinkService {
    private const FORMATS = ['ofx', 'qif'];

    private ImportAccountLinkMapper $mapper;

    public function __construct(ImportAccountLinkMapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * Record source-account -> destination-account routings. Best-effort:
     * unsupported formats and invalid entries are skipped.
     *
     * @param array<string, int|string> $sourceToDest source key => budget account id
     */
    public function remember(string $userId, string $format, array $sourceToDest): void {
        if (!in_array($format, self::FORMATS, true)) {
            return;
        }
        $now = date('Y-m-d H:i:s');

        foreach ($sourceToDest as $sourceKey => $destId) {
            $key = trim((string) $sourceKey);
            $id = (int) $destId;
            if ($key === '' || $id <= 0) {
                continue;
            }
            if (mb_strlen($key) > 255) {
                $key = mb_substr($key, 0, 255);
            }

            try {
                $link = $this->mapper->findBySource($userId, $format, $key);
                if ($link->getBudgetAccountId() !== $id) {
                    $link->setBudgetAccountId($id);
                    $link->setUpdatedAt($now);
                    $this->mapper->update($link);
                }
            } catch (DoesNotExistException $e) {
                $link = new ImportAccountLink();
                $link->setUserId($userId);
                $link->setFormat($format);
                $link->setSourceKey($key);
                $link->setBudgetAccountId($id);
                $link->setUpdatedAt($now);
                $this->mapper->insert($link);
            }
        }
    }

    /**
     * Remembered routings for a format.
     *
     * @return array<string, int> source key => budget account id
     */
    public function recall(string $userId, string $format): array {
        if (!in_array($format, self::FORMATS, true)) {
            return [];
        }
        $map = [];
        foreach ($this->mapper->findAllByFormat($userId, $format) as $link) {
            $map[$link->getSourceKey()] = $link->getBudgetAccountId();
        }
        return $map;
    }
}

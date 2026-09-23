<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareAutoConfig;
use OCA\Budget\Db\ShareAutoConfigMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Auto-share new entities (#306). When an owner creates a new entity, any
 * accepted share that has opted into auto-sharing that entity type receives it
 * automatically, at the permission the owner chose.
 */
class AutoShareService {
	private ShareAutoConfigMapper $autoConfigMapper;
	private ShareItemMapper $shareItemMapper;
	private ShareMapper $shareMapper;
	private LoggerInterface $logger;

	public function __construct(
		ShareAutoConfigMapper $autoConfigMapper,
		ShareItemMapper $shareItemMapper,
		ShareMapper $shareMapper,
		LoggerInterface $logger,
	) {
		$this->autoConfigMapper = $autoConfigMapper;
		$this->shareItemMapper = $shareItemMapper;
		$this->shareMapper = $shareMapper;
		$this->logger = $logger;
	}

	/**
	 * Share a freshly-created entity with every accepted share that auto-shares
	 * this type. Best-effort: any failure is logged and swallowed so it can never
	 * break the entity creation that triggered it.
	 */
	public function autoShareNewEntity(string $ownerUserId, string $entityType, int $entityId): void {
		try {
			$targets = $this->autoConfigMapper->findActiveForOwnerAndType($ownerUserId, $entityType);
			foreach ($targets as $target) {
				$this->shareItemMapper->shareEntity($target['shareId'], $entityType, $entityId, $target['permission']);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Budget auto-share failed for ' . $entityType . ' #' . $entityId . ': ' . $e->getMessage(),
				['app' => 'budget', 'exception' => $e]
			);
		}
	}

	/**
	 * The auto-share rules for one of the owner's shares (one per enabled type).
	 *
	 * @return ShareAutoConfig[]
	 */
	public function getConfigs(string $ownerUserId, int $shareId): array {
		$this->assertOwner($ownerUserId, $shareId);
		return $this->autoConfigMapper->findByShareId($shareId);
	}

	/**
	 * Enable or disable auto-share of an entity type for one of the owner's shares.
	 */
	public function setConfig(string $ownerUserId, int $shareId, string $entityType, bool $enabled, string $permission): void {
		$this->assertOwner($ownerUserId, $shareId);

		if (!in_array($entityType, ShareItem::VALID_TYPES, true)) {
			throw new \InvalidArgumentException('Invalid entity type');
		}
		if ($enabled) {
			if (!in_array($permission, [ShareItem::PERMISSION_READ, ShareItem::PERMISSION_WRITE], true)) {
				throw new \InvalidArgumentException('Invalid permission');
			}
			$this->autoConfigMapper->setConfig($shareId, $entityType, $permission);
		} else {
			$this->autoConfigMapper->removeConfig($shareId, $entityType);
		}
	}

	/**
	 * Verify the share exists and belongs to the given owner.
	 */
	private function assertOwner(string $ownerUserId, int $shareId): void {
		try {
			$share = $this->shareMapper->findById($shareId);
		} catch (DoesNotExistException $e) {
			throw new \InvalidArgumentException('Share not found');
		}
		if ($share->getOwnerUserId() !== $ownerUserId) {
			throw new \InvalidArgumentException('Not your share');
		}
	}
}

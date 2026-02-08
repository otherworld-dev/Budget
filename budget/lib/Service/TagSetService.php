<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSet;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCP\AppFramework\Db\Entity;

/**
 * @extends AbstractCrudService<TagSet>
 */
class TagSetService extends AbstractCrudService {
    private TagMapper $tagMapper;
    private CategoryMapper $categoryMapper;
    private TransactionTagMapper $transactionTagMapper;
    private SavingsGoalMapper $savingsGoalMapper;

    public function __construct(
        TagSetMapper $mapper,
        TagMapper $tagMapper,
        CategoryMapper $categoryMapper,
        TransactionTagMapper $transactionTagMapper,
        SavingsGoalMapper $savingsGoalMapper
    ) {
        $this->mapper = $mapper;
        $this->tagMapper = $tagMapper;
        $this->categoryMapper = $categoryMapper;
        $this->transactionTagMapper = $transactionTagMapper;
        $this->savingsGoalMapper = $savingsGoalMapper;
    }

    /**
     * Create a new tag set for a category
     */
    public function create(
        string $userId,
        int $categoryId,
        string $name,
        ?string $description = null,
        int $sortOrder = 0
    ): TagSet {
        // Validate category exists and belongs to user
        $this->categoryMapper->find($categoryId, $userId);

        $tagSet = new TagSet();
        $tagSet->setCategoryId($categoryId);
        $tagSet->setName($name);
        $tagSet->setDescription($description);
        $tagSet->setSortOrder($sortOrder);
        $this->setTimestamps($tagSet, true);

        return $this->mapper->insert($tagSet);
    }

    /**
     * Find tag sets by category
     *
     * @return TagSet[]
     */
    public function findByCategory(int $categoryId, string $userId): array {
        return $this->mapper->findByCategory($categoryId, $userId);
    }

    /**
     * Get tag sets with all their tags loaded
     *
     * @return TagSet[]
     */
    public function getCategoryTagSetsWithTags(int $categoryId, string $userId): array {
        $tagSets = $this->findByCategory($categoryId, $userId);

        if (empty($tagSets)) {
            return [];
        }

        // Batch load all tags for these tag sets
        $tagSetIds = array_map(fn($ts) => $ts->getId(), $tagSets);
        $tagsGrouped = $this->tagMapper->findByTagSets($tagSetIds);

        // Populate tags on each tag set
        foreach ($tagSets as $tagSet) {
            $tagSet->setTags($tagsGrouped[$tagSet->getId()] ?? []);
        }

        return $tagSets;
    }

    /**
     * Get all tag sets with their tags loaded (for reports filtering)
     *
     * @return TagSet[]
     */
    public function getAllTagSetsWithTags(string $userId): array {
        $tagSets = $this->findAll($userId);

        if (empty($tagSets)) {
            return [];
        }

        // Batch load all tags for these tag sets
        $tagSetIds = array_map(fn($ts) => $ts->getId(), $tagSets);
        $tagsGrouped = $this->tagMapper->findByTagSets($tagSetIds);

        // Populate tags on each tag set
        foreach ($tagSets as $tagSet) {
            $tagSet->setTags($tagsGrouped[$tagSet->getId()] ?? []);
        }

        return $tagSets;
    }

    /**
     * Get a single tag set with its tags loaded
     */
    public function getTagSetWithTags(int $tagSetId, string $userId): TagSet {
        $tagSet = $this->find($tagSetId, $userId);
        $tags = $this->tagMapper->findByTagSet($tagSetId);
        $tagSet->setTags($tags);

        return $tagSet;
    }

    /**
     * Create a new tag within a tag set
     */
    public function createTag(
        int $tagSetId,
        string $userId,
        string $name,
        ?string $color = null,
        int $sortOrder = 0
    ): Tag {
        // Validate tag set exists and belongs to user
        $this->find($tagSetId, $userId);

        $tag = new Tag();
        $tag->setTagSetId($tagSetId);
        $tag->setName($name);
        $tag->setColor($color ?: $this->generateRandomColor());
        $tag->setSortOrder($sortOrder);
        $tag->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->tagMapper->insert($tag);
    }

    /**
     * Update a tag
     */
    public function updateTag(int $tagId, string $userId, array $updates): Tag {
        $tag = $this->tagMapper->find($tagId, $userId);

        $this->applyUpdates($tag, $updates);

        return $this->tagMapper->update($tag);
    }

    /**
     * Delete a tag (cascade deletes transaction_tags)
     */
    public function deleteTag(int $tagId, string $userId): void {
        $tag = $this->tagMapper->find($tagId, $userId);

        // Clear tag references on savings goals linked to this tag
        $this->savingsGoalMapper->clearTagReference($tagId);

        // Delete associated transaction tags first (cascade delete)
        $this->transactionTagMapper->deleteByTag($tagId);

        $this->tagMapper->delete($tag);
    }

    /**
     * @inheritDoc
     */
    protected function beforeUpdate(Entity $entity, array $updates, string $userId): void {
        // Validate new category if being updated
        if (isset($updates['categoryId'])) {
            $this->categoryMapper->find($updates['categoryId'], $userId);
        }
    }

    /**
     * @inheritDoc
     */
    protected function beforeDelete(Entity $entity, string $userId): void {
        /** @var TagSet $entity */
        // Cascade delete: Delete all tags in this tag set
        $tags = $this->tagMapper->findByTagSet($entity->getId());
        foreach ($tags as $tag) {
            // Clear savings goal references to this tag
            $this->savingsGoalMapper->clearTagReference($tag->getId());
            // Delete associated transaction tags first
            $this->transactionTagMapper->deleteByTag($tag->getId());
            // Then delete the tag itself
            $this->tagMapper->delete($tag);
        }
    }

    /**
     * Generate a random HSL color
     */
    private function generateRandomColor(): string {
        $hue = rand(0, 360);
        $saturation = rand(65, 85);
        $lightness = rand(55, 65);

        return $this->hslToHex($hue, $saturation, $lightness);
    }

    /**
     * Convert HSL to hex color
     */
    private function hslToHex(int $h, int $s, int $l): string {
        $h = $h / 360;
        $s = $s / 100;
        $l = $l / 100;

        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hue2rgb($p, $q, $h + 1/3);
            $g = $this->hue2rgb($p, $q, $h);
            $b = $this->hue2rgb($p, $q, $h - 1/3);
        }

        return sprintf('#%02x%02x%02x',
            round($r * 255),
            round($g * 255),
            round($b * 255)
        );
    }

    private function hue2rgb(float $p, float $q, float $t): float {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }
}

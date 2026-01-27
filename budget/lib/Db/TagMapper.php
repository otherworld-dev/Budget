<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Tag>
 */
class TagMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_tags', Tag::class);
    }

    /**
     * Find a tag by ID with user validation via tag set -> category ownership
     *
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Tag {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_tag_sets', 'ts', 't.tag_set_id = ts.id')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * Find all tags for a specific tag set
     *
     * @return Tag[]
     */
    public function findByTagSet(int $tagSetId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('tag_set_id', $qb->createNamedParameter($tagSetId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find all tags for multiple tag sets in a single query (batch, avoids N+1)
     *
     * @param int[] $tagSetIds
     * @return array<int, Tag[]> tagSetId => Tag[]
     */
    public function findByTagSets(array $tagSetIds): array {
        if (empty($tagSetIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('tag_set_id', $qb->createNamedParameter($tagSetIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        $tags = $this->findEntities($qb);

        // Group tags by tag set ID
        $result = [];
        foreach ($tags as $tag) {
            $tagSetId = $tag->getTagSetId();
            if (!isset($result[$tagSetId])) {
                $result[$tagSetId] = [];
            }
            $result[$tagSetId][] = $tag;
        }

        return $result;
    }

    /**
     * Find multiple tags by IDs in a single query
     *
     * @param int[] $ids
     * @return array<int, Tag> tagId => Tag
     */
    public function findByIds(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        $entities = $this->findEntities($qb);

        // Index by ID for quick lookup
        $result = [];
        foreach ($entities as $entity) {
            $result[$entity->getId()] = $entity;
        }

        return $result;
    }

    /**
     * Delete all tags for a user (via tag sets)
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        // Get all tag IDs for this user first
        $qb->select('t.id')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_tag_sets', 'ts', 't.tag_set_id = ts.id')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $tagIds = $result->fetchAll(\PDO::FETCH_COLUMN);
        $result->closeCursor();

        if (empty($tagIds)) {
            return 0;
        }

        // Delete tags (cascade handles transaction_tags)
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }
}

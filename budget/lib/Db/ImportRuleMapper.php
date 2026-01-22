<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ImportRule>
 */
class ImportRuleMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_import_rules', ImportRule::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): ImportRule {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        
        return $this->findEntity($qb);
    }

    /**
     * @return ImportRule[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('priority', 'DESC')
            ->addOrderBy('name', 'ASC');
        
        return $this->findEntities($qb);
    }

    /**
     * @return ImportRule[]
     */
    public function findActive(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('priority', 'DESC')
            ->addOrderBy('id', 'ASC');
        
        return $this->findEntities($qb);
    }

    /**
     * Find matching rule for transaction
     */
    public function findMatchingRule(string $userId, array $transactionData): ?ImportRule {
        $rules = $this->findActive($userId);
        
        foreach ($rules as $rule) {
            if ($this->matchesRule($rule, $transactionData)) {
                return $rule;
            }
        }
        
        return null;
    }

    /**
     * Check if transaction matches rule
     */
    private function matchesRule(ImportRule $rule, array $data): bool {
        $field = $rule->getField();
        $pattern = $rule->getPattern();
        $matchType = $rule->getMatchType();
        
        if (!isset($data[$field])) {
            return false;
        }
        
        $value = $data[$field];
        
        switch ($matchType) {
            case 'contains':
                return stripos($value, $pattern) !== false;
            
            case 'starts_with':
                return stripos($value, $pattern) === 0;
            
            case 'ends_with':
                return substr(strtolower($value), -strlen($pattern)) === strtolower($pattern);
            
            case 'equals':
                return strtolower($value) === strtolower($pattern);
            
            case 'regex':
                return preg_match('/' . $pattern . '/i', $value) === 1;
            
            default:
                return false;
        }
    }

    /**
     * Delete all import rules for a user
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }
}
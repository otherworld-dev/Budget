<?php

declare(strict_types=1);

namespace OCA\Budget\Api;

use OCP\AppFramework\Db\Entity;

/**
 * Response shapes for the public REST API (v1).
 *
 * The internal /api/... endpoints hand entities straight to DataResponse, so
 * their JSON follows the database and is free to change with any migration.
 * Public clients — the Android capture app, automation tooling — cannot be
 * updated in lockstep, so v1 maps every field explicitly here: a new column
 * never leaks into the contract, and a removed one breaks this file loudly
 * instead of a third-party integration silently.
 *
 * Inputs are accepted as either entities or the array rows the services
 * return, because the same record reaches the controllers both ways
 * (owned records as entities, shared ones as pre-serialized arrays).
 */
final class ApiSerializer {
    private function __construct() {
    }

    public static function account(Entity|array $account): array {
        $a = self::toArray($account);

        return [
            'id' => (int)($a['id'] ?? 0),
            'name' => (string)($a['name'] ?? ''),
            'type' => (string)($a['type'] ?? ''),
            'currency' => (string)($a['currency'] ?? ''),
            'balance' => (float)($a['balance'] ?? 0),
            // Only set when the account is not in the user's base currency.
            'balanceInBaseCurrency' => isset($a['convertedBalance']) ? (float)$a['convertedBalance'] : null,
            'baseCurrency' => $a['baseCurrency'] ?? null,
            'institution' => $a['institution'] ?? null,
            'shared' => (bool)($a['_shared'] ?? false),
            'updatedAt' => $a['updatedAt'] ?? null,
        ];
    }

    public static function category(Entity|array $category): array {
        $c = self::toArray($category);

        return [
            'id' => (int)($c['id'] ?? 0),
            'name' => (string)($c['name'] ?? ''),
            'type' => (string)($c['type'] ?? ''),
            'parentId' => isset($c['parentId']) ? (int)$c['parentId'] : null,
            'icon' => $c['icon'] ?? null,
            'color' => $c['color'] ?? null,
            'shared' => (bool)($c['_shared'] ?? false),
        ];
    }

    public static function transaction(Entity|array $transaction): array {
        $t = self::toArray($transaction);

        $out = [
            'id' => (int)($t['id'] ?? 0),
            'accountId' => (int)($t['accountId'] ?? 0),
            'categoryId' => isset($t['categoryId']) ? (int)$t['categoryId'] : null,
            'date' => $t['date'] ?? null,
            'description' => (string)($t['description'] ?? ''),
            'vendor' => $t['vendor'] ?? null,
            'amount' => (float)($t['amount'] ?? 0),
            'type' => (string)($t['type'] ?? ''),
            'reference' => $t['reference'] ?? null,
            'notes' => $t['notes'] ?? null,
            'status' => $t['status'] ?? 'cleared',
            'reconciled' => (bool)($t['reconciled'] ?? false),
            'isSplit' => (bool)($t['isSplit'] ?? false),
            'createdAt' => $t['createdAt'] ?? null,
            'updatedAt' => $t['updatedAt'] ?? null,
        ];

        // List queries join these in; single-record lookups do not. Present
        // only when known, so a client can render a list without a second
        // round-trip per row but never sees an invented value.
        foreach (['accountName', 'accountCurrency', 'categoryName'] as $joined) {
            if (isset($t[$joined])) {
                $out[$joined] = $t[$joined];
            }
        }

        return $out;
    }

    public static function attachment(Entity|array $attachment): array {
        $a = self::toArray($attachment);

        return [
            'id' => (int)($a['id'] ?? 0),
            'transactionId' => (int)($a['transactionId'] ?? 0),
            'fileId' => (int)($a['fileId'] ?? 0),
            'fileName' => $a['fileName'] ?? null,
            'mimeType' => $a['mimeType'] ?? null,
            'createdAt' => $a['createdAt'] ?? null,
            // listForTransaction() flags rows whose file the user has deleted.
            'missing' => (bool)($a['missing'] ?? false),
        ];
    }

    /** @param callable(Entity|array): array $mapper */
    public static function map(array $items, callable $mapper): array {
        return array_values(array_map($mapper, $items));
    }

    private static function toArray(Entity|array $value): array {
        if (is_array($value)) {
            return $value;
        }

        return $value instanceof \JsonSerializable ? (array)$value->jsonSerialize() : [];
    }
}

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
 *
 * Wire naming is snake_case throughout — fixed by the Android capture app's
 * contract (its handoff document is the authority for v1's shapes) and
 * matching the wider OCS ecosystem. The internal camelCase never leaks.
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
            'balance' => self::money($a['balance'] ?? 0),
            // Only set when the account is not in the user's base currency.
            'balance_in_base_currency' => isset($a['convertedBalance']) ? self::money($a['convertedBalance']) : null,
            'base_currency' => $a['baseCurrency'] ?? null,
            'institution' => $a['institution'] ?? null,
            'shared' => (bool)($a['_shared'] ?? false),
            'updated_at' => $a['updatedAt'] ?? null,
        ];
    }

    public static function category(Entity|array $category): array {
        $c = self::toArray($category);

        return [
            'id' => (int)($c['id'] ?? 0),
            'name' => (string)($c['name'] ?? ''),
            'type' => (string)($c['type'] ?? ''),
            'parent_id' => isset($c['parentId']) ? (int)$c['parentId'] : null,
            'icon' => $c['icon'] ?? null,
            'color' => $c['color'] ?? null,
            'shared' => (bool)($c['_shared'] ?? false),
        ];
    }

    public static function transaction(Entity|array $transaction): array {
        $t = self::toArray($transaction);

        $out = [
            'id' => (int)($t['id'] ?? 0),
            'account_id' => (int)($t['accountId'] ?? 0),
            'category_id' => isset($t['categoryId']) ? (int)$t['categoryId'] : null,
            'date' => $t['date'] ?? null,
            'description' => (string)($t['description'] ?? ''),
            'vendor' => $t['vendor'] ?? null,
            'amount' => self::money($t['amount'] ?? 0),
            'type' => (string)($t['type'] ?? ''),
            'reference' => $t['reference'] ?? null,
            'notes' => $t['notes'] ?? null,
            'status' => $t['status'] ?? 'cleared',
            'reconciled' => (bool)($t['reconciled'] ?? false),
            'is_split' => (bool)($t['isSplit'] ?? false),
            'created_at' => $t['createdAt'] ?? null,
            'updated_at' => $t['updatedAt'] ?? null,
        ];

        // List queries join these in; single-record lookups do not. Present
        // only when known, so a client can render a list without a second
        // round-trip per row but never sees an invented value.
        foreach (['accountName' => 'account_name', 'accountCurrency' => 'account_currency', 'categoryName' => 'category_name'] as $joined => $wire) {
            if (isset($t[$joined])) {
                $out[$wire] = $t[$joined];
            }
        }

        return $out;
    }

    public static function attachment(Entity|array $attachment): array {
        $a = self::toArray($attachment);

        return [
            'id' => (int)($a['id'] ?? 0),
            'transaction_id' => (int)($a['transactionId'] ?? 0),
            'file_id' => (int)($a['fileId'] ?? 0),
            'file_name' => $a['fileName'] ?? null,
            'mime_type' => $a['mimeType'] ?? null,
            'created_at' => $a['createdAt'] ?? null,
            // listForTransaction() flags rows whose file the user has deleted.
            'missing' => (bool)($a['missing'] ?? false),
        ];
    }

    /**
     * The draft a receipt extraction produces (#533). Not an entity — the
     * extraction service already normalises every field — but the shape is
     * pinned here (and in ApiSerializerTest) like every other v1 shape, so
     * the contract survives refactors of the service.
     */
    public static function receiptDraft(array $draft): array {
        return [
            'merchant' => $draft['merchant'] ?? null,
            'date' => $draft['date'] ?? null,
            'total' => $draft['total'] ?? null,
            // A capture app that lets the user categorise each item needs the
            // tax line: splits must sum exactly to the transaction, and most
            // receipts print tax separately, so items alone do not reconcile.
            // Additive to v1 — existing clients ignore unknown keys.
            'subtotal' => $draft['subtotal'] ?? null,
            'tax' => $draft['tax'] ?? null,
            // Money taken off, positive as printed. Without it a supermarket
            // receipt's items sum higher than the total and no client can make
            // them reconcile into splits.
            'discount' => $draft['discount'] ?? null,
            'currency' => $draft['currency'] ?? null,
            'suggested_category_id' => $draft['suggestedCategoryId'] ?? null,
            'suggested_category_name' => $draft['suggestedCategoryName'] ?? null,
            'line_items' => array_values(array_map(
                static fn (array $item) => [
                    'description' => (string)$item['description'],
                    'amount' => (string)$item['amount'],
                ],
                $draft['lineItems'] ?? []
            )),
            'warnings' => array_values($draft['warnings'] ?? []),
        ];
    }

    /**
     * One row of GET /transactions/recent — the capture app's glanceable
     * list, shaped exactly as its handoff specifies. merchant is the vendor
     * when one was recorded, else the description: the app shows a shop
     * name, not bank-statement prose.
     *
     * @param Entity|array $transaction A LIST-query row (the account joins
     *                                  must be present).
     */
    public static function recentTransaction(Entity|array $transaction): array {
        $t = self::toArray($transaction);

        $vendor = $t['vendor'] ?? null;

        return [
            'id' => (int)($t['id'] ?? 0),
            'merchant' => is_string($vendor) && $vendor !== '' ? $vendor : (string)($t['description'] ?? ''),
            'date' => $t['date'] ?? null,
            'amount' => self::money($t['amount'] ?? 0),
            'currency' => $t['accountCurrency'] ?? null,
            'account_name' => $t['accountName'] ?? null,
        ];
    }

    /** @param callable(Entity|array): array $mapper */
    public static function map(array $items, callable $mapper): array {
        return array_values(array_map($mapper, $items));
    }

    /**
     * Money as a fixed-point decimal string, never a JSON number.
     *
     * Every amount in this app is stored as DECIMAL(15,2) and calculated with
     * BCMath through MoneyCalculator, precisely so that a penny cannot go
     * missing. Handing a client a JSON number throws that away at the last
     * step: JSON numbers are IEEE doubles in most parsers, so 0.1 + 0.2 stops
     * being 0.30 the moment the client does arithmetic of its own.
     *
     * A string preserves what the database guarantees all the way to the
     * client, and drops straight into the exact decimal type every platform
     * has — BigDecimal on Android, Decimal in .NET, decimal.Decimal in Python.
     * Clients that only display the figure can print it unchanged.
     *
     * Always two decimal places, matching the column scale: '0.00', '-12.50'.
     */
    private static function money(float|int|string $value): string {
        return number_format((float)$value, 2, '.', '');
    }

    /**
     * One allocation of a split transaction.
     *
     * `amount` is a money string like every other figure in v1, so a client
     * can sum the parts without a float ever entering the arithmetic.
     */
    public static function split(Entity|array $split): array {
        $data = self::toArray($split);

        return [
            'id' => isset($data['id']) ? (int)$data['id'] : null,
            'transaction_id' => isset($data['transactionId']) ? (int)$data['transactionId'] : null,
            'amount' => self::money($data['amount'] ?? 0),
            'category_id' => isset($data['categoryId']) ? (int)$data['categoryId'] : null,
            'category_name' => $data['categoryName'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    /** @param iterable<Entity|array> $splits */
    public static function splits(iterable $splits): array {
        $out = [];
        foreach ($splits as $split) {
            $out[] = self::split($split);
        }
        return $out;
    }

    private static function toArray(Entity|array $value): array {
        if (is_array($value)) {
            return $value;
        }

        return $value instanceof \JsonSerializable ? (array)$value->jsonSerialize() : [];
    }
}

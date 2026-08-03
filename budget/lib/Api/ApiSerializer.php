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
            'balance' => self::money($a['balance'] ?? 0),
            // Only set when the account is not in the user's base currency.
            'balanceInBaseCurrency' => isset($a['convertedBalance']) ? self::money($a['convertedBalance']) : null,
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
            'amount' => self::money($t['amount'] ?? 0),
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
            'currency' => $draft['currency'] ?? null,
            'total' => $draft['total'] ?? null,
            'lineItems' => array_values(array_map(
                static fn (array $item) => [
                    'description' => (string)$item['description'],
                    'amount' => (string)$item['amount'],
                ],
                $draft['lineItems'] ?? []
            )),
            'suggestedCategoryId' => $draft['suggestedCategoryId'] ?? null,
            'suggestedCategoryName' => $draft['suggestedCategoryName'] ?? null,
            'warnings' => array_values($draft['warnings'] ?? []),
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

    private static function toArray(Entity|array $value): array {
        if (is_array($value)) {
            return $value;
        }

        return $value instanceof \JsonSerializable ? (array)$value->jsonSerialize() : [];
    }
}

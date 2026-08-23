<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\IL10N;

/**
 * Category tree import and export (#354).
 *
 * Export writes the user's own category tree as a hand-editable JSON
 * document. Import reads that document back — or a CSV of "Parent > Child"
 * paths — validates it, previews it against the tree the user already has,
 * and creates whatever is missing. Nothing is updated or deleted: a category
 * that already exists at the same level (same parent, type and name,
 * compared case-insensitively) is left exactly as it is and the file's
 * children are merged in underneath it, so a file can be imported twice
 * without doing any harm.
 */
class CategoryTransferService {
    public const EXPORT_TYPE = 'categories';
    public const EXPORT_VERSION = 1;
    /** Separator between the levels of a CSV path ("Food > Groceries"). */
    public const PATH_SEPARATOR = '>';
    public const MAX_DEPTH = 10;
    public const MAX_NODES = 2000;

    private const TYPES = ['income', 'expense'];
    private const PERIODS = ['monthly', 'weekly', 'quarterly', 'yearly'];

    /**
     * CSV header names (lower-cased, spaces and dashes folded) -> field.
     * Generous on purpose: the file is as likely to come out of a
     * spreadsheet or an AI assistant as out of this app.
     */
    private const CSV_COLUMNS = [
        'path' => 'path', 'category' => 'path', 'name' => 'path',
        'parent' => 'parent',
        'type' => 'type',
        'icon' => 'icon',
        'color' => 'color', 'colour' => 'color',
        'budget' => 'budgetAmount', 'budgetamount' => 'budgetAmount', 'budget_amount' => 'budgetAmount',
        'period' => 'budgetPeriod', 'budgetperiod' => 'budgetPeriod', 'budget_period' => 'budgetPeriod',
        'excludedfromreports' => 'excludedFromReports', 'excluded_from_reports' => 'excludedFromReports',
        'excludedfrombudget' => 'excludedFromBudget', 'excluded_from_budget' => 'excludedFromBudget',
        'budgetrollover' => 'budgetRollover', 'budget_rollover' => 'budgetRollover', 'rollover' => 'budgetRollover',
    ];

    private CategoryService $categoryService;
    private ValidationService $validationService;
    private IL10N $l;

    public function __construct(CategoryService $categoryService, ValidationService $validationService, IL10N $l) {
        $this->categoryService = $categoryService;
        $this->validationService = $validationService;
        $this->l = $l;
    }

    // ── Export ───────────────────────────────────────────────────────

    /**
     * The user's own category tree as a portable document.
     *
     * Ids, timestamps and sort orders are deliberately left out: the file is
     * meant to be read and edited by people, and imported into a different
     * account where none of those would mean anything. Order is the order
     * of the arrays. Fields that are unset or at their default are omitted.
     *
     * @return array{app: string, type: string, version: int, exportedAt: string, categories: array}
     */
    public function export(string $userId): array {
        return [
            'app' => 'budget',
            'type' => self::EXPORT_TYPE,
            'version' => self::EXPORT_VERSION,
            'exportedAt' => gmdate('c'),
            'categories' => array_map([$this, 'exportNode'], $this->categoryService->getCategoryTree($userId)),
        ];
    }

    private function exportNode(array $node): array {
        $out = ['name' => $node['name'], 'type' => $node['type']];
        if (!empty($node['icon'])) {
            $out['icon'] = $node['icon'];
        }
        if (!empty($node['color'])) {
            $out['color'] = $node['color'];
        }
        if (isset($node['budgetAmount']) && (float) $node['budgetAmount'] > 0) {
            $out['budgetAmount'] = (float) $node['budgetAmount'];
            if (!empty($node['budgetPeriod']) && $node['budgetPeriod'] !== 'monthly') {
                $out['budgetPeriod'] = $node['budgetPeriod'];
            }
        }
        foreach (['excludedFromReports', 'excludedFromBudget', 'budgetRollover'] as $flag) {
            if (!empty($node[$flag])) {
                $out[$flag] = true;
            }
        }
        $children = array_map([$this, 'exportNode'], $node['children'] ?? []);
        if ($children !== []) {
            $out['children'] = $children;
        }
        return $out;
    }

    // ── Parse ────────────────────────────────────────────────────────

    /**
     * Turn an uploaded file into a clean, validated tree.
     *
     * JSON: the export document, a bare list of categories, or a single
     * category object. CSV: one row per category with a "path" column
     * ("Food > Groceries"), or a "parent" column, plus optional attribute
     * columns; the delimiter (comma, semicolon, tab) is detected from the
     * header. Problems that can be worked around (no type, bad colour,
     * duplicate siblings) become warnings; anything that cannot throws.
     *
     * @param string|null $format 'json' or 'csv'; sniffed from the content when null
     * @return array{categories: array, warnings: string[]}
     * @throws \InvalidArgumentException
     */
    public function parse(string $content, ?string $format = null): array {
        $content = trim(ltrim($content, "\xEF\xBB\xBF"));
        if ($content === '') {
            throw new \InvalidArgumentException($this->l->t('The file is empty'));
        }
        $format = $format !== null && $format !== ''
            ? strtolower($format)
            : (($content[0] === '{' || $content[0] === '[') ? 'json' : 'csv');

        $warnings = [];
        $raw = $format === 'json' ? $this->parseJson($content) : $this->parseCsv($content, $warnings);

        $count = 0;
        $categories = $this->normalizeNodes($raw, null, 0, '', $count, $warnings);
        if ($categories === []) {
            throw new \InvalidArgumentException($this->l->t('No categories found in the file'));
        }
        return ['categories' => $categories, 'warnings' => $warnings];
    }

    private function parseJson(string $content): array {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException($this->l->t('The file is not valid JSON'));
        }
        if (array_key_exists('categories', $data)) {
            $data = $data['categories'];
            if (!is_array($data)) {
                throw new \InvalidArgumentException($this->l->t('No categories found in the file'));
            }
        }
        // A single category object rather than a list
        if (!array_is_list($data)) {
            $data = [$data];
        }
        return $data;
    }

    /**
     * @param string[] $warnings
     */
    private function parseCsv(string $content, array &$warnings): array {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        // Delimiter: whichever of , ; TAB splits the first line most
        $delimiter = ',';
        $best = substr_count($lines[0], ',');
        foreach ([';', "\t"] as $candidate) {
            $n = substr_count($lines[0], $candidate);
            if ($n > $best) {
                $best = $n;
                $delimiter = $candidate;
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter, '"', '\\');
        }
        if ($rows === []) {
            throw new \InvalidArgumentException($this->l->t('No categories found in the file'));
        }

        // Header row: any cell we recognise makes it one. Without one the
        // first column is the path and the second the type.
        $columns = [];
        foreach ($rows[0] as $i => $cell) {
            $key = str_replace([' ', '-'], ['', '_'], strtolower(trim((string) $cell)));
            if (isset(self::CSV_COLUMNS[$key]) && !isset($columns[self::CSV_COLUMNS[$key]])) {
                $columns[self::CSV_COLUMNS[$key]] = $i;
            }
        }
        if (isset($columns['path'])) {
            array_shift($rows);
        } else {
            $columns = ['path' => 0, 'type' => 1];
        }

        $roots = [];
        foreach ($rows as $row) {
            $path = trim((string) ($row[$columns['path']] ?? ''));
            if ($path === '') {
                continue;
            }
            if (isset($columns['parent'])) {
                $parent = trim((string) ($row[$columns['parent']] ?? ''));
                if ($parent !== '') {
                    $path = $parent . ' ' . self::PATH_SEPARATOR . ' ' . $path;
                }
            }
            $segments = array_values(array_filter(
                array_map('trim', explode(self::PATH_SEPARATOR, $path)),
                static fn(string $s) => $s !== ''
            ));
            if ($segments === []) {
                continue;
            }

            $attrs = [];
            foreach (['type', 'icon', 'color', 'budgetAmount', 'budgetPeriod', 'excludedFromReports', 'excludedFromBudget', 'budgetRollover'] as $field) {
                if (!isset($columns[$field])) {
                    continue;
                }
                $value = trim((string) ($row[$columns[$field]] ?? ''));
                if ($value !== '') {
                    $attrs[$field] = $value;
                }
            }
            $this->insertPath($roots, $segments, $attrs);
        }
        return $roots;
    }

    /**
     * Place one CSV row into the raw tree, creating the levels above it as
     * needed. A level that exists already (same name, any case) is reused,
     * so rows can come in any order and a parent listed after its children
     * still gets its own attributes.
     *
     * @param string[] $segments
     */
    private function insertPath(array &$roots, array $segments, array $attrs): void {
        $level = &$roots;
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $found = null;
            foreach ($level as $k => $node) {
                if (strcasecmp((string) $node['name'], $segment) === 0) {
                    $found = $k;
                    break;
                }
            }
            if ($found === null) {
                $node = ['name' => $segment, 'children' => []];
                // Implicit intermediate levels take the row's type so the
                // whole branch ends up on the right side of the tree.
                if (isset($attrs['type'])) {
                    $node['type'] = $attrs['type'];
                }
                $level[] = $node;
                $found = array_key_last($level);
            }
            if ($i === $last) {
                $level[$found] = $attrs + $level[$found];
            }
            $level = &$level[$found]['children'];
        }
    }

    /**
     * Validate raw nodes into the canonical shape, recursively.
     *
     * Siblings with the same name are merged (their children concatenated)
     * with a warning. Children always take their parent's type; a root
     * without a usable type is imported as an expense, with a warning.
     *
     * @param string[] $warnings
     */
    private function normalizeNodes(array $nodes, ?string $parentType, int $depth, string $parentPath, int &$count, array &$warnings): array {
        if ($depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException($this->l->t('Categories are nested deeper than %1$d levels', [self::MAX_DEPTH]));
        }

        // Pass 1: merge duplicate siblings on the raw input. Same name AND
        // same (effective) type: an "Other" expense root and an "Other"
        // income root are two different categories, as they are in the app.
        $merged = [];
        foreach ($nodes as $raw) {
            if (is_string($raw)) {
                $raw = ['name' => $raw];
            }
            if (!is_array($raw)) {
                continue;
            }
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '') {
                $warnings[] = $this->l->t('A category without a name was skipped');
                continue;
            }
            $rawType = strtolower(trim((string) ($raw['type'] ?? '')));
            $effectiveType = $parentType ?? (in_array($rawType, self::TYPES, true) ? $rawType : 'expense');
            $key = $effectiveType . '|' . mb_strtolower($name);
            if (isset($merged[$key])) {
                $warnings[] = $this->l->t('"%1$s" is listed more than once; the entries were merged', [$this->joinPath($parentPath, trim((string) $merged[$key]['name']))]);
                $merged[$key]['children'] = array_merge($this->rawChildren($merged[$key]), $this->rawChildren($raw));
                continue;
            }
            $merged[$key] = $raw;
        }

        // Pass 2: validate and recurse
        $out = [];
        foreach ($merged as $raw) {
            if (++$count > self::MAX_NODES) {
                throw new \InvalidArgumentException($this->l->t('The file holds more than %1$d categories', [self::MAX_NODES]));
            }
            $nameCheck = $this->validationService->validateName(trim((string) $raw['name']), true);
            if (!$nameCheck['valid']) {
                throw new \InvalidArgumentException($nameCheck['error']);
            }
            $name = $nameCheck['sanitized'];
            $path = $this->joinPath($parentPath, $name);

            $type = strtolower(trim((string) ($raw['type'] ?? '')));
            if ($parentType !== null) {
                if ($type !== '' && $type !== $parentType) {
                    $warnings[] = $this->l->t('"%1$s" must have the same type as its parent; imported as %2$s', [$path, $parentType]);
                }
                $type = $parentType;
            } elseif (!in_array($type, self::TYPES, true)) {
                $warnings[] = $type === ''
                    ? $this->l->t('"%1$s" has no type; imported as an expense category', [$path])
                    : $this->l->t('"%1$s" has the unknown type "%2$s"; imported as an expense category', [$path, $type]);
                $type = 'expense';
            }

            $icon = isset($raw['icon']) ? mb_substr(trim((string) $raw['icon']), 0, ValidationService::MAX_ICON_LENGTH) : '';

            $color = null;
            $rawColor = trim((string) ($raw['color'] ?? $raw['colour'] ?? ''));
            if ($rawColor !== '') {
                $colorCheck = $this->validationService->validateColor($rawColor);
                if ($colorCheck['valid']) {
                    $color = $colorCheck['sanitized'];
                } else {
                    $warnings[] = $this->l->t('"%1$s": the colour "%2$s" is not a hex code and was ignored', [$path, $rawColor]);
                }
            }

            $budgetAmount = null;
            $rawBudget = $raw['budgetAmount'] ?? $raw['budget'] ?? null;
            if ($rawBudget !== null && $rawBudget !== '') {
                if (is_numeric($rawBudget) && (float) $rawBudget >= 0) {
                    $budgetAmount = (float) $rawBudget;
                } else {
                    $warnings[] = $this->l->t('"%1$s": the budget "%2$s" is not a number and was ignored', [$path, (string) $rawBudget]);
                }
            }
            $period = strtolower(trim((string) ($raw['budgetPeriod'] ?? $raw['period'] ?? '')));

            $node = [
                'name' => $name,
                'type' => $type,
                'icon' => $icon === '' ? null : $icon,
                'color' => $color,
                'budgetAmount' => $budgetAmount,
                'budgetPeriod' => in_array($period, self::PERIODS, true) ? $period : null,
                'excludedFromReports' => $this->truthy($raw['excludedFromReports'] ?? false),
                'excludedFromBudget' => $this->truthy($raw['excludedFromBudget'] ?? false),
                'budgetRollover' => $this->truthy($raw['budgetRollover'] ?? false),
                'children' => $this->normalizeNodes($this->rawChildren($raw), $type, $depth + 1, $path, $count, $warnings),
            ];
            $out[] = $node;
        }
        return $out;
    }

    private function rawChildren(array $raw): array {
        $children = $raw['children'] ?? $raw['subcategories'] ?? null;
        return is_array($children) ? $children : [];
    }

    private function joinPath(string $parentPath, string $name): string {
        return $parentPath === '' ? $name : $parentPath . ' ' . self::PATH_SEPARATOR . ' ' . $name;
    }

    private function truthy(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    // ── Plan ─────────────────────────────────────────────────────────

    /**
     * Annotate a parsed tree with what importing it would do.
     *
     * Each node gets `action`: 'create', or 'exists' with `existingId` when a
     * category of that name and type already sits under the same parent.
     * Children of a category that does not exist yet are always created —
     * they are never matched against the root level just because their
     * parent has no id yet.
     *
     * @return array{categories: array, counts: array{create: int, exists: int, total: int}}
     */
    public function plan(string $userId, array $categories): array {
        $index = [];
        foreach ($this->categoryService->findAll($userId) as $category) {
            $index[$this->key($category->getParentId(), $category->getType(), $category->getName())] = [
                'id' => $category->getId(),
                'color' => $category->getColor(),
            ];
        }
        $counts = ['create' => 0, 'exists' => 0, 'total' => 0];
        $annotated = $this->planNodes($categories, null, true, $index, $counts);
        return ['categories' => $annotated, 'counts' => $counts];
    }

    private function planNodes(array $nodes, ?int $parentId, bool $parentExists, array $index, array &$counts): array {
        $out = [];
        foreach ($nodes as $node) {
            $counts['total']++;
            $existing = $parentExists ? ($index[$this->key($parentId, $node['type'], $node['name'])] ?? null) : null;
            $existingId = $existing['id'] ?? null;
            if ($existingId !== null) {
                $node['action'] = 'exists';
                $node['existingId'] = $existingId;
                $node['existingColor'] = $existing['color'] ?? null;
                $counts['exists']++;
            } else {
                $node['action'] = 'create';
                $node['existingId'] = null;
                $counts['create']++;
            }
            $node['children'] = $this->planNodes($node['children'], $existingId, $existingId !== null, $index, $counts);
            $out[] = $node;
        }
        return $out;
    }

    private function key(?int $parentId, string $type, string $name): string {
        return ($parentId ?? 0) . '|' . $type . '|' . mb_strtolower(trim($name));
    }

    // ── Import ───────────────────────────────────────────────────────

    /**
     * Create everything the plan marks 'create', parents before children.
     * A child without a colour of its own takes its parent's, as the
     * default tree does.
     *
     * @return array{created: int, skipped: int}
     */
    public function import(string $userId, array $categories): array {
        $plan = $this->plan($userId, $categories);
        $result = ['created' => 0, 'skipped' => 0];
        $this->importNodes($userId, $plan['categories'], null, null, $result);
        return $result;
    }

    private function importNodes(string $userId, array $nodes, ?int $parentId, ?string $parentColor, array &$result): void {
        foreach ($nodes as $i => $node) {
            if ($node['action'] === 'exists') {
                $id = $node['existingId'];
                $color = $node['existingColor'] ?? $parentColor;
                $result['skipped']++;
            } else {
                $created = $this->categoryService->create(
                    $userId,
                    $node['name'],
                    $node['type'],
                    $parentId,
                    $node['icon'],
                    $node['color'] ?? $parentColor,
                    $node['budgetAmount'],
                    $i,
                    $node['excludedFromReports'],
                    $node['excludedFromBudget']
                );
                $id = $created->getId();
                $color = $created->getColor();

                $updates = [];
                if ($node['budgetPeriod'] !== null && $node['budgetPeriod'] !== 'monthly') {
                    $updates['budgetPeriod'] = $node['budgetPeriod'];
                }
                if ($node['budgetRollover']) {
                    $updates['budgetRollover'] = true;
                }
                if ($updates !== []) {
                    $this->categoryService->update($id, $userId, $updates);
                }
                $result['created']++;
            }
            $this->importNodes($userId, $node['children'], $id, $color, $result);
        }
    }
}

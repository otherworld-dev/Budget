<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\CategoryTransferService;
use OCA\Budget\Service\ValidationService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class CategoryTransferServiceTest extends TestCase {
    private CategoryTransferService $service;
    private CategoryService $categoryService;

    protected function setUp(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            foreach ($params as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
                $text = str_replace('%' . ($i + 1) . '$d', (string) $param, $text);
            }
            return $text;
        });
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->service = new CategoryTransferService($this->categoryService, new ValidationService($l), $l);
    }

    private function makeCategory(array $overrides = []): Category {
        $data = array_merge([
            'id' => 1, 'userId' => 'user1', 'name' => 'Food', 'type' => 'expense', 'parentId' => null,
            'icon' => null, 'color' => '#ff0000', 'budgetAmount' => null,
        ], $overrides);
        $category = new Category();
        $category->setId($data['id']);
        $category->setUserId($data['userId']);
        $category->setName($data['name']);
        $category->setType($data['type']);
        $category->setParentId($data['parentId']);
        $category->setIcon($data['icon']);
        $category->setColor($data['color']);
        $category->setBudgetAmount($data['budgetAmount']);
        return $category;
    }

    /** Flatten a normalized/planned tree to "path|type" strings for easy asserts. */
    private function paths(array $nodes, string $prefix = ''): array {
        $out = [];
        foreach ($nodes as $node) {
            $path = $prefix === '' ? $node['name'] : $prefix . ' > ' . $node['name'];
            $out[] = $path . '|' . $node['type'] . (isset($node['action']) ? '|' . $node['action'] : '');
            $out = array_merge($out, $this->paths($node['children'], $path));
        }
        return $out;
    }

    // ── export ───────────────────────────────────────────────────────

    public function testExportStripsIdsAndDefaultsAndKeepsNesting(): void {
        $this->categoryService->method('getCategoryTree')->with('user1')->willReturn([
            [
                'id' => 1, 'userId' => 'user1', 'name' => 'Food', 'type' => 'expense', 'parentId' => null,
                'icon' => 'icon-food', 'color' => '#ff0000', 'budgetAmount' => 400.0, 'budgetPeriod' => 'monthly',
                'sortOrder' => 0, 'excludedFromReports' => false, 'excludedFromBudget' => false, 'budgetRollover' => true,
                'createdAt' => 'x', 'updatedAt' => 'y',
                'children' => [
                    ['id' => 2, 'name' => 'Groceries', 'type' => 'expense', 'parentId' => 1, 'icon' => null, 'color' => null,
                        'budgetAmount' => null, 'budgetPeriod' => 'monthly', 'excludedFromReports' => true, 'children' => []],
                    ['id' => 3, 'name' => 'Insurance', 'type' => 'expense', 'parentId' => 1, 'icon' => '', 'color' => '#00ff00',
                        'budgetAmount' => 120.0, 'budgetPeriod' => 'yearly', 'children' => []],
                ],
            ],
            ['id' => 4, 'name' => 'Income', 'type' => 'income', 'parentId' => null, 'color' => '#0000ff', 'budgetAmount' => 0, 'children' => []],
        ]);

        $export = $this->service->export('user1');

        $this->assertSame('budget', $export['app']);
        $this->assertSame('categories', $export['type']);
        $this->assertSame(1, $export['version']);
        $this->assertSame([
            [
                'name' => 'Food', 'type' => 'expense', 'icon' => 'icon-food', 'color' => '#ff0000', 'budgetAmount' => 400.0,
                'budgetRollover' => true,
                'children' => [
                    ['name' => 'Groceries', 'type' => 'expense', 'excludedFromReports' => true],
                    ['name' => 'Insurance', 'type' => 'expense', 'color' => '#00ff00', 'budgetAmount' => 120.0, 'budgetPeriod' => 'yearly'],
                ],
            ],
            ['name' => 'Income', 'type' => 'income', 'color' => '#0000ff'],
        ], $export['categories']);
    }

    // ── parse: JSON ──────────────────────────────────────────────────

    public function testParseJsonExportDocument(): void {
        $json = json_encode(['app' => 'budget', 'type' => 'categories', 'version' => 1, 'categories' => [
            ['name' => ' Food ', 'type' => 'Expense', 'color' => '#ABC', 'budget' => '400', 'children' => [
                ['name' => 'Groceries', 'icon' => 'icon-cart'],
                'Eating Out',
                ['name' => 'Insurance', 'budgetAmount' => 120, 'period' => 'Yearly', 'excludedFromBudget' => 'yes'],
            ]],
            ['name' => 'Salary', 'type' => 'income'],
        ]]);

        $parsed = $this->service->parse($json);

        $this->assertSame([], $parsed['warnings']);
        $this->assertSame(
            ['Food|expense', 'Food > Groceries|expense', 'Food > Eating Out|expense', 'Food > Insurance|expense', 'Salary|income'],
            $this->paths($parsed['categories'])
        );
        $food = $parsed['categories'][0];
        $this->assertSame('#ABC', $food['color']);
        $this->assertSame(400.0, $food['budgetAmount']);
        $this->assertSame('icon-cart', $food['children'][0]['icon']);
        $insurance = $food['children'][2];
        $this->assertSame(120.0, $insurance['budgetAmount']);
        $this->assertSame('yearly', $insurance['budgetPeriod']);
        $this->assertTrue($insurance['excludedFromBudget']);
        $this->assertFalse($insurance['excludedFromReports']);
    }

    public function testParseJsonBareListAndSingleObject(): void {
        $this->assertSame(['A|expense', 'B|income'], $this->paths($this->service->parse('[{"name":"A","type":"expense"},{"name":"B","type":"income"}]')['categories']));
        $this->assertSame(['A|income', 'A > B|income'], $this->paths($this->service->parse('{"name":"A","type":"income","subcategories":["B"]}')['categories']));
    }

    public function testParseWarnsAndDefaultsOnMissingOrMismatchedTypes(): void {
        $parsed = $this->service->parse('[{"name":"Mystery","children":[{"name":"Child","type":"income"}]},{"name":"Odd","type":"thing"}]');

        $this->assertSame(['Mystery|expense', 'Mystery > Child|expense', 'Odd|expense'], $this->paths($parsed['categories']));
        $this->assertSame([
            '"Mystery" has no type; imported as an expense category',
            '"Mystery > Child" must have the same type as its parent; imported as expense',
            '"Odd" has the unknown type "thing"; imported as an expense category',
        ], $parsed['warnings']);
    }

    public function testParseMergesDuplicateSiblingsAndWarns(): void {
        $parsed = $this->service->parse('[{"name":"Food","type":"expense","children":["A"]},{"name":"food","children":["B","a"]}]');

        $this->assertSame(['Food|expense', 'Food > A|expense', 'Food > B|expense'], $this->paths($parsed['categories']));
        $this->assertContains('"Food" is listed more than once; the entries were merged', $parsed['warnings']);
        $this->assertContains('"Food > A" is listed more than once; the entries were merged', $parsed['warnings']);
    }

    public function testParseIgnoresBadColourAndBudgetWithWarnings(): void {
        $parsed = $this->service->parse('[{"name":"Food","type":"expense","color":"red","budget":"lots"}]');

        $this->assertNull($parsed['categories'][0]['color']);
        $this->assertNull($parsed['categories'][0]['budgetAmount']);
        $this->assertSame([
            '"Food": the colour "red" is not a hex code and was ignored',
            '"Food": the budget "lots" is not a number and was ignored',
        ], $parsed['warnings']);
    }

    public function testParseRejectsEmptyInvalidAndNamelessInput(): void {
        foreach (['', "  \n ", '{not json', '{"categories": "x"}', '[]', '[{"type":"expense"}]', 'path,type'] as $bad) {
            try {
                $this->service->parse($bad);
                $this->fail('Expected an exception for: ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testParseRejectsTooDeepNesting(): void {
        $node = ['name' => 'L10'];
        for ($i = 9; $i >= 0; $i--) {
            $node = ['name' => 'L' . $i, 'type' => 'expense', 'children' => [$node]];
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nested deeper than 10 levels');
        $this->service->parse(json_encode([$node]));
    }

    // ── parse: CSV ───────────────────────────────────────────────────

    public function testParseCsvWithHeaderPathsAndAttributes(): void {
        $csv = "Path;Type;Budget;Colour;excluded from reports\n"
            . "Food;expense;400;#f97316;\n"
            . "Food > Groceries;;;;\n"
            . "Food > Eating Out;;120;;yes\n"
            . "Income > Salary;income;;;\n"
            . "\"Bills > Rent, Mortgage\";expense;;;\n";

        $parsed = $this->service->parse($csv);

        $this->assertSame(
            ['Food|expense', 'Food > Groceries|expense', 'Food > Eating Out|expense', 'Income|income', 'Income > Salary|income', 'Bills|expense', 'Bills > Rent, Mortgage|expense'],
            $this->paths($parsed['categories'])
        );
        $this->assertSame([], $parsed['warnings'], 'an implicit parent takes the row\'s type, so nothing should warn');
        $food = $parsed['categories'][0];
        $this->assertSame(400.0, $food['budgetAmount']);
        $this->assertSame('#f97316', $food['color']);
        $this->assertSame(120.0, $food['children'][1]['budgetAmount']);
        $this->assertTrue($food['children'][1]['excludedFromReports']);
    }

    public function testParseCsvParentColumnAndLateParentRow(): void {
        $csv = "name,parent,type,icon\n"
            . "Groceries,Food,expense,\n"
            . "Food,,expense,icon-food\n"
            . "Snacks,Food > Groceries,,\n";

        $parsed = $this->service->parse($csv);

        $this->assertSame(['Food|expense', 'Food > Groceries|expense', 'Food > Groceries > Snacks|expense'], $this->paths($parsed['categories']));
        $this->assertSame('icon-food', $parsed['categories'][0]['icon'], 'a parent listed after its child still gets its own attributes');
    }

    public function testParseCsvWithoutHeaderUsesFirstTwoColumns(): void {
        $parsed = $this->service->parse("Housing,expense\nHousing > Rent\nSalary,income\n");

        $this->assertSame(['Housing|expense', 'Housing > Rent|expense', 'Salary|income'], $this->paths($parsed['categories']));
    }

    public function testParseFormatOverrideAndBom(): void {
        $parsed = $this->service->parse("\xEF\xBB\xBFpath,type\nFood,expense\n", 'csv');
        $this->assertSame(['Food|expense'], $this->paths($parsed['categories']));
    }

    // ── plan ─────────────────────────────────────────────────────────

    public function testPlanMatchesExistingCaseInsensitivelyAndNeverMatchesRootsForNewParents(): void {
        $this->categoryService->method('findAll')->with('user1')->willReturn([
            $this->makeCategory(['id' => 1, 'name' => 'Food', 'type' => 'expense']),
            $this->makeCategory(['id' => 2, 'name' => 'Groceries', 'type' => 'expense', 'parentId' => 1]),
            $this->makeCategory(['id' => 3, 'name' => 'Food', 'type' => 'income']),
        ]);
        $parsed = $this->service->parse(json_encode([
            ['name' => 'FOOD', 'type' => 'expense', 'children' => ['groceries', 'Eating Out']],
            ['name' => 'Transport', 'type' => 'expense', 'children' => ['Food']],
            ['name' => 'Food', 'type' => 'income'],
        ]));

        $plan = $this->service->plan('user1', $parsed['categories']);

        $this->assertSame([
            'FOOD|expense|exists',
            'FOOD > groceries|expense|exists',
            'FOOD > Eating Out|expense|create',
            'Transport|expense|create',
            'Transport > Food|expense|create',
            'Food|income|exists',
        ], $this->paths($plan['categories']));
        $this->assertSame(['create' => 3, 'exists' => 3, 'total' => 6], $plan['counts']);
        $this->assertSame(1, $plan['categories'][0]['existingId']);
        $this->assertSame(2, $plan['categories'][0]['children'][0]['existingId']);
        $this->assertNull($plan['categories'][1]['children'][0]['existingId'], 'a child of a new parent must not match the root "Food"');
    }

    // ── import ───────────────────────────────────────────────────────

    public function testImportCreatesParentsFirstSkipsExistingAndInheritsColour(): void {
        $this->categoryService->method('findAll')->willReturn([
            $this->makeCategory(['id' => 1, 'name' => 'Food', 'type' => 'expense', 'color' => '#111111']),
        ]);

        $created = [];
        $nextId = 100;
        $this->categoryService->method('create')->willReturnCallback(
            function (string $userId, string $name, string $type, ?int $parentId, ?string $icon, ?string $color, ?float $budget, int $sortOrder, bool $exclReports, bool $exclBudget) use (&$created, &$nextId) {
                $created[] = compact('name', 'type', 'parentId', 'icon', 'color', 'budget', 'sortOrder', 'exclReports', 'exclBudget');
                return $this->makeCategory(['id' => $nextId++, 'name' => $name, 'type' => $type, 'parentId' => $parentId, 'color' => $color ?? '#random']);
            }
        );
        $updates = [];
        $this->categoryService->method('update')->willReturnCallback(function (int $id, string $userId, array $data) use (&$updates) {
            $updates[$id] = $data;
            return $this->makeCategory(['id' => $id]);
        });

        $parsed = $this->service->parse(json_encode([
            ['name' => 'Food', 'type' => 'expense', 'children' => [
                ['name' => 'Groceries', 'budgetAmount' => 200, 'excludedFromReports' => true],
            ]],
            ['name' => 'Housing', 'type' => 'expense', 'color' => '#3b82f6', 'children' => [
                ['name' => 'Insurance', 'budgetAmount' => 300, 'budgetPeriod' => 'yearly', 'budgetRollover' => true, 'children' => ['Car']],
            ]],
        ]));

        $result = $this->service->import('user1', $parsed['categories']);

        $this->assertSame(['created' => 4, 'skipped' => 1], $result);
        $this->assertSame('Groceries', $created[0]['name']);
        $this->assertSame(1, $created[0]['parentId'], 'child of the existing Food goes under its real id');
        $this->assertSame('#111111', $created[0]['color'], 'child without a colour inherits the existing parent\'s');
        $this->assertSame(200.0, $created[0]['budget']);
        $this->assertTrue($created[0]['exclReports']);

        $this->assertSame(['Housing', null, '#3b82f6', 1], [$created[1]['name'], $created[1]['parentId'], $created[1]['color'], $created[1]['sortOrder']]);
        $this->assertSame(['Insurance', 100 + 1, '#3b82f6'], [$created[2]['name'], $created[2]['parentId'], $created[2]['color']], 'created parent id is passed to its children, colour inherited');
        $this->assertSame(['Car', 102], [$created[3]['name'], $created[3]['parentId']]);

        $this->assertSame([102 => ['budgetPeriod' => 'yearly', 'budgetRollover' => true]], $updates, 'period/rollover are applied to Insurance (id 102) through update() after creation');
    }

    public function testImportIsIdempotent(): void {
        $this->categoryService->method('findAll')->willReturn([
            $this->makeCategory(['id' => 1, 'name' => 'Food', 'type' => 'expense']),
            $this->makeCategory(['id' => 2, 'name' => 'Groceries', 'type' => 'expense', 'parentId' => 1]),
        ]);
        $this->categoryService->expects($this->never())->method('create');

        $result = $this->service->import('user1', $this->service->parse('[{"name":"Food","type":"expense","children":["Groceries"]}]')['categories']);

        $this->assertSame(['created' => 0, 'skipped' => 2], $result);
    }
}

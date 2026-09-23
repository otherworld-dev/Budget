/**
 * Rule criteria builder (src/modules/rules/components/CriteriaBuilder.js).
 *
 * The visual editor for import-rule conditions: a tree of AND/OR groups with
 * leaf conditions (field, match type, pattern, NOT). Tests drive it through
 * the DOM it renders, the way a user would, and read the result back through
 * getCriteria() / validate(), which is what RulesModule saves.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    getCanonicalLocale: () => 'en-GB',
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    alertDialog: vi.fn(() => Promise.resolve()),
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
}));

import { CriteriaBuilder } from '../../src/modules/rules/components/CriteriaBuilder.js';
import { alertDialog } from '../../src/utils/dialogs.js';

const ACCOUNTS = [
    { id: 3, name: 'Current', type: 'checking' },
    { id: 7, name: 'Visa <Gold>', type: 'credit_card' },
    { id: 9, name: 'Joint', type: 'checking' },
];

function cond(overrides = {}) {
    return { type: 'condition', field: 'description', matchType: 'contains', pattern: 'amazon', negate: false, ...overrides };
}

function tree(conditions, operator = 'AND') {
    return { version: 2, root: { operator, conditions } };
}

let container;

function build(criteria = null, options = {}) {
    return new CriteriaBuilder(container, criteria, options);
}

/** Fire an event the way the browser would after the user changes a control. */
function change(el, value, type = 'change') {
    if (el.type === 'checkbox') {
        el.checked = value;
    } else {
        el.value = value;
    }
    el.dispatchEvent(new Event(type, { bubbles: true }));
}

const q = (sel) => container.querySelector(sel);
const qa = (sel) => Array.from(container.querySelectorAll(sel));
const optionValues = (select) => Array.from(select.options).map(o => o.value);

beforeEach(() => {
    document.body.innerHTML = '<div id="criteria"></div>';
    container = document.getElementById('criteria');
    vi.clearAllMocks();
});

describe('initial criteria', () => {
    it('starts with one empty "description contains" condition when given nothing', () => {
        const builder = build(null);

        expect(builder.getCriteria()).toEqual(tree([cond({ pattern: '' })]));
    });

    it.each([
        ['a non-object', 'nonsense'],
        ['an object with no root', {}],
        ['a null root', { root: null }],
        ['a root that is neither group nor condition', { root: { foo: 1 } }],
        ['a non-object root', { root: 'x' }],
    ])('falls back to an empty builder for %s (#318)', (_label, criteria) => {
        const builder = build(criteria);

        expect(builder.getCriteria()).toEqual(tree([cond({ pattern: '' })]));
        expect(qa('.criteria-condition')).toHaveLength(1);
    });

    it('wraps a legacy single-condition root in an AND group', () => {
        const legacy = { version: 2, root: cond({ pattern: 'tesco' }) };

        const builder = build(legacy);

        expect(builder.getCriteria()).toEqual(tree([cond({ pattern: 'tesco' })]));
    });

    it('keeps a well-formed tree as it is', () => {
        const criteria = tree([cond(), cond({ field: 'vendor', pattern: 'shell' })], 'OR');

        const builder = build(criteria);

        expect(builder.getCriteria()).toBe(criteria);
    });
});

describe('rendering', () => {
    it('renders one row per condition, and nested groups inside their parent', () => {
        build(tree([
            cond(),
            { operator: 'OR', conditions: [cond({ pattern: 'a' }), cond({ pattern: 'b' })] },
        ]));

        expect(qa('.criteria-group')).toHaveLength(2);
        expect(qa('.criteria-condition')).toHaveLength(3);
        expect(qa('.criteria-group .criteria-group .criteria-condition')).toHaveLength(2);
    });

    it('shows the group operator that is set', () => {
        build(tree([cond()], 'OR'));

        expect(q('.group-operator').value).toBe('OR');
    });

    it('offers no remove button on the root group, but does on nested groups', () => {
        build(tree([cond(), { operator: 'AND', conditions: [cond()] }]));

        const removeButtons = qa('.btn-remove-group');
        expect(removeButtons).toHaveLength(1);
        expect(removeButtons[0].dataset.path).toBe('conditions.1');
    });

    it('addresses each control by its path in the tree', () => {
        build(tree([cond(), { operator: 'AND', conditions: [cond(), cond()] }]));

        expect(qa('.condition-pattern').map(el => el.dataset.path))
            .toEqual(['conditions.0', 'conditions.1.conditions.0', 'conditions.1.conditions.1']);
    });

    it('reflects the NOT flag', () => {
        build(tree([cond({ negate: true }), cond({ negate: false })]));

        expect(qa('.condition-negate').map(cb => cb.checked)).toEqual([true, false]);
    });

    it('escapes HTML in a text pattern', () => {
        build(tree([cond({ pattern: '<img src=x onerror=alert(1)>' })]));

        expect(q('img')).toBeNull();
        expect(q('input.condition-pattern').value).toBe('<img src=x onerror=alert(1)>');
    });

    // escapeHtml() goes through textContent/innerHTML, which escapes < > &
    // but not quotes. The pattern lands inside value="...", so a double quote
    // ends the attribute: the value is cut short, and the rest of the pattern
    // is parsed as markup. A rule shared with another user carries it along.
    it.fails('keeps a double quote in a text pattern intact (known bug: quotes not escaped in value="")', () => {
        build(tree([cond({ pattern: 'say "hi" now' })]));

        expect(q('input.condition-pattern').value).toBe('say "hi" now');
    });

    describe('match types per field', () => {
        const matchTypes = (field) => {
            build(tree([cond({ field })]), { accounts: ACCOUNTS });
            return optionValues(q('.condition-match-type'));
        };

        it.each(['description', 'vendor', 'reference', 'notes', 'source'])('text field %s gets string matches', (field) => {
            expect(matchTypes(field)).toEqual(['contains', 'starts_with', 'ends_with', 'equals', 'regex']);
        });

        it('amount gets numeric comparisons', () => {
            expect(matchTypes('amount')).toEqual(['equals', 'greater_than', 'less_than', 'between']);
        });

        it('date gets date comparisons', () => {
            expect(matchTypes('date')).toEqual(['equals', 'before', 'after', 'between']);
        });

        it.each(['type', 'account', 'account_type'])('%s only matches exactly ("is")', (field) => {
            expect(matchTypes(field)).toEqual(['equals']);
            expect(q('.condition-match-type').options[0].textContent).toBe('is');
        });

        it('selects the condition\'s current match type', () => {
            build(tree([cond({ matchType: 'ends_with' })]));

            expect(q('.condition-match-type').value).toBe('ends_with');
        });
    });

    describe('pattern widget', () => {
        it('uses a free-text input for text fields', () => {
            build(tree([cond({ pattern: 'tesco' })]));

            const input = q('.condition-pattern');
            expect(input.tagName).toBe('INPUT');
            expect(input.value).toBe('tesco');
        });

        it('uses an Expense/Income picker for Transaction Type', () => {
            build(tree([cond({ field: 'type', matchType: 'equals', pattern: 'credit' })]));

            const select = q('select.condition-pattern');
            expect(optionValues(select)).toEqual(['debit', 'credit']);
            expect(select.value).toBe('credit');
        });

        it('lists the user\'s accounts for Account, selecting the stored id', () => {
            build(tree([cond({ field: 'account', matchType: 'equals', pattern: '7' })]), { accounts: ACCOUNTS });

            const select = q('select.condition-pattern');
            expect(optionValues(select)).toEqual(['3', '7', '9']);
            expect(select.value).toBe('7');
            expect(select.options[1].textContent).toBe('Visa <Gold>');
        });

        it('matches a numeric stored account id against the options', () => {
            build(tree([cond({ field: 'account', matchType: 'equals', pattern: 9 })]), { accounts: ACCOUNTS });

            expect(q('select.condition-pattern').value).toBe('9');
        });

        it('says there are no accounts instead of showing an empty picker', () => {
            build(tree([cond({ field: 'account', matchType: 'equals', pattern: '' })]));

            expect(q('select.condition-pattern')).toBeNull();
            expect(q('.condition-pattern-empty').textContent).toBe('No accounts available');
        });

        it('offers each account type the user actually has, once, with a readable label', () => {
            build(tree([cond({ field: 'account_type', matchType: 'equals', pattern: 'credit_card' })]), { accounts: ACCOUNTS });

            const select = q('select.condition-pattern');
            expect(optionValues(select)).toEqual(['checking', 'credit_card']);
            expect(select.value).toBe('credit_card');
            expect(select.options[1].textContent).toBe('Credit Card');
        });

        it('shows the empty message for Account Type with no accounts', () => {
            build(tree([cond({ field: 'account_type', matchType: 'equals', pattern: '' })]));

            expect(q('.condition-pattern-empty')).not.toBeNull();
        });
    });

    describe('placeholders', () => {
        it.each([
            ['amount', 'greater_than', 'e.g., 50.00'],
            ['date', 'before', 'e.g., 2024-01-15'],
            ['description', 'regex', 'e.g., ^ORDER-\\d+'],
            ['source', 'contains', 'e.g., OFX Import'],
            ['vendor', 'contains', 'e.g., amazon'],
        ])('%s / %s hints "%s"', (field, matchType, expected) => {
            build(tree([cond({ field, matchType, pattern: '' })]));

            expect(q('input.condition-pattern').placeholder).toBe(expected);
        });

        // The placeholder is written into placeholder="..." unescaped, so the
        // quotes in the JSON example end the attribute and the user sees only
        // "e.g., {" - the one hint that explains the format.
        it.fails.each([
            ['amount', 'e.g., {"min": 10, "max": 100}'],
            ['date', 'e.g., {"min": "2024-01-01", "max": "2024-12-31"}'],
        ])('%s / between shows the whole JSON example (known bug: placeholder cut at the first quote)', (field, expected) => {
            build(tree([cond({ field, matchType: 'between', pattern: '' })]));

            expect(q('input.condition-pattern').placeholder).toBe(expected);
        });
    });
});

describe('editing the tree', () => {
    it('adds a blank condition to the root group', () => {
        const builder = build(tree([cond()]));

        q('.btn-add-condition').click();

        expect(builder.getCriteria().root.conditions).toEqual([cond(), cond({ pattern: '' })]);
        expect(qa('.criteria-condition')).toHaveLength(2);
    });

    it('adds a nested AND group holding one blank condition', () => {
        const builder = build(tree([cond()]));

        q('.btn-add-group').click();

        expect(builder.getCriteria().root.conditions[1]).toEqual({ operator: 'AND', conditions: [cond({ pattern: '' })] });
        expect(qa('.criteria-group')).toHaveLength(2);
    });

    it('adds a condition inside the nested group whose button was clicked', () => {
        const builder = build(tree([cond(), { operator: 'OR', conditions: [cond({ pattern: 'x' })] }]));

        q('.btn-add-condition[data-path="conditions.1"]').click();

        const root = builder.getCriteria().root;
        expect(root.conditions).toHaveLength(2);
        expect(root.conditions[1].conditions).toHaveLength(2);
    });

    it('removes a condition when its group has others', () => {
        const builder = build(tree([cond({ pattern: 'a' }), cond({ pattern: 'b' })]));

        q('.btn-remove-condition[data-path="conditions.0"]').click();

        expect(builder.getCriteria().root.conditions).toEqual([cond({ pattern: 'b' })]);
        expect(alertDialog).not.toHaveBeenCalled();
    });

    it('refuses to remove the last condition of a group and says why', () => {
        const builder = build(tree([cond()]));

        q('.btn-remove-condition').click();

        expect(builder.getCriteria().root.conditions).toHaveLength(1);
        expect(alertDialog).toHaveBeenCalledWith(expect.stringContaining('Cannot remove the last condition'));
    });

    it('refuses to remove the last condition of a nested group too', () => {
        const builder = build(tree([cond(), { operator: 'AND', conditions: [cond()] }]));

        q('.btn-remove-condition[data-path="conditions.1.conditions.0"]').click();

        expect(builder.getCriteria().root.conditions[1].conditions).toHaveLength(1);
        expect(alertDialog).toHaveBeenCalledOnce();
    });

    it('removes a nested group with everything in it', () => {
        const builder = build(tree([cond(), { operator: 'AND', conditions: [cond(), cond()] }]));

        q('.btn-remove-group').click();

        expect(builder.getCriteria().root.conditions).toEqual([cond()]);
        expect(qa('.criteria-group')).toHaveLength(1);
    });

    it('ignores a request to remove the root', () => {
        const builder = build(tree([cond()]));

        builder.removeGroup('');
        builder.removeCondition('');

        expect(builder.getCriteria().root.conditions).toHaveLength(1);
    });

    it('switches a group between AND and OR', () => {
        const builder = build(tree([cond()]));

        change(q('.group-operator'), 'OR');

        expect(builder.getCriteria().root.operator).toBe('OR');
    });

    it('records the NOT flag', () => {
        const builder = build(tree([cond()]));

        change(q('.condition-negate'), true);

        expect(builder.getCriteria().root.conditions[0].negate).toBe(true);
    });

    it('records typed pattern text as the user types', () => {
        const builder = build(tree([cond({ pattern: '' })]));

        change(q('.condition-pattern'), 'netflix', 'input');

        expect(builder.getCriteria().root.conditions[0].pattern).toBe('netflix');
    });

    it('records a picked account from the dropdown', () => {
        const builder = build(tree([cond({ field: 'account', matchType: 'equals', pattern: '3' })]), { accounts: ACCOUNTS });

        change(q('select.condition-pattern'), '9');

        expect(builder.getCriteria().root.conditions[0].pattern).toBe('9');
    });

    it('records a changed match type', () => {
        const builder = build(tree([cond()]));

        change(q('.condition-match-type'), 'regex');

        expect(builder.getCriteria().root.conditions[0].matchType).toBe('regex');
    });

    it('edits only the condition whose control changed', () => {
        const builder = build(tree([cond({ pattern: 'a' }), { operator: 'OR', conditions: [cond({ pattern: 'b' })] }]));

        change(q('.condition-pattern[data-path="conditions.1.conditions.0"]'), 'changed', 'input');

        const root = builder.getCriteria().root;
        expect(root.conditions[0].pattern).toBe('a');
        expect(root.conditions[1].conditions[0].pattern).toBe('changed');
    });

    describe('changing the field', () => {
        it('to Amount resets the match type to equals and shows numeric options', () => {
            const builder = build(tree([cond({ matchType: 'regex' })]));

            change(q('.condition-field'), 'amount');

            expect(builder.getCriteria().root.conditions[0]).toMatchObject({ field: 'amount', matchType: 'equals' });
            expect(optionValues(q('.condition-match-type'))).toContain('between');
        });

        it('to Date resets the match type to equals', () => {
            const builder = build(tree([cond({ matchType: 'starts_with' })]));

            change(q('.condition-field'), 'date');

            expect(builder.getCriteria().root.conditions[0]).toMatchObject({ field: 'date', matchType: 'equals' });
        });

        it('to Transaction Type defaults the pattern to Expense', () => {
            const builder = build(tree([cond()]));

            change(q('.condition-field'), 'type');

            expect(builder.getCriteria().root.conditions[0]).toMatchObject({ field: 'type', matchType: 'equals', pattern: 'debit' });
            expect(q('select.condition-pattern').value).toBe('debit');
        });

        it('to Account defaults to the first account, as a string id', () => {
            const builder = build(tree([cond()]), { accounts: ACCOUNTS });

            change(q('.condition-field'), 'account');

            expect(builder.getCriteria().root.conditions[0]).toMatchObject({ field: 'account', matchType: 'equals', pattern: '3' });
        });

        it('to Account with no accounts leaves the pattern empty', () => {
            const builder = build(tree([cond()]));

            change(q('.condition-field'), 'account');

            expect(builder.getCriteria().root.conditions[0].pattern).toBe('');
        });

        it('to Account Type defaults to the first type the user has', () => {
            const builder = build(tree([cond()]), { accounts: ACCOUNTS });

            change(q('.condition-field'), 'account_type');

            expect(builder.getCriteria().root.conditions[0]).toMatchObject({ field: 'account_type', pattern: 'checking' });
        });

        it('back to a text field resets the match type to contains', () => {
            const builder = build(tree([cond({ field: 'amount', matchType: 'greater_than', pattern: '5' })]));

            change(q('.condition-field'), 'vendor');

            expect(builder.getCriteria().root.conditions[0]).toMatchObject({ field: 'vendor', matchType: 'contains' });
        });
    });
});

describe('getAccountTypeOptions', () => {
    it('lists distinct account types in first-seen order, skipping blanks', () => {
        const builder = build(null, { accounts: [...ACCOUNTS, { id: 11, name: 'Odd', type: '' }, { id: 12, name: 'Loan', type: 'loan' }] });

        expect(builder.getAccountTypeOptions()).toEqual(['checking', 'credit_card', 'loan']);
    });

    it('is empty with no accounts', () => {
        expect(build().getAccountTypeOptions()).toEqual([]);
    });
});

describe('validate', () => {
    it('accepts a complete tree', () => {
        const builder = build(tree([cond(), { operator: 'OR', conditions: [cond({ field: 'amount', matchType: 'greater_than', pattern: '10' })] }]));

        expect(builder.validate()).toEqual({ valid: true, errors: [] });
    });

    it('rejects the untouched starting condition for having no pattern', () => {
        const result = build().validate();

        expect(result.valid).toBe(false);
        expect(result.errors).toEqual(['Condition at condition 1 has no pattern value']);
    });

    it('treats a whitespace-only pattern as missing', () => {
        const result = build(tree([cond({ pattern: '   ' })])).validate();

        expect(result.errors).toEqual(['Condition at condition 1 has no pattern value']);
    });

    it('names nested positions in its messages', () => {
        const result = build(tree([cond(), { operator: 'AND', conditions: [cond(), cond({ pattern: '' })] }])).validate();

        expect(result.errors).toEqual(['Condition at condition 2 > condition 2 has no pattern value']);
    });

    it('reports a missing field and a missing match type', () => {
        const result = build(tree([cond({ field: '', matchType: '' })])).validate();

        expect(result.errors).toEqual([
            'Condition at condition 1 has no field selected',
            'Condition at condition 1 has no match type selected',
        ]);
    });

    it('reports an empty group', () => {
        const builder = build(tree([cond(), { operator: 'AND', conditions: [] }]));

        expect(builder.validate().errors).toEqual(['Group at condition 2 has no conditions']);
    });

    it('names the root when the root group is empty', () => {
        const builder = build(tree([cond()]));
        builder.getCriteria().root.conditions = [];

        expect(builder.validate().errors).toEqual(['Group at root has no conditions']);
    });

    it('accepts a valid regex', () => {
        expect(build(tree([cond({ matchType: 'regex', pattern: '^ORDER-\\d+$' })])).validate().valid).toBe(true);
    });

    it('rejects an invalid regex with the parser\'s reason', () => {
        const result = build(tree([cond({ matchType: 'regex', pattern: '([a-z' })])).validate();

        expect(result.valid).toBe(false);
        expect(result.errors[0]).toMatch(/^Condition at condition 1 has invalid regex pattern: /);
    });

    it('accepts a between range with min and max', () => {
        const result = build(tree([cond({ field: 'amount', matchType: 'between', pattern: '{"min": 10, "max": 100}' })])).validate();

        expect(result.valid).toBe(true);
    });

    it('rejects a between range that is not JSON', () => {
        const result = build(tree([cond({ field: 'amount', matchType: 'between', pattern: '10-100' })])).validate();

        expect(result.errors).toEqual(["Condition at condition 1 'between' pattern must be valid JSON with min/max"]);
    });

    it('rejects a between range missing max', () => {
        const result = build(tree([cond({ field: 'date', matchType: 'between', pattern: '{"min": "2026-01-01"}' })])).validate();

        expect(result.errors).toEqual(["Condition at condition 1 'between' pattern must have 'min' and 'max' properties"]);
    });

    // `!parsed.min` treats 0 as missing, so "amount between 0 and 100" can't
    // be saved from the visual builder.
    it.fails('accepts a between range starting at 0 (known bug: min of 0 read as missing)', () => {
        const result = build(tree([cond({ field: 'amount', matchType: 'between', pattern: '{"min": 0, "max": 100}' })])).validate();

        expect(result.valid).toBe(true);
    });

    // Criteria can come from the JSON editor or an older save with a numeric
    // pattern (an account id). validate() calls .trim() on it and throws.
    it.fails('validates a numeric pattern instead of throwing (known bug: pattern.trim on a number)', () => {
        const builder = build(tree([cond({ field: 'account', matchType: 'equals', pattern: 7 })]), { accounts: ACCOUNTS });

        expect(builder.validate()).toEqual({ valid: true, errors: [] });
    });
});

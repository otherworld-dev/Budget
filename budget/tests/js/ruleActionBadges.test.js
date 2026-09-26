/**
 * The rule list summarises each rule's actions as badges. The builder saves
 * v2 actions as set_category, add_tags and so on, while the badges were
 * matched on the bare names, so every rule made in the builder showed
 * "No actions".
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/modules/rules/components/ActionBuilder.css', () => ({}));
vi.mock('../../src/modules/rules/components/CriteriaBuilder.css', () => ({}));

import RulesModule from '../../src/modules/rules/RulesModule.js';

function badgesFor(actions, categories = []) {
    const module = Object.create(RulesModule.prototype);
    Object.defineProperty(module, 'categories', { value: categories });
    return module.getRuleActionBadges({}, { version: 2, actions });
}

describe('rule action badges', () => {
    it('names the category a builder rule sets', () => {
        const html = badgesFor([{ type: 'set_category', value: '7' }], [{ id: 7, name: 'Groceries' }]);
        expect(html).toContain('Groceries');
        expect(html).not.toContain('No actions');
    });

    it('shows badges for the other builder action types', () => {
        const html = badgesFor([
            { type: 'set_vendor', value: 'Tesco' },
            { type: 'add_tags', value: [1] },
            { type: 'set_type', value: 'debit' },
            { type: 'set_account', value: 3 },
            { type: 'set_reference', value: 'x' },
            { type: 'set_notes', value: 'y' },
        ]);
        for (const cls of ['vendor', 'tags', 'type', 'account', 'reference', 'notes']) {
            expect(html).toContain(`action-badge ${cls}`);
        }
    });

    it('shows badges for text transform and regex actions', () => {
        const html = badgesFor([
            { type: 'set_description', value: 'Updated description' },
            { type: 'regex_replace', field: 'description', target: 'reference', pattern: '/\\d+/', replacement: 'X' },
            { type: 'change_case', field: 'description', mode: 'sentence' },
            { type: 'replace_text', field: 'description', find: 'foo', replace: 'bar' },
        ]);
        expect(html).toContain('Regular expression: description → reference');
        expect(html).toContain('Case change on description');
        expect(html).toContain('Replace in description');
    });
});

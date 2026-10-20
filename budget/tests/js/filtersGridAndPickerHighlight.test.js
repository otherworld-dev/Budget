/**
 * Two small layout points from the last #399 browser check.
 *
 * On a phone the transactions filter grid's columns were sized by their
 * widest select option, so Category, Status and Search ran past the panel's
 * edge. And the inline category picker's arrow-key highlight was only the
 * faint hover fill, where the tag picker's has a ring.
 */

import { describe, it, expect } from 'vitest';

import { RULES } from './helpers/stylesheet.js';

const rulesFor = (selector) => RULES.filter(rule => rule.selectors.includes(selector));

describe('the transactions filter grid', () => {
    it('lets its columns shrink below their controls\' content', () => {
        const columns = rulesFor('.filters-grid').map(rule => rule.declarations['grid-template-columns']).filter(Boolean);

        expect(columns.length).toBeGreaterThanOrEqual(2);
        columns.forEach(value => expect(value).toMatch(/minmax\(0, 1fr\)/));
    });

    it('keeps its selects and inputs inside their column on a phone', () => {
        for (const selector of ['.filters-grid select', '.filters-grid input']) {
            const rule = rulesFor(selector).find(r => r.declarations['max-width']);
            expect(rule?.declarations['max-width'], selector).toBe('100%');
        }
    });
});

describe('the inline category picker', () => {
    it('rings the item the arrow keys are on, as the tag picker does', () => {
        const ring = rulesFor('.category-autocomplete-item.highlighted').find(r => r.declarations.outline);

        expect(ring?.declarations.outline).toBe(rulesFor('.tags-autocomplete-item.highlighted').find(r => r.declarations.outline)?.declarations.outline);
    });
});

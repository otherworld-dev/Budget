import { describe, it, expect } from 'vitest';
import { rememberedRulePayload, findRememberedRule } from '../../src/utils/receiptItemRules.js';

describe('rememberedRulePayload', () => {
    const body = rememberedRulePayload('DOG TREATS', '20', 'Receipt items');

    it('matches the item text exactly, on description only', () => {
        expect(body.criteria.root.conditions).toEqual([
            { type: 'condition', field: 'description', matchType: 'equals', pattern: 'DOG TREATS' },
        ]);
    });

    it('never runs on bank imports', () => {
        expect(body.applyOnImport).toBe(false);
    });

    it('sets the category as a numeric id and outranks the setup defaults', () => {
        expect(body.actions.actions).toEqual([{ type: 'set_category', value: 20 }]);
        expect(body.priority).toBeGreaterThan(0);
        expect(body.schemaVersion).toBe(2);
    });
});

describe('findRememberedRule', () => {
    const remembered = { id: 5, ...rememberedRulePayload('Dog Treats', 20, 'Receipt items') };

    it('finds an earlier remembered rule case-insensitively', () => {
        expect(findRememberedRule([remembered], 'DOG TREATS')).toBe(remembered);
    });

    it('ignores rules the user made that also run on imports', () => {
        const imported = { ...remembered, id: 6, applyOnImport: true };
        expect(findRememberedRule([imported], 'DOG TREATS')).toBeNull();
    });

    it('ignores contains rules and multi-condition rules', () => {
        const contains = {
            ...remembered,
            criteria: { version: 2, root: { operator: 'AND', conditions: [{ ...remembered.criteria.root.conditions[0], matchType: 'contains' }] } },
        };
        const twoConditions = {
            ...remembered,
            criteria: { version: 2, root: { operator: 'AND', conditions: [remembered.criteria.root.conditions[0], { type: 'condition', field: 'amount', matchType: 'equals', pattern: '3' }] } },
        };
        expect(findRememberedRule([contains, twoConditions], 'DOG TREATS')).toBeNull();
    });

    it('copes with no rules', () => {
        expect(findRememberedRule(null, 'X')).toBeNull();
    });
});

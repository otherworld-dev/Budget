import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/modules/rules/components/ActionBuilder.css', () => ({}));

import { ActionBuilder } from '../../src/modules/rules/components/ActionBuilder.js';

describe('ActionBuilder regex replace action', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="action-builder"></div>';
    });

    it('renders the regex replace form and validates a valid action', () => {
        const builder = new ActionBuilder(document.getElementById('action-builder'));
        builder.actions = [{
            type: 'regex_replace',
            field: 'description',
            pattern: '/\\d+/',
            replacement: 'X',
            behavior: 'always',
            priority: 50,
        }];
        builder.render();

        expect(builder.validate().valid).toBe(true);
        expect(document.body.innerHTML).toContain('Regex Replace');
        expect(document.body.innerHTML).toContain('action-pattern');
        expect(document.body.innerHTML).toContain('action-replacement');
    });

    it('defaults new regex actions to concrete source and target fields', () => {
        const builder = new ActionBuilder(document.getElementById('action-builder'));
        builder.addAction('regex_replace');

        expect(builder.actions[0].field).toBe('description');
        expect(builder.actions[0].target).toBe('description');
        expect(document.querySelector('.action-field option[value=""]')).toBeNull();
        expect(document.querySelector('.action-target option[value=""]')).toBeNull();
    });

    it('validates a valid Change Case action', () => {
        const builder = new ActionBuilder(document.getElementById('action-builder'));
        builder.actions = [{
            type: 'change_case',
            field: 'description',
            mode: 'sentence',
            behavior: 'always',
            priority: 50,
        }];

        const result = builder.validate();
        expect(result.valid).toBe(true);
        expect(result.errors).toEqual([]);
    });

    it('accepts a full regex literal with flags without client-side rejection', () => {
        const builder = new ActionBuilder(document.getElementById('action-builder'));
        builder.actions = [{
            type: 'regex_replace',
            field: 'description',
            pattern: '/\\d+/i',
            replacement: 'X',
            behavior: 'always',
            priority: 50,
        }];

        const result = builder.validate();
        expect(result.valid).toBe(true);
        expect(result.errors).toEqual([]);
    });
});

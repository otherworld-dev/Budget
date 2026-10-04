/**
 * The rules "Create default categories" adds sit at priority 0, and a rule
 * made in the editor used to start at 0 too: being newer, it lost to an
 * overlapping default every time (T3). A new rule now starts at 1; an
 * existing rule keeps its own priority, 0 included.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(),
    ApiError: class ApiError extends Error {},
}));

vi.mock('../../src/modules/rules/components/ActionBuilder.css', () => ({}));
vi.mock('../../src/modules/rules/components/CriteriaBuilder.css', () => ({}));

import RulesModule, { NEW_RULE_PRIORITY } from '../../src/modules/rules/RulesModule.js';

function makeModule() {
    const mod = Object.create(RulesModule.prototype);
    mod.app = { categories: [], accounts: [] };
    mod.resetRuleView = vi.fn();
    mod.populateGroupDatalist = vi.fn();
    mod.populateRuleCategoryDropdown = vi.fn();
    mod.initializeCriteriaBuilder = vi.fn();
    mod.initializeActionBuilder = vi.fn();
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="rule-modal" style="display:none">
            <h3 id="rule-modal-title"></h3>
            <form id="rule-form">
                <input type="hidden" id="rule-id">
                <input type="text" id="rule-name">
                <input type="text" id="rule-group-name">
                <input type="number" id="rule-priority" min="0" max="100" value="0">
                <input type="checkbox" id="rule-active" checked>
                <input type="checkbox" id="rule-apply-on-import" checked>
            </form>
        </div>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('a rule made in the editor', () => {
    it('starts above the default rules', async () => {
        await makeModule().showRuleModal();

        expect(NEW_RULE_PRIORITY).toBeGreaterThan(0);
        expect(document.getElementById('rule-priority').value).toBe(String(NEW_RULE_PRIORITY));
    });

    it('keeps an existing rule\'s priority, 0 included', async () => {
        await makeModule().showRuleModal({
            id: 4, name: 'Old rule', priority: 0, schemaVersion: 2,
            criteria: { version: 2, root: { operator: 'AND', conditions: [] } },
            actions: { version: 2, actions: [] },
        });

        expect(document.getElementById('rule-priority').value).toBe('0');
    });
});

/**
 * Savings goal cards with net-negative progress.
 *
 * A goal's currentAmount can go negative (withdrawals exceeding deposits on
 * a tag-linked goal). An unclamped percentage renders `width: -N%` — invalid
 * CSS, dropped — and `.goal-progress-fill` has no base width, so the fill
 * paints the bar FULL for a goal that is actually below zero. Same failure
 * mode as the four progress bars clamped in v2.44.2 (#361).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import SavingsModule from '../../src/modules/savings/SavingsModule.js';

function makeSavings() {
    const mod = Object.create(SavingsModule.prototype);
    mod.app = { settings: {} };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = '<div id="goals-list"></div><div id="empty-goals"></div>';
});

describe('renderGoals', () => {
    it('clamps the fill to zero for a goal whose withdrawals exceed deposits', () => {
        makeSavings().renderGoals([
            { id: 1, name: 'Rainy day', currentAmount: -50, targetAmount: 200 },
        ]);

        const html = document.getElementById('goals-list').innerHTML;
        expect(html).toContain('width: 0%');
        expect(html).not.toContain('width: -');
    });

    it('leaves ordinary progress untouched and caps at 100', () => {
        makeSavings().renderGoals([
            { id: 1, name: 'Holiday', currentAmount: 50, targetAmount: 200 },
            { id: 2, name: 'Done', currentAmount: 500, targetAmount: 200 },
        ]);

        const html = document.getElementById('goals-list').innerHTML;
        expect(html).toContain('width: 25%');
        expect(html).toContain('width: 100%');
    });
});

/**
 * Budget progress bars said "over budget" only by turning red. They now carry
 * role="progressbar" with their value, and an overspend adds visually hidden
 * "Over budget" text beside the bar for screen readers.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { progressBarAttrs, overBudgetText } from '../../src/utils/budgetProgress.js';
import ProjectsModule from '../../src/modules/projects/ProjectsModule.js';

function render(markup) {
    const el = document.createElement('div');
    el.innerHTML = markup;
    return el;
}

describe('progressBarAttrs', () => {
    it('describes the bar with its value and name', () => {
        const bar = render(`<div ${progressBarAttrs(42.4, 'Groceries & more')}></div>`).firstElementChild;
        expect(bar.getAttribute('role')).toBe('progressbar');
        expect(bar.getAttribute('aria-valuemin')).toBe('0');
        expect(bar.getAttribute('aria-valuemax')).toBe('100');
        expect(bar.getAttribute('aria-valuenow')).toBe('42');
        expect(bar.getAttribute('aria-label')).toBe('Groceries & more');
    });

    it('keeps aria-valuenow in range but reports the real figure as text', () => {
        const bar = render(`<div ${progressBarAttrs(130)}></div>`).firstElementChild;
        expect(bar.getAttribute('aria-valuenow')).toBe('100');
        expect(bar.getAttribute('aria-valuetext')).toBe('130%');
    });
});

describe('overBudgetText', () => {
    it('is visually hidden text, only when over', () => {
        expect(overBudgetText(false)).toBe('');
        const span = render(overBudgetText(true)).firstElementChild;
        expect(span.className).toBe('visually-hidden');
        expect(span.textContent).toBe('Over budget');
    });
});

describe('project card', () => {
    it('marks an overspent project for screen readers', () => {
        const mod = Object.create(ProjectsModule.prototype);
        mod.money = (v) => String(v);
        mod.remainingText = () => '';
        mod.timeText = () => '';
        const el = render(mod.summaryHtml({ name: 'Kitchen', spent: 150, totalAmount: 100, remaining: -50 }));

        expect(el.querySelector('[role="progressbar"]').getAttribute('aria-valuetext')).toBe('150%');
        expect(el.querySelector('.visually-hidden').textContent).toBe('Over budget');
    });
});

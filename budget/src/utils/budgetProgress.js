import { translate as t } from '@nextcloud/l10n';
import { escapeHtml } from './dom.js';

/**
 * The progress bar band for spending against a budget. Shared by the Budget
 * page and project budgets so the two can never colour the same figure
 * differently (#391). The names match the .budget-progress-fill classes.
 *
 * @param {number} percentage spent as a percentage of the budget, clamped to 0-100
 * @returns {'good'|'warning'|'danger'|'over'}
 */
export function expenseProgressStatus(percentage) {
    if (percentage >= 100) return 'over';
    if (percentage >= 80) return 'danger';
    if (percentage >= 60) return 'warning';
    return 'good';
}

/**
 * ARIA for a progress bar whose only other signal is colour and width.
 * Goes on the .budget-progress-bar track (not the fill), so screen readers
 * announce it as a progress bar with its value.
 *
 * aria-valuenow has to stay within min/max, so an overspend reads 100 there;
 * aria-valuetext carries the real figure ("123%").
 *
 * @param {number} percent spent as a percentage of the budget (may exceed 100)
 * @param {string} [label] what the bar measures, e.g. the category name
 * @returns {string} attribute markup
 */
export function progressBarAttrs(percent, label) {
    const value = Number.isFinite(Number(percent)) ? Number(percent) : 0;
    const now = Math.round(Math.min(Math.max(value, 0), 100));
    const text = `${Math.round(value)}%`;
    return `role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${now}" aria-valuetext="${escapeHtml(text)}"`
        + (label ? ` aria-label="${escapeHtml(label)}"` : '');
}

/**
 * "Over budget" for screen readers, beside a bar that shows it only in red.
 * Kept outside the progressbar element: its children are presentational.
 *
 * @param {boolean} over
 * @returns {string} markup, or '' when not over
 */
export function overBudgetText(over) {
    return over ? `<span class="visually-hidden">${escapeHtml(t('budget', 'Over budget'))}</span>` : '';
}

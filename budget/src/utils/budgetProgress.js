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

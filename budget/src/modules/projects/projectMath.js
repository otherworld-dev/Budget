/**
 * How project budgets read on screen (#391). The server works out the
 * figures; this decides the grouping, the bars, the form's running total and
 * which categories the form offers.
 */
import { expenseProgressStatus } from '../../utils/budgetProgress.js';
import { toCents } from '../../utils/money.js';

/**
 * Running and upcoming projects first, soonest start first; finished ones
 * apart, most recent first.
 */
export function groupProjects(projects) {
    const open = [];
    const finished = [];
    for (const project of projects || []) {
        (project.status === 'finished' ? finished : open).push(project);
    }
    const byStart = (a, b) => String(a.startDate).localeCompare(String(b.startDate))
        || String(a.name).localeCompare(String(b.name));
    open.sort(byStart);
    finished.sort((a, b) => byStart(b, a));
    return { open, finished };
}

/**
 * The bar for spending against an amount. The width is clamped to 0-100,
 * since a negative width is invalid CSS and paints the bar full. The colour
 * uses the Budget page's bands, and the percentage for the label is left
 * unclamped.
 */
export function progressFor(spent, amount) {
    const raw = amount > 0 ? (spent / amount) * 100 : 0;
    const width = Math.min(Math.max(raw, 0), 100);
    return { width, status: expenseProgressStatus(width), percent: Math.round(raw) };
}

/**
 * The form's running total: what is left of the total once the subcategory
 * amounts are taken off, in whole pennies. Blank amounts count as nothing.
 *
 * @param {string|number} total
 * @param {Array<string|number|null>} amounts
 * @returns {{cents: number, over: boolean, valid: boolean}}
 */
export function unallocated(total, amounts) {
    const allocated = amounts.reduce((sum, value) => {
        const cents = toCents(value);
        return sum + (Number.isNaN(cents) ? 0 : cents);
    }, 0);
    const totalCents = toCents(total);
    if (Number.isNaN(totalCents)) {
        return { cents: -allocated, over: allocated > 0, valid: false };
    }
    const cents = totalCents - allocated;
    return { cents, over: cents < 0, valid: true };
}

function findNode(nodes, id) {
    for (const node of nodes || []) {
        if (node.id === id) return node;
        const found = findNode(node.children, id);
        if (found) return found;
    }
    return null;
}

/**
 * The subcategories that can be given an amount: every descendant in tree
 * order with its depth below the category, leaving out anything flagged
 * Exclude from reports together with everything under it, as the server does.
 *
 * @returns {Array<{id: number, name: string, depth: number}>}
 */
export function subcategoriesOf(categoryTree, categoryId) {
    const list = [];
    const walk = (nodes, depth) => {
        for (const node of nodes || []) {
            if (node.excludedFromReports) continue;
            list.push({ id: node.id, name: node.name, depth });
            walk(node.children, depth + 1);
        }
    };
    const root = findNode(categoryTree, categoryId);
    if (root) walk(root.children, 1);
    return list;
}

/**
 * The tree pruned to the viewer's own expense categories, for the project's
 * category picker. Shared categories stay out: the project would be built on
 * someone else's category.
 *
 * Pass the tree as the server sends it (app.rawCategoryTree), not the merged
 * app.categoryTree: merging swaps your own top-level category for a shared
 * one of the same name, so your category and everything under it would be
 * dropped here with the shared node.
 */
export function ownExpenseTree(categoryTree) {
    return (categoryTree || [])
        .filter(node => !node._shared && node.type === 'expense')
        .map(node => ({ ...node, children: ownExpenseTree(node.children) }));
}

/**
 * Pure data transform for the Money Flow (Sankey) report (#366).
 *
 * Turns the two `categories/spending` responses (one per transaction
 * direction, netted and signed) into a flow graph a Sankey chart can render
 * directly, Firefly III cashflow style: income categories on the left flow
 * into a central "Cash Flow" node, which fans back out on the right into
 * expense parent categories and -- where they have their own spending --
 * further into their subcategories. The gap between income and expenses
 * becomes a Surplus node (income > expenses, flowing out of center) or a
 * Deficit node (expenses > income, flowing into center).
 *
 * No DOM, no Chart.js here -- ReportsModule.js owns fetching both directions
 * and rendering the result.
 */
import { translate as t } from '@nextcloud/l10n';

export const CENTER_KEY = 'center';
export const SURPLUS_KEY = 'surplus';
export const DEFICIT_KEY = 'deficit';

const DEFAULT_INCOME_COLOR = '#2e7d32';  // matches the green used for income elsewhere in Reports
const DEFAULT_EXPENSE_COLOR = '#c62828'; // matches the red used for expenses elsewhere in Reports
const SURPLUS_COLOR = '#2e7d32';
const DEFICIT_COLOR = '#c62828';
const CENTER_COLOR = '#2196f3';          // matches the blue used for the net cash-flow line

/**
 * Netted "spent" values can be negative when refunds outweighed spending in
 * the period; that's not a flow in the diagram, so treat it as zero.
 * @param {number|string} value
 * @returns {number}
 */
function clampSpent(value) {
    const amount = parseFloat(value);
    if (!Number.isFinite(amount) || amount <= 0) return 0;
    return amount;
}

/**
 * Walk parentId links up to the top-level ancestor. Guards against a cycle
 * (shouldn't happen, but a pure function should never loop forever on bad data).
 * @param {object} category
 * @param {Map<number, object>} categoriesById
 * @returns {object}
 */
function topLevelAncestor(category, categoriesById) {
    let current = category;
    const seen = new Set();
    while (current.parentId != null && !seen.has(current.id)) {
        seen.add(current.id);
        const parent = categoriesById.get(current.parentId);
        if (!parent) break;
        current = parent;
    }
    return current;
}

/**
 * @param {Array<{categoryId:number, spent:number|string, name?:string, color?:string|null}>} incomeRows
 *   Response of GET categories/spending?transactionType=credit for the period.
 * @param {Array<{categoryId:number, spent:number|string, name?:string, color?:string|null}>} expenseRows
 *   Response of GET categories/spending?transactionType=debit for the period.
 * @param {Array<{id:number, name:string, type:string, parentId:number|null, color?:string|null}>} categories
 *   The app's flat category list (this.app.categories), used to resolve each
 *   row's category type/parent/name/color and to filter each side by type --
 *   the endpoint returns every category with activity in that direction
 *   regardless of the category's own type (e.g. a refund credit posted
 *   against an expense category).
 * @returns {{
 *   flows: Array<{from:string, to:string, flow:number}>,
 *   labels: Record<string,string>,
 *   colors: Record<string,string>,
 *   totals: {income:number, expenses:number, surplus:number}
 * }}
 */
export function buildMoneyFlows(incomeRows, expenseRows, categories) {
    const categoriesById = new Map((categories || []).map(cat => [cat.id, cat]));

    const flows = [];
    const labels = { [CENTER_KEY]: t('budget', 'Cash Flow') };
    const colors = { [CENTER_KEY]: CENTER_COLOR };

    // ----- Income: roll every subcategory's spending into its top-level parent -----
    const incomeTotals = new Map(); // top-level categoryId -> amount
    const incomeMeta = new Map();   // top-level categoryId -> { name, color }

    (incomeRows || []).forEach(row => {
        const category = categoriesById.get(row.categoryId);
        if (!category || category.type !== 'income') return; // filter by type (#endpoint returns both directions)
        const amount = clampSpent(row.spent);
        if (amount <= 0) return;

        const top = topLevelAncestor(category, categoriesById);
        incomeTotals.set(top.id, (incomeTotals.get(top.id) || 0) + amount);
        if (!incomeMeta.has(top.id)) {
            incomeMeta.set(top.id, { name: top.name, color: top.color });
        }
    });

    let totalIncome = 0;
    incomeTotals.forEach((amount, categoryId) => {
        const key = `in:${categoryId}`;
        const meta = incomeMeta.get(categoryId);
        flows.push({ from: key, to: CENTER_KEY, flow: amount });
        labels[key] = meta.name;
        colors[key] = meta.color || DEFAULT_INCOME_COLOR;
        totalIncome += amount;
    });

    // ----- Expenses: each parent's own spending, fanned out to subcategories with spending -----
    const expenseSpend = new Map(); // categoryId -> clamped own spending
    (expenseRows || []).forEach(row => {
        const category = categoriesById.get(row.categoryId);
        if (!category || category.type !== 'expense') return; // filter by type
        expenseSpend.set(row.categoryId, clampSpent(row.spent));
    });

    const childrenByParent = new Map();
    (categories || []).forEach(cat => {
        if (cat.type === 'expense' && cat.parentId != null) {
            if (!childrenByParent.has(cat.parentId)) childrenByParent.set(cat.parentId, []);
            childrenByParent.get(cat.parentId).push(cat);
        }
    });

    // "Top-level" = no parent, or the parent isn't itself an expense category
    // (defensive against malformed data -- a real cross-type parent shouldn't happen).
    const topLevelExpenseCategories = (categories || []).filter(cat => {
        if (cat.type !== 'expense') return false;
        if (cat.parentId == null) return true;
        const parent = categoriesById.get(cat.parentId);
        return !parent || parent.type !== 'expense';
    });

    let totalExpenses = 0;
    topLevelExpenseCategories.forEach(parent => {
        const children = childrenByParent.get(parent.id) || [];
        const spendingChildren = children
            .map(child => ({ child, amount: expenseSpend.get(child.id) || 0 }))
            .filter(({ amount }) => amount > 0);

        const childTotal = spendingChildren.reduce((sum, { amount }) => sum + amount, 0);
        const ownAmount = expenseSpend.get(parent.id) || 0;
        const parentTotal = ownAmount + childTotal;
        if (parentTotal <= 0) return; // no activity anywhere in this branch this period

        const parentKey = `out:${parent.id}`;
        flows.push({ from: CENTER_KEY, to: parentKey, flow: parentTotal });
        labels[parentKey] = parent.name;
        colors[parentKey] = parent.color || DEFAULT_EXPENSE_COLOR;
        totalExpenses += parentTotal;

        spendingChildren.forEach(({ child, amount }) => {
            const childKey = `out:${child.id}`;
            flows.push({ from: parentKey, to: childKey, flow: amount });
            labels[childKey] = child.name;
            colors[childKey] = child.color || DEFAULT_EXPENSE_COLOR;
        });
    });

    // ----- Surplus / deficit -----
    const surplus = totalIncome - totalExpenses;
    if (surplus > 0) {
        flows.push({ from: CENTER_KEY, to: SURPLUS_KEY, flow: surplus });
        labels[SURPLUS_KEY] = t('budget', 'Surplus');
        colors[SURPLUS_KEY] = SURPLUS_COLOR;
    } else if (surplus < 0) {
        flows.push({ from: DEFICIT_KEY, to: CENTER_KEY, flow: -surplus });
        labels[DEFICIT_KEY] = t('budget', 'Deficit');
        colors[DEFICIT_KEY] = DEFICIT_COLOR;
    }

    return {
        flows,
        labels,
        colors,
        totals: { income: totalIncome, expenses: totalExpenses, surplus },
    };
}

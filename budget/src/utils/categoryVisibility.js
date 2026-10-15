/**
 * Categories a tile has been told to leave out (#413).
 *
 * A tile stores only the categories the user unticked. Unticking a parent
 * hides everything under it, the same way excluded_from_reports covers a
 * whole branch, so the rows are matched against the expanded set rather than
 * the saved list.
 */

const MAX_DEPTH = 64;

function rowCategoryId(row) {
    return parseInt(row.id ?? row.categoryId, 10);
}

/**
 * The saved hidden ids plus every category beneath each of them.
 *
 * @param {Array<number|string>} hiddenIds - The ids the user unticked
 * @param {Array<{id: number, parentId: ?number}>} categories - Flat category list
 * @returns {Set<number>}
 */
export function hiddenCategoryBranch(hiddenIds, categories) {
    const hidden = new Set(
        (Array.isArray(hiddenIds) ? hiddenIds : [])
            .map(id => parseInt(id, 10))
            .filter(id => !Number.isNaN(id))
    );
    if (hidden.size === 0) return hidden;

    const parentOf = new Map();
    for (const cat of categories || []) {
        parentOf.set(parseInt(cat.id, 10), cat.parentId == null ? null : parseInt(cat.parentId, 10));
    }

    const branch = new Set(hidden);
    for (const id of parentOf.keys()) {
        let cursor = parentOf.get(id);
        for (let depth = 0; cursor != null && depth < MAX_DEPTH; depth++) {
            if (hidden.has(cursor)) {
                branch.add(id);
                break;
            }
            cursor = parentOf.get(cursor);
        }
    }
    return branch;
}

/**
 * Drop the rows filed under a hidden category. Rows name their category as
 * `id` (spending summaries) or `categoryId`.
 */
export function withoutHiddenCategories(rows, hiddenIds, categories) {
    const branch = hiddenCategoryBranch(hiddenIds, categories);
    if (branch.size === 0) return rows;
    return rows.filter(row => !branch.has(rowCategoryId(row)));
}

/**
 * The expense categories in tree order for a picker: each parent followed by
 * its children, siblings by their saved order and then by name. A category
 * whose parent is missing is listed at the top level so it can still be
 * unticked.
 *
 * @returns {Array<{id: number, name: string, depth: number, parentId: ?number}>}
 */
export function categoryPickerRows(categories) {
    const expense = (categories || []).filter(cat => cat.type !== 'income');
    const ids = new Set(expense.map(cat => cat.id));
    const childrenOf = new Map();
    for (const cat of expense) {
        const key = cat.parentId != null && ids.has(cat.parentId) ? cat.parentId : null;
        if (!childrenOf.has(key)) childrenOf.set(key, []);
        childrenOf.get(key).push(cat);
    }

    const bySavedOrder = (a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0)
        || String(a.name).localeCompare(String(b.name));

    const rows = [];
    const visited = new Set();
    const walk = (parentKey, depth) => {
        for (const cat of (childrenOf.get(parentKey) || []).sort(bySavedOrder)) {
            if (visited.has(cat.id) || depth >= MAX_DEPTH) continue;
            visited.add(cat.id);
            rows.push({ id: cat.id, name: cat.name, depth, parentId: cat.parentId ?? null });
            walk(cat.id, depth + 1);
        }
    };
    walk(null, 0);
    return rows;
}

/**
 * Which categories the category form offers as a parent.
 *
 * A category only ever sits in its owner's tree, so the choice depends on
 * whose category is being saved:
 *  - 'add'    a new category: your own categories, plus any shared with you
 *             at Full control (the new one then belongs to that owner)
 *  - 'own'    editing one of your own: your own categories only
 *  - { owner } editing a category someone gave you Full control of: that
 *             person's categories you have Full control of
 *
 * Pass the tree as the server sends it (app.rawCategoryTree), not the merged
 * app.categoryTree: merging swaps your own category for a shared one of the
 * same name, which would offer the other person's id under your category's
 * name (#402).
 *
 * @param {Array} rawTree - own categories, then shared ones, each with children
 * @param {'add'|'own'|{owner: string}} scope
 * @returns {Array} a tree for dom.populateCategorySelect
 */
export function parentPickerTree(rawTree, scope) {
    const tree = rawTree || [];
    if (scope && typeof scope === 'object') {
        return manageable(tree, node => node._sharedBy === scope.owner, false);
    }
    const own = tree.filter(node => !node._shared);
    if (scope !== 'add') {
        return own;
    }
    return [...own, ...manageable(tree.filter(node => node._shared), () => true, true)];
}

/**
 * The shared nodes matching `accept` that you have Full control of, keeping
 * their nesting. A node you can't build under is dropped and its children
 * move up a level, so a Full control subcategory under a read-only parent is
 * still offered.
 */
function manageable(nodes, accept, labelOwner) {
    const out = [];
    for (const node of nodes || []) {
        if (!node._shared) continue;
        const children = manageable(node.children, accept, labelOwner);
        if (node._canManage && accept(node)) {
            const owner = node._sharedByName || node._sharedBy || '';
            out.push({
                ...node,
                // Beside your own categories a shared one can share a name
                name: labelOwner && owner ? `${node.name} · ${owner}` : node.name,
                children,
            });
        } else {
            out.push(...children);
        }
    }
    return out;
}

/**
 * "Remember this" for a receipt item's category (#444).
 *
 * A remembered item is an ordinary categorisation rule — the user can see,
 * edit and delete it on the Rules page like any other — shaped so that it
 * only ever speaks for receipts:
 *
 *  - it matches the item's text exactly (case-insensitive), not "contains",
 *    so "MILK" remembered once doesn't start claiming "MILK CHOCOLATE";
 *  - applyOnImport is off, so it never touches a bank statement import,
 *    where a till's item text has no business matching anything;
 *  - priority 1, the rule editor's starting point, so it beats the setup
 *    defaults (priority 0) when both match an item.
 *
 * Receipt scans still see it: their suggestions run through testRules,
 * which ignores applyOnImport.
 */

/** @returns {object} the POST/PUT body for the import-rules endpoint */
export function rememberedRulePayload(description, categoryId, groupName) {
    return {
        name: description,
        groupName,
        priority: 1,
        active: true,
        applyOnImport: false,
        schemaVersion: 2,
        criteria: {
            version: 2,
            root: {
                operator: 'AND',
                conditions: [{ type: 'condition', field: 'description', matchType: 'equals', pattern: description }],
            },
        },
        actions: { version: 2, stopProcessing: true, actions: [{ type: 'set_category', value: Number(categoryId) }] },
        stopProcessing: true,
    };
}

/**
 * The rule an earlier "Remember" made for this item text, if any, so a
 * second correction updates it instead of stacking a rival rule that ties
 * on priority.
 *
 * @param {Array} rules the user's rules, as GET /api/import-rules returns them
 * @returns {?object}
 */
export function findRememberedRule(rules, description) {
    const wanted = String(description).toLowerCase();
    return (rules || []).find((rule) => {
        if (rule.schemaVersion !== 2 || rule.applyOnImport !== false) return false;
        const conditions = rule.criteria?.root?.conditions;
        if (!Array.isArray(conditions) || conditions.length !== 1) return false;
        const only = conditions[0];
        return only.field === 'description'
            && only.matchType === 'equals'
            && String(only.pattern ?? '').toLowerCase() === wanted;
    }) || null;
}

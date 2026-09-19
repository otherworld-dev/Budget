/**
 * Category colours (#392).
 *
 * The category form always started on the same blue, so every category had to
 * be given a colour by hand and each new one checked against the rest. New
 * categories now take the palette colour used least so far, earliest in the
 * palette on a tie.
 *
 * The palette is ordered so that neighbouring entries contrast: the first
 * sixteen are the 500 shades the server already picks from, with hues spread
 * apart, and the next sixteen are the darker 700 shades in the same order,
 * so a budget of twenty or thirty categories still gets no repeats.
 */

export const CATEGORY_PALETTE = [
    '#3b82f6', '#ef4444', '#22c55e', '#f59e0b', '#a855f7', '#06b6d4', '#ec4899', '#84cc16',
    '#f97316', '#6366f1', '#14b8a6', '#d946ef', '#eab308', '#0ea5e9', '#10b981', '#8b5cf6',
    '#1d4ed8', '#b91c1c', '#15803d', '#b45309', '#7e22ce', '#0e7490', '#be185d', '#4d7c0f',
    '#c2410c', '#4338ca', '#0f766e', '#a21caf', '#a16207', '#0369a1', '#047857', '#6d28d9',
];

function usage(usedColors) {
    const counts = new Map();
    (usedColors || []).forEach(color => {
        if (typeof color === 'string' && color !== '') {
            const key = color.toLowerCase();
            counts.set(key, (counts.get(key) || 0) + 1);
        }
    });
    return counts;
}

/**
 * The colour for the next category, given the colours categories already use.
 *
 * @param {Array<string|null>} usedColors
 * @return {string}
 */
export function nextCategoryColor(usedColors = []) {
    const counts = usage(usedColors);
    let best = CATEGORY_PALETTE[0];
    let bestCount = Infinity;
    CATEGORY_PALETTE.forEach(color => {
        const count = counts.get(color) || 0;
        if (count < bestCount) {
            best = color;
            bestCount = count;
        }
    });
    return best;
}

/**
 * A colour for each of `count` categories, different from each other and from
 * `usedColors` for as long as the palette allows.
 *
 * @param {number} count
 * @param {Array<string|null>} usedColors Colours the other categories keep
 * @return {string[]}
 */
export function distinctCategoryColors(count, usedColors = []) {
    const used = [...(usedColors || [])];
    const colors = [];
    for (let i = 0; i < count; i++) {
        const color = nextCategoryColor(used);
        colors.push(color);
        used.push(color);
    }
    return colors;
}

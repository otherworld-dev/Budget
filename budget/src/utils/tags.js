/**
 * Tag picker helpers.
 */

/**
 * The tags a picker should offer when assigning tags to something (#373).
 *
 * A hidden tag is left out — unless the item being edited already carries
 * it, in which case it must stay visible and selected: the forms save the
 * full set of checked boxes, so a missing box would silently strip the tag
 * on the next edit.
 *
 * @param {Array<{id: number, hidden?: boolean}>} tags
 * @param {number[]} keepIds Tag ids already on the item being edited
 * @returns {Array}
 */
export function offerableTags(tags, keepIds = []) {
    const keep = new Set(keepIds);
    return (tags || []).filter(tag => !tag.hidden || keep.has(tag.id));
}

/**
 * Apply offerableTags() to each tag set, dropping a set that hiding has
 * left with nothing to offer. A set that had no tags to begin with is kept,
 * so the pickers can still say "No tags defined" for it.
 *
 * @param {Array<{tags?: Array}>} tagSets
 * @param {number[]} keepIds
 * @returns {Array}
 */
export function offerableTagSets(tagSets, keepIds = []) {
    return (tagSets || [])
        .map(tagSet => ({ tagSet, offered: offerableTags(tagSet.tags, keepIds) }))
        .filter(({ tagSet, offered }) => offered.length > 0 || (tagSet.tags || []).length === 0)
        .map(({ tagSet, offered }) => ({ ...tagSet, tags: offered }));
}

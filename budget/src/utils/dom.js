/**
 * DOM manipulation and HTML utilities
 */

/**
 * Escape HTML special characters to prevent XSS
 * @param {string} str - String to escape
 * @returns {string} Escaped HTML string
 */
export function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#39;');
}

/**
 * Close a modal by hiding it and setting ARIA attributes
 * @param {HTMLElement} modal - Modal element to close
 */
export function closeModal(modal) {
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
}

/**
 * Populate a <select> element with hierarchical category options.
 * Renders the category tree recursively with indentation using non-breaking spaces.
 *
 * @param {HTMLSelectElement} selectElement - The select element to populate (existing options are preserved)
 * @param {Array} categoryTree - Hierarchical category array (each item may have .children)
 * @param {Object} [options] - Optional filters
 * @param {string} [options.typeFilter] - Only include categories matching this type (e.g. 'expense', 'income')
 * @param {number} [options.excludeId] - Exclude this category ID (and skip rendering it, but still recurse children)
 * @param {number|string} [options.selectedId] - Pre-select this category ID
 * @param {number} [level=0] - Current nesting depth (used internally for recursion)
 */
export function populateCategorySelect(selectElement, categoryTree, options = {}, level = 0) {
    if (!selectElement || !Array.isArray(categoryTree)) return;

    const { typeFilter, excludeId, selectedId } = options;

    categoryTree.forEach(category => {
        const matchesType = !typeFilter || category.type === typeFilter;
        const isExcluded = excludeId != null && category.id === excludeId;

        if (matchesType && !isExcluded) {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = '\u00A0\u00A0'.repeat(level) + category.name;
            if (selectedId != null && category.id == selectedId) {
                option.selected = true;
            }
            selectElement.appendChild(option);
        }

        if (category.children && category.children.length > 0) {
            populateCategorySelect(selectElement, category.children, options, matchesType ? level + 1 : level);
        }
    });
}

/**
 * Build an HTML string of <option> elements from a hierarchical category tree.
 * Useful for template-literal-based rendering where DOM manipulation isn't available.
 *
 * @param {Array} categoryTree - Hierarchical category array (each item may have .children)
 * @param {Object} [options] - Optional filters
 * @param {string} [options.typeFilter] - Only include categories matching this type
 * @param {number|string} [options.selectedId] - Pre-select this category ID
 * @param {number} [level=0] - Current nesting depth (used internally for recursion)
 * @returns {string} HTML string of <option> elements
 */
export function buildCategoryOptionsHtml(categoryTree, options = {}, level = 0) {
    if (!Array.isArray(categoryTree)) return '';

    const { typeFilter, selectedId } = options;
    let html = '';

    categoryTree.forEach(category => {
        const matchesType = !typeFilter || category.type === typeFilter;

        if (matchesType) {
            const indent = '\u00A0\u00A0'.repeat(level);
            const selected = selectedId != null && category.id == selectedId ? ' selected' : '';
            html += `<option value="${category.id}"${selected}>${indent}${escapeHtml(category.name)}</option>`;
        }

        if (category.children && category.children.length > 0) {
            html += buildCategoryOptionsHtml(category.children, options, matchesType ? level + 1 : level);
        }
    });

    return html;
}

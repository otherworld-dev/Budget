/**
 * MultiSelect - Reusable checklist dropdown component
 */
import { translate as t } from '@nextcloud/l10n';

export class MultiSelect {
    /**
     * @param {HTMLElement|string} container - Container element or selector
     * @param {Object} [options] - Configuration options
     * @param {string} [options.placeholder] - Text when no item is selected
     * @param {Function} [options.onChange] - Selection change callback
     */
    constructor(container, options = {}) {
        this.container = typeof container === 'string' ? document.querySelector(container) : container;
        if (!this.container) {
            throw new Error('MultiSelect container element not found');
        }

        this.placeholder = options.placeholder || t('budget', 'Select column(s)...');
        this.onChange = options.onChange || null;
        this.hasAllOption = options.hasAllOption || false;
        this.allOptionLabel = options.allOptionLabel || t('budget', 'All');
        this.summaryFormatter = options.summaryFormatter || null;

        this.optionsList = [];
        this.selectedValues = new Set();
        this._documentClickHandler = null;

        this.init();
    }

    init() {
        this.container.innerHTML = '';
        this.container.classList.add('custom-multiselect');

        // Toggle button
        this.toggleBtn = document.createElement('button');
        this.toggleBtn.type = 'button';
        this.toggleBtn.className = 'custom-multiselect-toggle';
        this.toggleBtn.setAttribute('aria-haspopup', 'true');
        this.toggleBtn.setAttribute('aria-expanded', 'false');

        this.summarySpan = document.createElement('span');
        this.summarySpan.className = 'custom-multiselect-summary';
        this.summarySpan.textContent = this.placeholder;

        this.caretSpan = document.createElement('span');
        this.caretSpan.className = 'custom-multiselect-caret';
        this.caretSpan.setAttribute('aria-hidden', 'true');
        this.caretSpan.textContent = '▾';

        this.toggleBtn.appendChild(this.summarySpan);
        this.toggleBtn.appendChild(this.caretSpan);

        // Menu container
        this.menu = document.createElement('div');
        this.menu.className = 'custom-multiselect-menu';
        this.menu.setAttribute('role', 'group');
        this.menu.style.display = 'none';

        // Options wrapper
        this.optionsContainer = document.createElement('div');
        this.optionsContainer.className = 'custom-multiselect-options';
        this.menu.appendChild(this.optionsContainer);

        this.container.appendChild(this.toggleBtn);
        this.container.appendChild(this.menu);

        this.bindEvents();
    }

    bindEvents() {
        this.toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = this.menu.style.display !== 'none';
            if (isOpen) {
                this.close();
            } else {
                this.open();
            }
        });

        this._documentClickHandler = (e) => {
            if (!this.container.contains(e.target)) {
                this.close();
            }
        };
        document.addEventListener('click', this._documentClickHandler);
    }

    open() {
        this.menu.style.display = 'block';
        this.toggleBtn.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.menu.style.display = 'none';
        this.toggleBtn.setAttribute('aria-expanded', 'false');
    }

    /**
     * Set available dropdown options
     * @param {Array<string|number|{value: string|number, label: string}>} items
     */
    setOptions(items = []) {
        this.optionsList = items.map(item => {
            if (item !== null && typeof item === 'object' && 'value' in item) {
                return { value: String(item.value), label: String(item.label) };
            }
            return { value: String(item), label: String(item) };
        });

        // Retain only selected values that still exist in options
        const currentVals = new Set(this.selectedValues);
        this.selectedValues.clear();
        currentVals.forEach(val => {
            if (this.optionsList.some(opt => opt.value === val)) {
                this.selectedValues.add(val);
            }
        });

        this.renderOptions();
        this.updateSummary();
    }

    renderOptions() {
        this.optionsContainer.innerHTML = '';

        if (this.hasAllOption) {
            const allLabel = document.createElement('label');
            allLabel.className = 'custom-multiselect-option custom-multiselect-all';

            const allCb = document.createElement('input');
            allCb.type = 'checkbox';
            allCb.checked = this.selectedValues.size === 0;

            allCb.addEventListener('change', () => {
                if (allCb.checked) {
                    this.selectedValues.clear();
                    const checkboxes = this.optionsContainer.querySelectorAll('.custom-multiselect-option:not(.custom-multiselect-all) input[type="checkbox"]');
                    checkboxes.forEach(cb => { cb.checked = false; });
                    this.updateSummary();
                    if (typeof this.onChange === 'function') {
                        this.onChange(this.getValue());
                    }
                } else {
                    allCb.checked = true;
                }
            });

            const allSpan = document.createElement('span');
            allSpan.textContent = this.allOptionLabel;

            allLabel.appendChild(allCb);
            allLabel.appendChild(allSpan);
            this.optionsContainer.appendChild(allLabel);
        }

        if (this.optionsList.length === 0) {
            const emptyLabel = document.createElement('div');
            emptyLabel.style.padding = '6px 8px';
            emptyLabel.style.color = 'var(--color-text-maxcontrast)';
            emptyLabel.style.fontStyle = 'italic';
            emptyLabel.textContent = t('budget', 'No options available');
            this.optionsContainer.appendChild(emptyLabel);
            return;
        }

        this.optionsList.forEach(opt => {
            const label = document.createElement('label');
            label.className = 'custom-multiselect-option';

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = opt.value;
            cb.checked = this.selectedValues.has(opt.value);

            cb.addEventListener('change', () => {
                if (cb.checked) {
                    this.selectedValues.add(opt.value);
                } else {
                    this.selectedValues.delete(opt.value);
                }
                const allCb = this.optionsContainer.querySelector('.custom-multiselect-all input[type="checkbox"]');
                if (allCb) {
                    allCb.checked = this.selectedValues.size === 0;
                }
                this.updateSummary();
                if (typeof this.onChange === 'function') {
                    this.onChange(this.getValue());
                }
            });

            const span = document.createElement('span');
            span.textContent = opt.label;

            label.appendChild(cb);
            label.appendChild(span);
            this.optionsContainer.appendChild(label);
        });
    }

    /**
     * Get array of selected option values
     * @returns {Array<string>}
     */
    getValue() {
        return Array.from(this.selectedValues);
    }

    /**
     * Set selected values
     * @param {string|Array<string>} values
     * @param {boolean} [triggerChange=false]
     */
    setValue(values, triggerChange = false) {
        this.selectedValues.clear();

        if (values !== null && values !== undefined) {
            const valArray = Array.isArray(values) ? values : [values];
            valArray.forEach(val => {
                const strVal = String(val);
                if (this.optionsList.length === 0 || this.optionsList.some(opt => opt.value === strVal)) {
                    this.selectedValues.add(strVal);
                }
            });
        }

        // Sync checkboxes in DOM
        const checkboxes = this.optionsContainer.querySelectorAll('.custom-multiselect-option:not(.custom-multiselect-all) input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.checked = this.selectedValues.has(cb.value);
        });
        const allCb = this.optionsContainer.querySelector('.custom-multiselect-all input[type="checkbox"]');
        if (allCb) {
            allCb.checked = this.selectedValues.size === 0;
        }

        this.updateSummary();

        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange(this.getValue());
        }
    }

    updateSummary() {
        const count = this.selectedValues.size;
        if (typeof this.summaryFormatter === 'function') {
            this.summarySpan.textContent = this.summaryFormatter(count, Array.from(this.selectedValues), this.optionsList);
            return;
        }

        if (count === 0) {
            this.summarySpan.textContent = this.placeholder;
        } else if (count === 1) {
            const val = Array.from(this.selectedValues)[0];
            const opt = this.optionsList.find(o => o.value === val);
            this.summarySpan.textContent = opt ? opt.label : val;
        } else {
            this.summarySpan.textContent = t('budget', '{count} columns selected', { count });
        }
    }

    destroy() {
        if (this._documentClickHandler) {
            document.removeEventListener('click', this._documentClickHandler);
        }
        if (this.container) {
            this.container.innerHTML = '';
        }
    }
}

export default MultiSelect;

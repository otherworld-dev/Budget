import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

// Track all instances for cleanup
let activePickers = [];

/**
 * Get flatpickr config based on user settings
 * @param {object} settings - User settings object with date_format
 * @returns {object} flatpickr configuration
 */
function getConfig(settings) {
	const dateFormat = settings?.date_format || 'Y-m-d';
	return {
		dateFormat: 'Y-m-d',
		altInput: true,
		altFormat: dateFormat,
		allowInput: true,
		disableMobile: true,
	};
}

/**
 * Initialize flatpickr on all date inputs in the page.
 * Idempotent — destroys existing instances first.
 * @param {object} settings - User settings object
 */
export function initDatePickers(settings) {
	destroyAllDatePickers();

	const dateInputs = document.querySelectorAll('input[type="date"]');
	dateInputs.forEach(input => {
		const instance = flatpickr(input, getConfig(settings));
		activePickers.push(instance);
	});
}

/**
 * Initialize flatpickr on a single dynamically created element.
 * Returns the flatpickr instance.
 * @param {HTMLElement} element - The input element
 * @param {object} settings - User settings object
 * @param {object} extraOptions - Additional flatpickr options to merge
 * @returns {object} flatpickr instance
 */
export function initSingleDatePicker(element, settings, extraOptions = {}) {
	const config = { ...getConfig(settings), ...extraOptions };
	const instance = flatpickr(element, config);
	activePickers.push(instance);
	return instance;
}

/**
 * Set a date value on an input that may have a flatpickr instance.
 * @param {HTMLElement|string} elementOrId - DOM element or element ID
 * @param {string} dateString - Date string in Y-m-d format (or empty/null to clear)
 */
export function setDateValue(elementOrId, dateString) {
	const el = typeof elementOrId === 'string'
		? document.getElementById(elementOrId)
		: elementOrId;

	if (!el) return;

	if (el._flatpickr) {
		if (!dateString) {
			el._flatpickr.clear();
		} else {
			el._flatpickr.setDate(dateString, false);
		}
	} else {
		el.value = dateString || '';
	}
}

/**
 * Clear a date input.
 * @param {HTMLElement|string} elementOrId - DOM element or element ID
 */
export function clearDateValue(elementOrId) {
	setDateValue(elementOrId, null);
}

/**
 * Destroy all tracked flatpickr instances.
 */
export function destroyAllDatePickers() {
	activePickers.forEach(fp => {
		if (fp && typeof fp.destroy === 'function') {
			fp.destroy();
		}
	});
	activePickers = [];
}

/**
 * Form validation utilities
 * Pure validation functions without DOM manipulation
 */

/**
 * Validate a value against a set of validation rules
 * @param {string} value - Value to validate
 * @param {object} validationRules - Rules to apply
 * @returns {object} { isValid: boolean, error: string }
 */
export function validate(value, validationRules = {}) {
    const trimmedValue = typeof value === 'string' ? value.trim() : value;

    // Apply validation rules
    for (const [rule, ruleValue] of Object.entries(validationRules)) {
        let isValid = true;
        let errorMessage = '';

        switch (rule) {
            case 'required':
                if (ruleValue && !trimmedValue) {
                    isValid = false;
                    errorMessage = 'This field is required';
                }
                break;
            case 'minLength':
                if (trimmedValue && trimmedValue.length < ruleValue) {
                    isValid = false;
                    errorMessage = `Minimum ${ruleValue} characters required`;
                }
                break;
            case 'maxLength':
                if (trimmedValue && trimmedValue.length > ruleValue) {
                    isValid = false;
                    errorMessage = `Maximum ${ruleValue} characters allowed`;
                }
                break;
            case 'pattern':
                if (trimmedValue && !ruleValue.test(trimmedValue)) {
                    isValid = false;
                    errorMessage = validationRules.patternMessage || 'Invalid format';
                }
                break;
            case 'email':
                if (trimmedValue && ruleValue && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedValue)) {
                    isValid = false;
                    errorMessage = 'Invalid email address';
                }
                break;
            case 'min':
                if (trimmedValue && parseFloat(trimmedValue) < ruleValue) {
                    isValid = false;
                    errorMessage = `Minimum value is ${ruleValue}`;
                }
                break;
            case 'max':
                if (trimmedValue && parseFloat(trimmedValue) > ruleValue) {
                    isValid = false;
                    errorMessage = `Maximum value is ${ruleValue}`;
                }
                break;
        }

        if (!isValid) {
            return { isValid: false, error: errorMessage };
        }
    }

    return { isValid: true };
}

/**
 * Validate required field
 * @param {string} value - Value to validate
 * @param {string} fieldName - Field name for error message
 * @returns {object} { isValid: boolean, error: string }
 */
export function validateRequired(value, fieldName = 'This field') {
    if (!value || (typeof value === 'string' && value.trim() === '')) {
        return { isValid: false, error: `${fieldName} is required` };
    }
    return { isValid: true };
}

/**
 * Validate email address format
 * @param {string} email - Email to validate
 * @returns {object} { isValid: boolean, error: string }
 */
export function validateEmail(email) {
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        return { isValid: false, error: 'Invalid email address' };
    }
    return { isValid: true };
}

/**
 * Validate numeric value is within range
 * @param {number|string} value - Value to validate
 * @param {number} min - Minimum value (optional)
 * @param {number} max - Maximum value (optional)
 * @returns {object} { isValid: boolean, error: string }
 */
export function validateNumber(value, min = null, max = null) {
    const num = parseFloat(value);
    if (isNaN(num)) {
        return { isValid: false, error: 'Must be a valid number' };
    }
    if (min !== null && num < min) {
        return { isValid: false, error: `Minimum value is ${min}` };
    }
    if (max !== null && num > max) {
        return { isValid: false, error: `Maximum value is ${max}` };
    }
    return { isValid: true };
}

/**
 * Validate string length
 * @param {string} value - Value to validate
 * @param {number} minLength - Minimum length (optional)
 * @param {number} maxLength - Maximum length (optional)
 * @returns {object} { isValid: boolean, error: string }
 */
export function validateLength(value, minLength = null, maxLength = null) {
    const length = value ? value.length : 0;
    if (minLength !== null && length < minLength) {
        return { isValid: false, error: `Minimum ${minLength} characters required` };
    }
    if (maxLength !== null && length > maxLength) {
        return { isValid: false, error: `Maximum ${maxLength} characters allowed` };
    }
    return { isValid: true };
}

/**
 * Validate value matches pattern
 * @param {string} value - Value to validate
 * @param {RegExp} pattern - Regular expression pattern
 * @param {string} errorMessage - Error message to show
 * @returns {object} { isValid: boolean, error: string }
 */
export function validatePattern(value, pattern, errorMessage = 'Invalid format') {
    if (!value || !pattern.test(value)) {
        return { isValid: false, error: errorMessage };
    }
    return { isValid: true };
}

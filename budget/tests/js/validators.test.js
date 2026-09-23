/**
 * Form validation helpers (src/utils/validators.js).
 *
 * Pure functions returning { isValid, error? }. Messages go through
 * @nextcloud/l10n, mocked here to substitute {placeholders} so the asserted
 * text is what a user would read in English.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

import {
    validate,
    validateRequired,
    validateEmail,
    validateNumber,
    validateLength,
    validatePattern,
} from '../../src/utils/validators.js';

const ok = { isValid: true };
const fail = (error) => ({ isValid: false, error });

describe('validate', () => {
    it('passes a value when no rules are given', () => {
        expect(validate('anything')).toEqual(ok);
        expect(validate('')).toEqual(ok);
    });

    describe('required', () => {
        it('rejects an empty value', () => {
            expect(validate('', { required: true })).toEqual(fail('This field is required'));
        });

        it('rejects a value that is only whitespace', () => {
            expect(validate('   \t\n', { required: true })).toEqual(fail('This field is required'));
        });

        it('rejects null and undefined', () => {
            expect(validate(null, { required: true }).isValid).toBe(false);
            expect(validate(undefined, { required: true }).isValid).toBe(false);
        });

        it('accepts a non-empty value, including the string "0"', () => {
            expect(validate('x', { required: true })).toEqual(ok);
            expect(validate('0', { required: true })).toEqual(ok);
        });

        it('is switched off by required: false', () => {
            expect(validate('', { required: false })).toEqual(ok);
        });
    });

    describe('minLength / maxLength', () => {
        it('rejects a value shorter than minLength', () => {
            expect(validate('ab', { minLength: 3 })).toEqual(fail('Minimum 3 characters required'));
        });

        it('accepts a value exactly at minLength', () => {
            expect(validate('abc', { minLength: 3 })).toEqual(ok);
        });

        it('measures length after trimming', () => {
            expect(validate('  ab  ', { minLength: 3 }).isValid).toBe(false);
            expect(validate('  abcd  ', { maxLength: 4 })).toEqual(ok);
        });

        it('rejects a value longer than maxLength', () => {
            expect(validate('abcdef', { maxLength: 5 })).toEqual(fail('Maximum 5 characters allowed'));
        });

        it('accepts a value exactly at maxLength', () => {
            expect(validate('abcde', { maxLength: 5 })).toEqual(ok);
        });

        it('leaves an empty optional value alone', () => {
            expect(validate('', { minLength: 3 })).toEqual(ok);
        });
    });

    describe('pattern', () => {
        it('rejects a value that does not match, with the default message', () => {
            expect(validate('abc', { pattern: /^\d+$/ })).toEqual(fail('Invalid format'));
        });

        it('uses patternMessage when one is given', () => {
            expect(validate('abc', { pattern: /^\d+$/, patternMessage: 'Digits only' }))
                .toEqual(fail('Digits only'));
        });

        it('accepts a matching value', () => {
            expect(validate('123', { pattern: /^\d+$/ })).toEqual(ok);
        });

        it('skips an empty optional value', () => {
            expect(validate('', { pattern: /^\d+$/ })).toEqual(ok);
        });
    });

    describe('email', () => {
        it('accepts a plausible address, ignoring surrounding whitespace', () => {
            expect(validate(' user@example.org ', { email: true })).toEqual(ok);
        });

        it.each(['user', 'user@', '@example.org', 'user@example', 'us er@example.org', 'a@b@c.d'])(
            'rejects %s',
            (value) => {
                expect(validate(value, { email: true })).toEqual(fail('Invalid email address'));
            },
        );

        it('is switched off by email: false', () => {
            expect(validate('not-an-email', { email: false })).toEqual(ok);
        });
    });

    describe('min / max', () => {
        it('rejects a number below min', () => {
            expect(validate('4.99', { min: 5 })).toEqual(fail('Minimum value is 5'));
        });

        it('accepts a number at min', () => {
            expect(validate('5', { min: 5 })).toEqual(ok);
        });

        it('rejects a number above max', () => {
            expect(validate('100.01', { max: 100 })).toEqual(fail('Maximum value is 100'));
        });

        it('accepts a number at max', () => {
            expect(validate('100', { max: 100 })).toEqual(ok);
        });

        it('handles negative numbers', () => {
            expect(validate('-1', { min: 0 }).isValid).toBe(false);
            expect(validate('-1', { min: -5, max: 0 })).toEqual(ok);
        });

        it('checks the string "0" against min', () => {
            expect(validate('0', { min: 1 })).toEqual(fail('Minimum value is 1'));
        });

        // Numeric input: the "is there a value" guard reads 0 as empty, so the
        // min check is skipped and 0 passes a min of 1.
        it.fails('checks a numeric 0 against min (known bug: 0 is treated as empty)', () => {
            expect(validate(0, { min: 1 })).toEqual(fail('Minimum value is 1'));
        });
    });

    it('reports the first failing rule in the order the rules were given', () => {
        expect(validate('a', { minLength: 3, pattern: /^\d+$/ })).toEqual(fail('Minimum 3 characters required'));
        expect(validate('a', { pattern: /^\d+$/, minLength: 3 })).toEqual(fail('Invalid format'));
    });

    it('ignores rule names it does not know', () => {
        expect(validate('x', { unknownRule: true })).toEqual(ok);
    });

    it('passes a value that satisfies every rule', () => {
        expect(validate('Groceries', { required: true, minLength: 2, maxLength: 50, pattern: /^[A-Za-z]+$/ }))
            .toEqual(ok);
    });
});

describe('validateRequired', () => {
    it('rejects empty, whitespace-only, null and undefined', () => {
        ['', '   ', null, undefined].forEach(v => {
            expect(validateRequired(v).isValid).toBe(false);
        });
    });

    it('names the field in the message', () => {
        expect(validateRequired('', 'Account name')).toEqual(fail('Account name is required'));
    });

    it('falls back to a generic field name', () => {
        expect(validateRequired('')).toEqual(fail('This field is required'));
    });

    it('accepts non-empty strings and non-string values', () => {
        expect(validateRequired('x')).toEqual(ok);
        expect(validateRequired(42)).toEqual(ok);
        expect(validateRequired(['a'])).toEqual(ok);
    });
});

describe('validateEmail', () => {
    it('accepts a plausible address', () => {
        expect(validateEmail('user.name+tag@example.co.uk')).toEqual(ok);
    });

    it.each(['', null, undefined, 'user', 'user@example', 'user@@example.org', 'user @example.org'])(
        'rejects %s',
        (value) => {
            expect(validateEmail(value)).toEqual(fail('Invalid email address'));
        },
    );
});

describe('validateNumber', () => {
    it('accepts numbers and numeric strings', () => {
        expect(validateNumber(12)).toEqual(ok);
        expect(validateNumber('12.50')).toEqual(ok);
        expect(validateNumber('-3')).toEqual(ok);
        expect(validateNumber(0)).toEqual(ok);
    });

    it.each(['', 'abc', null, undefined, NaN])('rejects %s', (value) => {
        expect(validateNumber(value)).toEqual(fail('Must be a valid number'));
    });

    it('enforces min and max inclusively', () => {
        expect(validateNumber('5', 5, 10)).toEqual(ok);
        expect(validateNumber('10', 5, 10)).toEqual(ok);
        expect(validateNumber('4.99', 5, 10)).toEqual(fail('Minimum value is 5'));
        expect(validateNumber('10.01', 5, 10)).toEqual(fail('Maximum value is 10'));
    });

    it('treats a min or max of 0 as a real bound', () => {
        expect(validateNumber('-0.01', 0)).toEqual(fail('Minimum value is 0'));
        expect(validateNumber('0.01', null, 0)).toEqual(fail('Maximum value is 0'));
    });

    it('applies no bounds when none are given', () => {
        expect(validateNumber('-1e9')).toEqual(ok);
    });

    // parseFloat reads the leading digits and stops, so text with a number in
    // front is accepted as that number.
    it.fails('rejects text that merely starts with a number (known bug: parseFloat is lenient)', () => {
        expect(validateNumber('12abc')).toEqual(fail('Must be a valid number'));
    });
});

describe('validateLength', () => {
    it('accepts a value within the bounds, inclusively', () => {
        expect(validateLength('abc', 3, 3)).toEqual(ok);
        expect(validateLength('abcd', 1, 10)).toEqual(ok);
    });

    it('rejects a value that is too short', () => {
        expect(validateLength('ab', 3)).toEqual(fail('Minimum 3 characters required'));
    });

    it('rejects a value that is too long', () => {
        expect(validateLength('abcdef', null, 5)).toEqual(fail('Maximum 5 characters allowed'));
    });

    it('treats null and undefined as length 0', () => {
        expect(validateLength(null, 1)).toEqual(fail('Minimum 1 characters required'));
        expect(validateLength(undefined, null, 5)).toEqual(ok);
    });

    it('does not trim, unlike validate()', () => {
        expect(validateLength('  a  ', 5)).toEqual(ok);
    });

    it('applies no bounds when none are given', () => {
        expect(validateLength('x'.repeat(10000))).toEqual(ok);
    });
});

describe('validatePattern', () => {
    it('accepts a matching value', () => {
        expect(validatePattern('GB29', /^[A-Z]{2}\d{2}$/)).toEqual(ok);
    });

    it('rejects a non-matching value with the default message', () => {
        expect(validatePattern('gb29', /^[A-Z]{2}\d{2}$/)).toEqual(fail('Invalid format'));
    });

    it('rejects a non-matching value with a custom message', () => {
        expect(validatePattern('x', /^\d$/, 'One digit')).toEqual(fail('One digit'));
    });

    it('rejects an empty value even if the pattern would match it', () => {
        expect(validatePattern('', /^$/)).toEqual(fail('Invalid format'));
    });
});

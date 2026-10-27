/**
 * Amount boxes take a decimal comma (#442).
 *
 * They were number fields, and Safari refuses a comma in one, so `12,50`
 * in the Transactions amount filter read as nothing. They are text fields
 * now, tidied to a plain dot-decimal number as they are typed into.
 */

import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { normaliseAmountText, amountPattern, makeAmountInput, installAmountInputs } from '../../src/utils/amountInput.js';

const tidy = (raw, options) => normaliseAmountText(raw, options).value;

describe('normaliseAmountText', () => {
    it('reads a decimal comma as the decimal point', () => {
        expect(tidy('12,50')).toBe('12.50');
    });

    it('leaves a dot-decimal amount alone', () => {
        expect(tidy('12.50')).toBe('12.50');
        expect(tidy('-3.5')).toBe('-3.5');
    });

    it('treats the last separator as the decimal point and drops the others', () => {
        expect(tidy('1.250,50')).toBe('1250.50');
        expect(tidy('1,250.50')).toBe('1250.50');
        expect(tidy('1.250.000,00')).toBe('1250000.00');
    });

    it('ends up right when thousands separators are typed one key at a time', () => {
        for (const keys of ['1.250,50', '1,250.50', '1.250.000,5']) {
            let box = '';
            for (const key of keys) {
                box = tidy(box + key);
            }
            expect(Number(box)).toBe(Number(tidy(keys)));
        }
    });

    it('drops currency signs, spaces and apostrophes', () => {
        expect(tidy('€ 12,50')).toBe('12.50');
        expect(tidy("1'250.50")).toBe('1250.50');
        expect(tidy('12 500')).toBe('12500');
    });

    it('keeps a minus only first, and only where negatives are allowed', () => {
        expect(tidy('-12,5')).toBe('-12.5');
        expect(tidy('1-2')).toBe('12');
        expect(tidy('--5')).toBe('-5');
        expect(tidy('-12,5', { allowNegative: false })).toBe('12.5');
    });

    it('keeps the cursor beside what was typed', () => {
        expect(normaliseAmountText('1.250,5', { caret: 6 })).toEqual({ value: '1250.5', caret: 5 });
        expect(normaliseAmountText('12,50', { caret: 3 })).toEqual({ value: '12.50', caret: 3 });
        expect(normaliseAmountText('€12', { caret: 1 })).toEqual({ value: '12', caret: 0 });
    });
});

describe('amountPattern', () => {
    const matches = (attrs, value) => {
        const input = document.createElement('input');
        Object.entries(attrs).forEach(([k, v]) => input.setAttribute(k, v));
        return new RegExp(`^(?:${amountPattern(input)})$`).test(value);
    };

    it('wants a whole number, not a lone sign or point', () => {
        for (const ok of ['0', '12', '12.5', '12.', '.5', '-3']) {
            expect(matches({}, ok)).toBe(true);
        }
        for (const bad of ['-', '.', '-.']) {
            expect(matches({}, bad)).toBe(false);
        }
    });

    it('refuses a negative when min is zero', () => {
        expect(matches({ min: '0' }, '0')).toBe(true);
        expect(matches({ min: '0' }, '-1')).toBe(false);
    });

    it('wants more than zero when min is above zero', () => {
        for (const ok of ['0.01', '1', '10.50', '.5', '0.10']) {
            expect(matches({ min: '0.01' }, ok)).toBe(true);
        }
        for (const bad of ['0', '0.00', '0.', '.', '-1']) {
            expect(matches({ min: '0.01' }, bad)).toBe(false);
        }
    });
});

describe('installAmountInputs', () => {
    beforeAll(() => installAmountInputs());

    let input;
    let seen;
    beforeEach(() => {
        document.body.innerHTML = '<input type="text" inputmode="decimal" data-amount id="amt" min="0">';
        input = document.getElementById('amt');
        seen = null;
        input.addEventListener('input', () => { seen = input.value; });
    });

    const type = (value) => {
        input.focus();
        input.value = value;
        input.setSelectionRange(value.length, value.length);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    it('tidies the box before its own listeners read it', () => {
        type('12,50');
        expect(input.value).toBe('12.50');
        expect(seen).toBe('12.50');
    });

    it('keeps out a minus when min is zero', () => {
        type('-5');
        expect(input.value).toBe('5');
    });

    it('sets a pattern so the form checks a complete number', () => {
        type('12,5');
        expect(input.pattern).toBe(amountPattern(input));
    });

    it('also tidies boxes built in JS', () => {
        const built = makeAmountInput(document.createElement('input'));
        document.body.appendChild(built);
        built.value = '7,25';
        built.dispatchEvent(new Event('input', { bubbles: true }));
        expect(built.type).toBe('text');
        expect(built.value).toBe('7.25');
    });

    it('leaves other inputs alone', () => {
        document.body.innerHTML = '<input type="text" id="plain">';
        const plain = document.getElementById('plain');
        plain.value = '12,50';
        plain.dispatchEvent(new Event('input', { bubbles: true }));
        expect(plain.value).toBe('12,50');
    });
});

/**
 * Settings > Decimal Places = 0.
 *
 * `parseInt('0') || 2` read the 0 as unset, so every amount kept two
 * decimals while the settings preview showed none. Amounts now show
 * without decimals, while the amount fields still take pence: the setting
 * is about display, and a field that took whole numbers only would refuse
 * 12.99.
 */

import { describe, it, expect } from 'vitest';
import { formatCurrency, currencyStep } from '../../src/utils/formatters.js';

const settings = (decimals) => ({ number_format_decimals: decimals, number_format_decimal_sep: '.', number_format_thousands_sep: ',' });

describe('Decimal Places', () => {
    it('shows amounts without decimals when set to 0', () => {
        expect(formatCurrency(386160.56, 'GBP', settings('0'))).toBe('£386,161');
        expect(formatCurrency(-1450, 'USD', settings(0))).toBe('-$1,450');
    });

    it('keeps two decimals when unset, and three when chosen', () => {
        expect(formatCurrency(12.5, 'GBP', settings(undefined))).toBe('£12.50');
        expect(formatCurrency(12.5, 'GBP', settings(''))).toBe('£12.50');
        expect(formatCurrency(12.5, 'GBP', settings('3'))).toBe('£12.500');
    });

    it('leaves a currency\'s own precision alone', () => {
        expect(formatCurrency(0.5, 'BTC', settings('0'))).toBe('0.50000000 BTC');
    });

    it('still lets the amount fields take pence', () => {
        expect(currencyStep('GBP', settings('0'))).toBe('0.01');
    });
});

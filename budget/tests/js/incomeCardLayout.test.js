/**
 * Income cards give the name its room and line the amounts up (#399).
 *
 * The card's header took what the date and the buttons left over, so at
 * 1440px 'Gutschrift Swisscom Abo Leon' wrapped onto two lines beside its
 * amount, and each card's amount started somewhere else (931, 528, 690 and
 * 771px). Below 1200px the header now has the card's first line to itself,
 * so every amount ends at the card's right edge. From 1200px the name gets
 * a column of the same width on every card and the amount a right-aligned
 * one, and a long date wraps instead of the name.
 *
 * jsdom does no layout: this checks the rules the layout rests on. Measured
 * in Chromium against the reporter's four incomes: at 1440px every name on
 * one line and every amount ending at the same x, every card one row high;
 * at 800px the same, with no sideways scroll.
 */

import { describe, it, expect } from 'vitest';
import { RULES } from './helpers/stylesheet.js';

/** The declarations for exactly this selector, outside media queries or in the one named */
function rule(selector, media = null) {
    return Object.assign({}, ...RULES
        .filter(r => (media === null ? !r.media : (r.media || '').includes(media)))
        .filter(r => r.selectors.includes(selector))
        .map(r => r.declarations));
}

describe('an income card', () => {
    it('gives the header the first line below 1200px', () => {
        expect(rule('.income-header').flex).toBe('1 1 100%');
        // The header still spreads the name and the amount to its two ends
        expect(rule('.income-header')['justify-content']).toBe('space-between');
    });

    it('from 1200px gives the name and the amount columns of their own', () => {
        expect(rule('.income-header', 'min-width: 1200px').flex).toBe('0 0 auto');
        expect(rule('.income-info', 'min-width: 1200px').width).toBe('16rem');
        expect(rule('.income-amount', 'min-width: 1200px')).toMatchObject({ 'min-width': '8.5rem', 'text-align': 'right' });
    });

    it('from 1200px lets the date wrap rather than the name', () => {
        expect(rule('.income-details', 'min-width: 1200px')).toMatchObject({ flex: '1 1 14rem', 'min-width': '0' });
        expect(rule('.income-next-date', 'min-width: 1200px')['min-width']).toBe('0');
    });

    it('never wraps the amount itself', () => {
        expect(rule('.income-amount')['white-space']).toBe('nowrap');
    });
});

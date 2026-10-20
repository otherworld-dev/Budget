/**
 * The date on an income or bill card that has to wrap (#399, browser
 * round 3).
 *
 * At 1440px an overdue income's 'Received 2026-09-28, next expected
 * 2026-09-29' wrapped after 'next', splitting the phrase. Measured in
 * Chromium on the reporter's data, the row has no room to give it: the
 * text takes 321px on one line, and beside the name and amount columns
 * that line the cards up (32a91518), the status pill and Mark Received,
 * Skip, Edit and Delete, the date gets 301px with its pill. So the date
 * now wraps in balanced lines, which breaks it between its two halves:
 * 'Received 2026-09-28,' over 'next expected 2026-09-29'. Bills' 'Paid …,
 * next due …' does the same.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { declarationsFor } from './helpers/stylesheet.js';

afterEach(() => {
    document.body.innerHTML = '';
});

describe.each([
    ['an income card', '<div class="income-card overdue"><div class="income-details"><div class="income-next-date"><span class="icon-calendar-dark"></span>Received 2026-09-28, next expected 2026-09-29</div></div></div>', '.income-next-date'],
    ['a bill card', '<div class="bill-card"><div class="bill-details"><div class="bill-due-date"><span class="icon-calendar-dark"></span>Paid 2026-09-24, next due 2026-10-24</div></div></div>', '.bill-due-date'],
])('%s', (name, markup, selector) => {
    it('wraps its date in balanced lines, between its two halves', () => {
        document.body.innerHTML = markup;
        expect(declarationsFor(document.querySelector(selector))['text-wrap']).toBe('balance');
    });
});

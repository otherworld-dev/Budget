/**
 * The calendar icon before a date can be seen in both themes (#399).
 *
 * Bills, Income, Transfers and Savings put Nextcloud's `icon-calendar`
 * before their dates, and so did the summary cards. That is the white icon
 * meant for coloured headers: white on a white card in the light theme, and
 * black on near-black (about 1.2:1) in the dark one. `icon-calendar-dark` is
 * the one that follows the theme.
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const FILES = [
    'src/modules/bills/BillsModule.js',
    'src/modules/income/IncomeModule.js',
    'src/modules/transfers/TransfersModule.js',
    'src/modules/savings/SavingsModule.js',
    'templates/index.php',
];

/** The class lists that hold the white calendar icon */
function whiteCalendarIcons(file) {
    const source = fs.readFileSync(path.join(root, file), 'utf8');
    return [...source.matchAll(/class="([^"]*)"/g)]
        .map(match => match[1])
        .filter(classes => classes.split(/\s+/).includes('icon-calendar'));
}

describe('calendar icons', () => {
    it.each(FILES)('%s uses the theme-following icon', (file) => {
        expect(whiteCalendarIcons(file)).toEqual([]);
    });

    it('still shows one on the cards and the summaries', () => {
        for (const file of FILES) {
            const source = fs.readFileSync(path.join(root, file), 'utf8');
            expect(source, file).toContain('icon-calendar-dark');
        }
    });
});

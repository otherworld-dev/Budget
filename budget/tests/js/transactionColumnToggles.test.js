/**
 * Configure columns on the Transactions list (#399, browser round 3): the
 * Balance checkbox did nothing. main.js wired a change handler for every
 * column but Balance, from a list of its own that had fallen behind the
 * setting's keys. The handlers are now wired from the setting's keys
 * (TRANSACTION_COLUMN_CLASSES), so a column with a checkbox can't be left
 * out again.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    TRANSACTION_COLUMN_CLASSES,
    applyTransactionColumnVisibility,
    wireTransactionColumnToggles,
} from '../../src/utils/transactionColumns.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const TEMPLATE = fs.readFileSync(path.resolve(here, '../../templates/index.php'), 'utf8')
    .replace(/<\?php[\s\S]*?\?>/g, '');
const MAIN = fs.readFileSync(path.resolve(here, '../../src/main.js'), 'utf8');

const ALL_SHOWN = { date: true, description: true, vendor: true, category: true, tags: true, amount: true, balance: true, account: true };

function untick(id) {
    const box = document.getElementById(id);
    box.checked = false;
    box.dispatchEvent(new Event('change', { bubbles: true }));
}

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Configure columns', () => {
    it('has a checkbox for every column of the setting', () => {
        for (const key of Object.keys(TRANSACTION_COLUMN_CLASSES)) {
            expect(document.getElementById(`col-toggle-${key}`), key).not.toBeNull();
        }
    });

    it('reports a change of the Balance checkbox', () => {
        const onToggle = vi.fn();
        wireTransactionColumnToggles(document, onToggle);

        untick('col-toggle-balance');

        expect(onToggle).toHaveBeenCalledWith('balance', false);
    });

    it('reports a change of every column\'s checkbox', () => {
        const onToggle = vi.fn();
        wireTransactionColumnToggles(document, onToggle);

        for (const key of Object.keys(TRANSACTION_COLUMN_CLASSES)) {
            untick(`col-toggle-${key}`);
        }

        expect(onToggle.mock.calls.map(([key]) => key).sort())
            .toEqual(Object.keys(TRANSACTION_COLUMN_CLASSES).sort());
    });

    it('hides the Balance column and marks the table hides-balance when unticked', () => {
        const table = document.getElementById('transactions-table');
        const visibility = { ...ALL_SHOWN };
        wireTransactionColumnToggles(document, (key, shown) => {
            visibility[key] = shown;
            applyTransactionColumnVisibility(table, visibility, { hasBalances: true });
        });
        applyTransactionColumnVisibility(table, visibility, { hasBalances: true });
        expect(table.classList.contains('hides-balance')).toBe(false);

        untick('col-toggle-balance');

        expect(table.classList.contains('hides-balance')).toBe(true);
        table.querySelectorAll('th.balance-column, td.balance-column').forEach(cell => {
            expect(cell.style.display).toBe('none');
        });
    });

    it('is what the app wires the checkboxes with', () => {
        expect(MAIN).toMatch(/wireTransactionColumnToggles\(/);
        expect(MAIN).not.toMatch(/\['date', 'description', 'vendor', 'category', 'tags', 'amount', 'account'\]/);
    });
});

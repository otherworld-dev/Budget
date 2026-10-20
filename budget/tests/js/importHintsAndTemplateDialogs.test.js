/**
 * Import's Map Columns step and template dialogs (#399, browser round 2, csv
 * bugs 7 and 8).
 *
 * - The CSV-option hints ("Change if columns are not detected correctly")
 *   had no style: 15px main-colour text flush on the next field's label, so
 *   each read as the hint of the field below.
 * - The checklists (Description, Notes, Vendor/Payee, Reference) were 3px
 *   wider than the selects: Nextcloud's 3px end margin shrank the selects.
 * - Save Import Template and Manage Import Templates put the x on its own
 *   row under the title.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { declarationsFor } from './helpers/stylesheet.js';
import { cascadedDeclarations } from './helpers/cascade.js';

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
)
    .replace(/<\?php\s+p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\);\s*\?>/g, (m, text) => text.replace(/\\'/g, '\''))
    .replace(/<\?php[\s\S]*?\?>/g, '');

const HINT_STYLE = ['font-size', 'color', 'line-height'];
const pick = (declarations, keys) => Object.fromEntries(keys.map(k => [k, declarations[k]]));

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Map Columns hints', () => {
    const formText = () => {
        const el = document.createElement('small');
        el.className = 'form-text';
        document.body.appendChild(el);
        return pick(declarationsFor(el), HINT_STYLE);
    };

    it('are styled like the app\'s other hints', () => {
        const hints = [
            ...document.querySelectorAll('#import-encoding-options .hint, #csv-options .hint'),
            ...document.querySelectorAll('.mapping-fields .hint'),
        ];
        expect(hints.length).toBeGreaterThanOrEqual(6);
        expect(formText()).toEqual({ 'font-size': '13px', color: 'var(--color-text-maxcontrast)', 'line-height': '1.4' });

        for (const hint of hints) {
            expect(pick(declarationsFor(hint), HINT_STYLE), hint.textContent).toEqual(formText());
        }
    });

    it('sit under their own field, with space before the next one', () => {
        const delimiterHint = document.querySelector('#csv-options .hint');

        expect(declarationsFor(delimiterHint).margin).toBe('5px 0 0');
        for (const options of document.querySelectorAll('.csv-options')) {
            expect(declarationsFor(options)['margin-bottom'], options.id).toBe('15px');
        }
    });

    it('apply to the Save Import Template dialog\'s hint too', () => {
        const hint = document.querySelector('#import-save-template-modal .form-group .hint');

        expect(pick(declarationsFor(hint), HINT_STYLE)).toEqual(formText());
    });
});

describe('Map Columns controls', () => {
    it('run the selects and the checklists to the same right edge', () => {
        const select = document.getElementById('csv-delimiter');
        const toggle = document.createElement('button');
        toggle.className = 'custom-multiselect-toggle';
        document.getElementById('map-description').appendChild(toggle);

        expect(declarationsFor(select)['margin-inline-end']).toBe('0');
        expect(declarationsFor(toggle)['margin-inline-end']).toBe('0');
        expect(declarationsFor(toggle).width).toBe('100%');
    });
});

describe('the dialogs with a title row', () => {
    const ids = () => [...document.querySelectorAll('.modal > .modal-content > .modal-header')]
        .map(header => header.closest('.modal').id);

    it('are the import template dialogs and the others built the same way', () => {
        expect(ids()).toEqual(expect.arrayContaining(['import-save-template-modal', 'import-templates-modal', 'add-to-goal-modal', 'pension-balance-modal']));
    });

    it('put the title and its x on one row', () => {
        for (const id of ids()) {
            const header = document.querySelector(`#${id} .modal-header`);
            const title = header.querySelector('h3');
            const close = header.querySelector('.modal-close');

            expect(declarationsFor(header), id).toMatchObject({ display: 'flex', 'justify-content': 'space-between', 'align-items': 'center' });
            expect(declarationsFor(title).margin, id).toBe('0');
            if (!close) {
                continue; // a title row with no x (Add Tag)
            }
            // Most of them also carry .cancel-btn, whose boxed look this beats
            expect(cascadedDeclarations(close, 'light'), id).toMatchObject({ background: 'none', border: 'none', 'flex-shrink': '0', 'min-height': '0', padding: '0' });
            // Colours from Nextcloud's theme variables, so both themes follow
            expect(cascadedDeclarations(close, 'light').color, id).toBe('var(--color-text-maxcontrast)');
            // The title comes first, so the x sits at the right
            expect(header.firstElementChild, id).toBe(title);
            expect(header.lastElementChild, id).toBe(close);
        }
    });
});

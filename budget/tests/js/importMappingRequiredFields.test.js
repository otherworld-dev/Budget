/**
 * Import's Map Columns step (#399). The hints under Date, Amount and
 * Description ("Or use separate income/expense columns below", "Tick more
 * than one column…") came out bold red like validation errors, even with the
 * field filled in, and those three fields sat 4px right of the others. The
 * stylesheet's `.required` rule, meant for the red asterisk, also matched the
 * `mapping-field required` wrapper around each of those fields.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { rulesMatching, declarationsFor } from './helpers/stylesheet.js';

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
)
    .replace(/<\?php\s+p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\);\s*\?>/g, (m, text) => text.replace(/\\'/g, '\''))
    .replace(/<\?php[\s\S]*?\?>/g, '');

const pick = (declarations, keys) => Object.fromEntries(keys.filter(k => k in declarations).map(k => [k, declarations[k]]));
const TEXT_AND_PLACE = ['color', 'font-weight', 'margin', 'margin-left', 'padding', 'padding-left'];

describe('Map Columns required fields', () => {
    let fields;

    beforeAll(() => {
        document.body.innerHTML = TEMPLATE;
        fields = [...document.querySelectorAll('#import-step-2 .mapping-field.required')];
    });

    it('are Date, Amount and Description', () => {
        expect(fields.map(f => f.dataset.mapField)).toEqual(['date', 'amount', 'description']);
    });

    it('get no style from being required', () => {
        for (const field of fields) {
            const asRequired = rulesMatching(field).map(r => r.selectors.join(', '));
            field.classList.remove('required');
            const asOptional = rulesMatching(field).map(r => r.selectors.join(', '));
            field.classList.add('required');

            expect(asRequired, field.dataset.mapField).toEqual(asOptional);
        }
    });

    it('line up with the optional fields, and keep their hints in the hint style', () => {
        const optional = document.querySelector('#import-step-2 .mapping-field[data-map-field="notes"]');
        const expected = pick(declarationsFor(optional), TEXT_AND_PLACE);

        for (const field of fields) {
            expect(pick(declarationsFor(field), TEXT_AND_PLACE), field.dataset.mapField).toEqual(expected);
        }
        // The two hints the report saw in red: theirs is the optional fields' style
        for (const name of ['amount', 'description']) {
            const hint = document.querySelector(`#import-step-2 [data-map-field="${name}"] > .hint`);
            expect(declarationsFor(hint), name).toEqual(declarationsFor(optional.querySelector('.hint')));
        }
    });

    it('still carry a red asterisk', () => {
        for (const field of fields) {
            const asterisk = field.querySelector('label .required');

            expect(asterisk.textContent).toBe('*');
            expect(declarationsFor(asterisk)).toMatchObject({ color: 'var(--color-error)', 'font-weight': '600', 'margin-left': '4px' });
        }
    });
});

describe('Required-field asterisks elsewhere', () => {
    it('are all still red', () => {
        document.body.innerHTML = TEMPLATE;
        const asterisks = [...document.querySelectorAll('.required')].filter(el => !el.classList.contains('mapping-field'));

        expect(asterisks.length).toBeGreaterThan(20);
        const notRed = asterisks.filter(el => declarationsFor(el).color !== 'var(--color-error)')
            .map(el => el.closest('label')?.getAttribute('for') || el.parentElement.outerHTML.slice(0, 80));
        expect(notRed).toEqual([]);
    });
});

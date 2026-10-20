/**
 * A required field's asterisk (#399, browser round 3). The pension dialogs
 * (Update Balance, contribution, withdrawal, recurring contribution) wrote
 * it as plain label text, so it was grey where every other form's is red.
 * Each one is now a span.required, which style.css colours.
 *
 * The markup is the real page template, with its PHP taken out.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { declarationsFor } from './helpers/stylesheet.js';

const TEMPLATE = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../templates/index.php'),
    'utf8'
)
    .replace(/<\?php\s+p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\);\s*\?>/g, (m, text) => text.replace(/\\'/g, '\''))
    .replace(/<\?php[\s\S]*?\?>/g, '');

beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
});

afterEach(() => {
    document.body.innerHTML = '';
});

/** A label's own text, outside any element inside it */
const ownText = (label) => [...label.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent).join('');

describe('required-field asterisks', () => {
    it('are never plain label text', () => {
        const plain = [...document.querySelectorAll('label')]
            .filter(label => ownText(label).includes('*'))
            .map(label => label.getAttribute('for') || label.textContent.trim());
        expect(plain).toEqual([]);
    });

    it.each(['snapshot-balance', 'snapshot-date'])('mark Update Balance\'s %s in the error colour', (id) => {
        const marker = document.querySelector(`#pension-balance-modal label[for="${id}"] .required`);
        expect(marker?.textContent).toBe('*');
        expect(declarationsFor(marker).color).toBe('var(--color-error)');
    });
});

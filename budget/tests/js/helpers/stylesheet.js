/**
 * css/style.css as rules a test can hold against the DOM. jsdom applies no
 * stylesheet itself, so a test asks which rules' selectors match an element
 * and what they declare.
 *
 * Rules inside a media query keep it in `media`; the matching helpers leave
 * them out, as jsdom has no viewport or colour scheme to judge them by.
 * Specificity isn't modelled: the helpers are for comparing what two
 * elements are given, or whether a rule reaches an element at all.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CSS = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../css/style.css'),
    'utf8'
).replace(/\/\*[\s\S]*?\*\//g, '');

/** A rule body's declarations, split on the semicolons outside quotes and brackets. */
function declarationsOf(body) {
    const out = {};
    let depth = 0;
    let quote = null;
    let start = 0;
    const take = (end) => {
        const part = body.slice(start, end);
        const colon = part.indexOf(':');
        if (colon > 0) out[part.slice(0, colon).trim()] = part.slice(colon + 1).trim();
    };
    for (let i = 0; i < body.length; i++) {
        const c = body[i];
        if (quote) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === '\'') {
            quote = c;
        } else if (c === '(') {
            depth++;
        } else if (c === ')') {
            depth--;
        } else if (c === ';' && depth === 0) {
            take(i);
            start = i + 1;
        }
    }
    take(body.length);
    return out;
}

function parse(text, media = null, out = []) {
    let i = 0;
    while (i < text.length) {
        const open = text.indexOf('{', i);
        if (open === -1) break;
        const head = text.slice(i, open).trim();
        let depth = 1;
        let j = open + 1;
        while (j < text.length && depth) {
            if (text[j] === '{') depth++;
            else if (text[j] === '}') depth--;
            j++;
        }
        const body = text.slice(open + 1, j - 1);
        if (head.startsWith('@media')) {
            parse(body, head, out);
        } else if (!head.startsWith('@')) {
            out.push({
                index: out.length,
                media,
                selectors: head.split(',').map(s => s.trim().replace(/\s+/g, ' ')),
                declarations: declarationsOf(body),
            });
        }
        i = j;
    }
    return out;
}

/** Every rule in source order: {index, media, selectors, declarations}. */
export const RULES = parse(CSS);

function matches(el, selector) {
    try {
        return el.matches(selector);
    } catch {
        // A selector jsdom can't evaluate (a pseudo-element, say) styles no element here
        return false;
    }
}

/** The rules outside media queries with a selector that matches `el`, in source order. */
export function rulesMatching(el) {
    return RULES.filter(rule => !rule.media && rule.selectors.some(s => matches(el, s)));
}

/**
 * Of the rules matching `el`, those whose matching selector names `part`
 * (a class such as '.overdue'): what that class adds to the element.
 */
export function declarationsNaming(el, part) {
    return Object.assign({}, ...RULES
        .filter(rule => !rule.media && rule.selectors.some(s => s.includes(part) && matches(el, s)))
        .map(rule => rule.declarations));
}

/** What the rules matching `el` declare, later rules winning. */
export function declarationsFor(el) {
    return Object.assign({}, ...rulesMatching(el).map(rule => rule.declarations));
}

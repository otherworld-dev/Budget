/**
 * No native browser dialogs anywhere in the app (#381).
 *
 * window.confirm/prompt/alert can be switched off by the browser for the whole
 * page — after a few in a row Chrome and Firefox offer "Prevent this page from
 * creating additional dialogs", and from then on every call returns
 * immediately, drawing nothing. Any destructive action still gated behind one
 * silently stops working with no dialog, no error and nothing in the console.
 *
 * This is a source-level guard rather than a behavioural test because the
 * failure it prevents is a *missed call site*, and there are sixty-odd of them.
 * The original migration grep excluded `window.`-prefixed calls and left two
 * window.prompt() calls behind; nothing in the unit tests or eslint noticed,
 * because each one is perfectly valid code on its own.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const SRC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../src');

/** The helper module is allowed to name them — it documents what it replaces. */
const ALLOWED = ['utils/dialogs.js'];

function jsFiles(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) jsFiles(full, out);
        else if (entry.name.endsWith('.js')) out.push(full);
    }
    return out;
}

/** Strip comments so prose about confirm() does not read as a call. */
function stripComments(code) {
    return code
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

// Matches confirm(, prompt(, alert(, and the window./globalThis. forms, while
// leaving confirmDialog( / promptDialog( / alertDialog( and .alert( alone.
const NATIVE = /(?:^|[^\w.$])(?:(?:window|globalThis|self)\s*\.\s*)?(confirm|prompt|alert)\s*\(/g;

describe('native browser dialogs', () => {
    const files = jsFiles(SRC);

    it('finds source files to check', () => {
        expect(files.length).toBeGreaterThan(10);
    });

    it('are not called anywhere in src/', () => {
        const offenders = [];

        for (const file of files) {
            const rel = path.relative(SRC, file).replace(/\\/g, '/');
            if (ALLOWED.includes(rel)) continue;

            const code = stripComments(fs.readFileSync(file, 'utf8'));
            code.split('\n').forEach((line, i) => {
                NATIVE.lastIndex = 0;
                let m;
                while ((m = NATIVE.exec(line)) !== null) {
                    offenders.push(`${rel}:${i + 1}  ${line.trim().slice(0, 100)}`);
                }
            });
        }

        expect(offenders).toEqual([]);
    });

    it('would catch a window-prefixed call, the form that slipped through', () => {
        // Guard on the guard: the original migration regex excluded anything
        // preceded by a dot, which silently skipped window.prompt().
        const sample = "const name = window.prompt(t('budget', 'Name this report:'));";
        NATIVE.lastIndex = 0;
        expect(NATIVE.test(sample)).toBe(true);
    });

    it('does not mistake the dialog helpers for native calls', () => {
        for (const sample of [
            'await confirmDialog(msg, { destructive: true })',
            'const v = await promptDialog(msg)',
            'alertDialog(msg)',
        ]) {
            NATIVE.lastIndex = 0;
            expect(NATIVE.test(sample)).toBe(false);
        }
    });
});

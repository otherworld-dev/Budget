/**
 * No string in the .pot may carry the javascript-format flag (#415).
 *
 * xgettext marks a JavaScript string as javascript-format whenever it spots
 * something that looks like a printf directive, and "{percent}% of" reads as
 * "% o" to it. Weblate then checks every translation against that: German
 * "{percent}% der" has a "% d" instead, so translators get a JavaScript-Format
 * error on a perfectly good translation. Our t() only ever fills in {name}
 * placeholders, so the flag is never right.
 *
 * The fix is a comment straight before the call, which xgettext reads:
 *
 *     /* xgettext:no-javascript-format *\/ t('budget', '{percent}% over', ...)
 *
 * This only fails once the .pot is regenerated, which is when it matters.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const POT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../translationfiles/templates/budget.pot');

describe('budget.pot format flags', () => {
    it('has no javascript-format strings', () => {
        const entries = fs.readFileSync(POT, 'utf8').split(/\r?\n\r?\n/);
        const flagged = entries
            .filter(entry => /^#,.*(?<!no-)javascript-format/m.test(entry))
            .map(entry => (entry.match(/^#: (.*)$/m) || [])[1]);

        expect(flagged, 'add /* xgettext:no-javascript-format */ before these t() calls').toEqual([]);
    });
});

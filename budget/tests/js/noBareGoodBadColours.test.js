/**
 * Every good/bad colour in the front end has to go through semanticColour()
 * (or a CSS variable) so the per-user colour scheme reaches it (#432). A bare
 * literal here would stay green/red under Colour-blind and Custom.
 */

import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';

const SRC = resolve(__dirname, '../../src');

const GOOD_BAD = [
    '#46ba61', '#e9322d', '#2e7d32', '#c62828', '#4caf50', '#43a047',
    'rgba(46, 125, 50', 'rgba(198, 40, 40', 'rgba(70, 186, 97',
    'rgba(233, 50, 45', 'rgba(76, 175, 80',
];

/**
 * Lines that name a green or red but do not mean good/bad. Matched by text,
 * not line number, so the list survives edits above them.
 */
const NOT_GOOD_BAD = [
    // AssetsModule: colour of the "vehicle"/"property"-style asset type, not a gain or loss.
    { file: 'modules/assets/AssetsModule.js', text: "color: '#2e7d32'," },
    // ReportsModule: the categorical palette that colours category slices.
    { file: 'modules/reports/ReportsModule.js', text: "'#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336'," },
    // TagSetsModule: the default colour offered for a new tag.
    { file: 'modules/tagsets/TagSetsModule.js', text: "tag.color || '#4CAF50'" },
];

function walk(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return walk(path);
        return path.endsWith('.js') ? [path] : [];
    });
}

describe('good/bad colours in src', () => {
    it('are never a bare literal outside semanticColour()', () => {
        const offenders = [];
        for (const path of walk(SRC)) {
            const file = relative(SRC, path).split(sep).join('/');
            if (file === 'utils/amountColours.js') continue;
            readFileSync(path, 'utf8').split('\n').forEach((line, index) => {
                const lower = line.toLowerCase();
                if (!GOOD_BAD.some((literal) => lower.includes(literal))) return;
                if (line.includes('semanticColour(')) return;
                if (NOT_GOOD_BAD.some((entry) => entry.file === file && line.includes(entry.text))) return;
                offenders.push(`${file}:${index + 1}: ${line.trim()}`);
            });
        }

        expect(offenders).toEqual([]);
    });
});

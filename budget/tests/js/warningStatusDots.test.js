/**
 * The amber "warning" status dots on accounts.
 *
 * Nextcloud core ships `#body-user .warning { background-color:
 * rgba(var(--color-warning-rgb), 0.2); ... }`. An id plus a class outranks
 * the app's `.account-status-dot.warning` and `.health-indicator.warning`,
 * so both dots came out as a faint 20% tint in every colour scheme instead
 * of the warning colour. Each needs a rule that matches the core one's
 * specificity and comes later in the cascade.
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const CSS = fs.readFileSync(path.resolve(__dirname, '../../css/style.css'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

function ruleBody(selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\#]/g, '\\$&');
    const match = CSS.match(new RegExp(`(^|[},])\\s*${escaped}\\s*\\{([^}]*)\\}`, 'm'));
    return match ? match[2] : null;
}

describe('warning status dots', () => {
    for (const dot of ['.account-status-dot.warning', '.health-indicator.warning']) {
        it(`${dot} beats Nextcloud's #body-user .warning`, () => {
            const body = ruleBody(`#body-user ${dot}`);
            expect(body).not.toBeNull();
            expect(body).toMatch(/background(-color)?:\s*var\(--budget-scheme-warning-fill, var\(--color-warning\)\)/);
            expect(body).toMatch(/margin:\s*0/);
            expect(body).toMatch(/padding:\s*0/);
            expect(body).toMatch(/border-radius:\s*50%/);
        });
    }
});

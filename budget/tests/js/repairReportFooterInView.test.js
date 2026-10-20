/**
 * Repair Data's report keeps Close and Fix Selected Issues in view (#399).
 *
 * The report scrolled as one box with its footer inside: at 1440x900 with
 * two categories it was 855px of content in an 810px box, and only the top
 * 8px of Close and Fix Selected Issues showed until the report was
 * scrolled, which then took the title out of view. The header and footer
 * now stay put and only the body scrolls, as in the Link to bill dialog.
 *
 * jsdom does no layout, so this checks what the layout rests on: the rules
 * that reach the report's box, body, header and footer.
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { declarationsFor } from './helpers/stylesheet.js';

let parts;

beforeAll(() => {
    document.body.innerHTML = `
        <div id="repair-data-modal" class="budget-modal-overlay">
            <div class="budget-modal" style="max-width: 700px;">
                <div class="budget-modal-header"><h2>Data Integrity Report</h2></div>
                <div class="budget-modal-body"><div class="repair-category"></div></div>
                <div class="budget-modal-footer"><button class="cancel-btn">Close</button><button class="repair-btn primary">Fix Selected Issues</button></div>
            </div>
        </div>
        <div class="budget-modal-overlay" id="some-other-dialog">
            <div class="budget-modal"><div class="budget-modal-body"></div></div>
        </div>`;
    const report = document.querySelector('#repair-data-modal');
    parts = {
        box: report.querySelector('.budget-modal'),
        header: report.querySelector('.budget-modal-header'),
        body: report.querySelector('.budget-modal-body'),
        footer: report.querySelector('.budget-modal-footer'),
    };
});

describe('the Repair Data report', () => {
    it('lays its box out as a column that doesn\'t scroll itself, within the window', () => {
        const box = declarationsFor(parts.box);

        expect(box.display).toBe('flex');
        expect(box['flex-direction']).toBe('column');
        expect(box.overflow).toBe('hidden');
        expect(box['max-height']).toBe('90vh');
    });

    it('scrolls only its body', () => {
        const body = declarationsFor(parts.body);

        expect(body['overflow-y']).toBe('auto');
        expect(body['min-height']).toBe('0');
        expect(body.flex).toBe('1 1 auto');
    });

    it('never shrinks the header or the footer with its buttons', () => {
        expect(declarationsFor(parts.header).flex).toBe('0 0 auto');
        expect(declarationsFor(parts.footer).flex).toBe('0 0 auto');
    });

    it('leaves the other dialogs as they were', () => {
        const other = declarationsFor(document.querySelector('#some-other-dialog .budget-modal'));

        expect(other.display).toBeUndefined();
        expect(other['overflow-y']).toBe('auto');
    });
});

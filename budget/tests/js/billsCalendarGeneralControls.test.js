/**
 * The Reports page's general controls and the Bills Calendar's own (#399).
 *
 * The general Generate Report button runs the chosen report again, and for
 * the Bills Calendar that rebuilt its Year and Account selects, so a user
 * looking at 2027 for one account was put back on this year and All
 * Accounts. The calendar keeps the user's choices now.
 *
 * Period, the Account checklist and the tag filter above don't scope the
 * calendar (it has its own Year and Account), yet stayed on screen and did
 * nothing. They are hidden while the Bills Calendar is the chosen report,
 * and come back for every other.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { rulesMatching } from './helpers/stylesheet.js';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import ReportsModule from '../../src/modules/reports/ReportsModule.js';

const thisYear = new Date().getFullYear();

function module() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = {
        accounts: [{ id: 3, name: 'Lohnkonto' }, { id: 7, name: 'Corner Card' }],
        settings: {},
    };
    mod.reportCharts = {};
    mod.generateBillsCalendar = vi.fn();
    mod.generateSummaryReport = vi.fn();
    mod.generateSpendingReport = vi.fn();
    return mod;
}

/** Whether a rule outside media queries hides the element. */
const hidden = el => rulesMatching(el).some(rule => /^none\b/.test(rule.declarations.display || ''));

describe('the Bills Calendar under the general report controls', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div class="reports-controls">
                <div class="control-group report-saved-group" id="saved"></div>
                <div class="control-group" id="type-group">
                    <select id="report-type">
                        <option value="summary">Summary</option>
                        <option value="spending">Spending</option>
                        <option value="bills-calendar">Bills Calendar</option>
                    </select>
                </div>
                <div class="control-group report-scope-control" id="period-group">
                    <select id="report-period-preset"><option value="last-3-months" selected>3</option></select>
                </div>
                <div id="custom-date-range" class="control-group custom-range report-scope-control" style="display: flex;">
                    <input id="report-start-date"><input id="report-end-date">
                </div>
                <div class="control-group report-scope-control" id="account-group"></div>
                <div class="control-group report-scope-control" id="report-exclude-shared-wrap"></div>
                <div class="control-group report-scope-control" id="tags-group"></div>
                <div class="control-group report-scope-control" id="untagged-group">
                    <input type="checkbox" id="report-include-untagged">
                </div>
                <button id="generate-report-btn">Generate Report</button>
            </div>
            <div id="report-loading"></div>
            <div id="report-bills-calendar" class="report-section">
                <select id="bills-calendar-year"></select>
                <select id="bills-calendar-account"><option value="">All Accounts</option></select>
                <select id="bills-calendar-status"><option value="active">Active</option></select>
                <input type="checkbox" id="bills-calendar-include-transfers" checked>
                <select id="bills-calendar-view"><option value="table">Table</option><option value="heatmap">Heatmap</option></select>
                <button id="generate-bills-calendar-btn"></button>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('opens on this year and All Accounts', () => {
        const mod = module();
        mod.showBillsCalendarReport();

        expect(document.getElementById('bills-calendar-year').value).toBe(String(thisYear));
        expect(document.getElementById('bills-calendar-account').value).toBe('');
    });

    it('keeps the Year and Account the user picked when Generate Report runs it again', async () => {
        const mod = module();
        document.getElementById('report-type').value = 'bills-calendar';
        await mod.generateReport();

        document.getElementById('bills-calendar-year').value = String(thisYear + 1);
        document.getElementById('bills-calendar-account').value = '3';
        await mod.generateReport();

        expect(document.getElementById('bills-calendar-year').value).toBe(String(thisYear + 1));
        expect(document.getElementById('bills-calendar-account').value).toBe('3');
        expect(mod.generateBillsCalendar).toHaveBeenCalledTimes(2);
    });

    it('still lists an account added since, and falls back to All Accounts for one that is gone', () => {
        const mod = module();
        mod.showBillsCalendarReport();
        document.getElementById('bills-calendar-account').value = '7';

        mod.app.accounts = [{ id: 3, name: 'Lohnkonto' }, { id: 9, name: 'Sparkonto' }];
        mod.showBillsCalendarReport();

        const select = document.getElementById('bills-calendar-account');
        expect([...select.options].map(o => o.value)).toEqual(['', '3', '9']);
        expect(select.value).toBe('');
    });

    it('hides the controls that do nothing for it', async () => {
        const mod = module();
        document.getElementById('report-type').value = 'bills-calendar';
        await mod.generateReport();

        for (const id of ['period-group', 'custom-date-range', 'account-group', 'report-exclude-shared-wrap', 'tags-group', 'untagged-group']) {
            expect(hidden(document.getElementById(id)), id).toBe(true);
        }
        for (const id of ['saved', 'type-group', 'generate-report-btn']) {
            expect(hidden(document.getElementById(id)), id).toBe(false);
        }
    });

    it('shows them again for the other reports', async () => {
        const mod = module();
        document.getElementById('report-type').value = 'bills-calendar';
        await mod.generateReport();
        document.getElementById('report-type').value = 'spending';
        await mod.generateReport();

        for (const id of ['period-group', 'custom-date-range', 'account-group', 'report-exclude-shared-wrap', 'tags-group', 'untagged-group']) {
            expect(hidden(document.getElementById(id)), id).toBe(false);
        }
        expect(mod.generateSpendingReport).toHaveBeenCalledTimes(1);
    });
});

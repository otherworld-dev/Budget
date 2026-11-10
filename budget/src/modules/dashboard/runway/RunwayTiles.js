/**
 * The runway dashboard tiles: "how long will my money last".
 *
 * Six tiles, all off by default: the Runway hero, the chart, the planned
 * one-offs, the two scenarios, where the drain comes from, and an overview
 * that combines them. They all render from one shared GET /api/runway, and
 * one setup (the gear on any of them) drives them all, so no two tiles can
 * disagree.
 *
 * The dashboard calls loadRunway() and renderRunwayTile() from its lazy
 * loader, and openRunwaySetup() from its tile settings dialog. After a
 * setup change or a one-off edit, every runway tile on the page is drawn
 * again from a fresh payload.
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { apiFetch } from '../../../utils/api.js';
import { showError, showSuccess } from '../../../utils/notifications.js';
import { monthsPhrase, untilPhrase, differencePhrase } from '../../../utils/runway.js';
import { drawRunwayChart } from './RunwayChart.js';
import { renderOneOffs } from './RunwayOneOffs.js';
import { renderRunwaySetup } from './RunwaySetup.js';
import {
    AS_TEXT,
    el,
    money,
    longDate,
    amountClass,
    runwayContext,
    loadRunway,
    resetRunway,
    setRunwayContext,
} from './runwayData.js';

export { loadRunway, resetRunway, setRunwayContext };

/** The registry keys of every runway tile: the hero first, then the widgets. */
export const RUNWAY_TILE_KEYS = ['runway', 'runwayChart', 'runwayOneOffs', 'runwayScenarios', 'runwayBreakdown', 'runwayOverview'];

/** Most excluded transactions the breakdown tile looks up when opened. */
const EXCLUDED_LIST_LIMIT = 20;

/** Tiles drawn so far, so a change can draw them all again. */
const rendered = new Set();

// ---------------------------------------------------------------------------
// Shared phrases
// ---------------------------------------------------------------------------

function drainLine(data) {
    const drain = data.scenarios?.current?.monthlyDrain;
    const months = data.window?.effectiveMonths || 0;
    if (drain === null || drain === undefined || months < 1) return '';
    const value = Number(drain);
    const amount = money(Math.abs(value), data.currency);
    return value > 0
        ? n('budget', 'Monthly drain {amount} · last {count} month', 'Monthly drain {amount} · last {count} months', months, { amount, count: months }, AS_TEXT)
        : n('budget', 'Monthly surplus {amount} · last {count} month', 'Monthly surplus {amount} · last {count} months', months, { amount, count: months }, AS_TEXT);
}

function onBudgetLine(onBudget) {
    if (!onBudget) return '';
    const months = monthsPhrase(onBudget);
    const until = untilPhrase(onBudget);
    return until
        ? t('budget', 'On budget: {months}, {until}', { months, until }, undefined, AS_TEXT)
        : t('budget', 'On budget: {months}', { months }, undefined, AS_TEXT);
}

/** The line under the months: the run-out month, or why there is none. */
function untilLine(scenario) {
    if (scenario?.state === 'not_running_out') return t('budget', 'More is coming in than going out');
    if (scenario?.state === 'insufficient_data') return t('budget', 'It needs at least one full month of transactions');
    return untilPhrase(scenario);
}

function perMonth(drain, currency) {
    if (drain === null || drain === undefined) return '';
    const value = Number(drain);
    const amount = money(Math.abs(value), currency);
    return value > 0
        ? t('budget', '{amount} a month going out', { amount }, undefined, AS_TEXT)
        : t('budget', '{amount} a month more coming in', { amount }, undefined, AS_TEXT);
}

function noAccounts(data) {
    return !data.accounts?.length;
}

function noAccountsMessage() {
    return el('div', 'empty-state-small', t('budget', 'No accounts are counted. Choose some with the gear on this tile while the dashboard is unlocked.'));
}

// ---------------------------------------------------------------------------
// Pieces
// ---------------------------------------------------------------------------

function headline(data) {
    const current = data.scenarios.current;
    const box = el('div', 'runway-headline');
    box.appendChild(el('div', 'runway-headline-label', t('budget', 'Runway')));
    box.appendChild(el('div', 'runway-headline-value', monthsPhrase(current)));
    const until = untilLine(current);
    if (until) box.appendChild(el('div', 'runway-headline-until', until));
    const drain = drainLine(data);
    if (drain) box.appendChild(el('div', 'runway-muted', drain));
    return box;
}

function scenarioBox(title, scenario, kind, currency) {
    const box = el('div', `runway-scenario runway-scenario--${kind}`);
    box.appendChild(el('div', 'runway-scenario-title', title));
    // A state with no figure reads as a sentence, so it gets body text size
    const figure = ['running_out', 'not_running_out', 'at_zero'].includes(scenario?.state);
    box.appendChild(el('div', figure ? 'runway-scenario-months' : 'runway-scenario-note', monthsPhrase(scenario)));
    const drain = perMonth(scenario?.monthlyDrain, currency);
    if (drain) box.appendChild(el('div', 'runway-scenario-drain', drain));
    const until = untilPhrase(scenario);
    if (until) box.appendChild(el('div', 'runway-scenario-until', until));
    return box;
}

function scenarios(data) {
    const wrap = el('div', 'runway-scenarios');
    const pair = el('div', 'runway-scenario-pair');
    pair.append(
        scenarioBox(t('budget', 'At current spending'), data.scenarios.current, 'current', data.currency),
        scenarioBox(t('budget', 'On budget'), data.scenarios.onBudget, 'budget', data.currency),
    );
    wrap.appendChild(pair);
    const difference = differencePhrase(data.differenceMonths);
    if (difference) wrap.appendChild(el('p', 'runway-difference', difference));
    return wrap;
}

/**
 * Looks the excluded transactions up the first time the list is opened.
 * The payload only carries their ids.
 */
async function fillExcluded(list, data) {
    const ids = data.excluded?.ids || [];
    list.textContent = '';
    list.appendChild(el('li', 'runway-muted', t('budget', 'Loading...')));
    const rows = await Promise.all(ids.slice(0, EXCLUDED_LIST_LIMIT)
        .map((id) => apiFetch(`/apps/budget/api/transactions/${id}`).catch(() => null)));
    list.textContent = '';
    const accounts = runwayContext().accounts() || [];
    for (const tx of rows.filter(Boolean)) {
        const signed = tx.type === 'debit' ? -Math.abs(Number(tx.amount)) : Math.abs(Number(tx.amount));
        const currency = accounts.find((a) => String(a.id) === String(tx.accountId))?.currency || data.currency;
        const item = el('li', 'runway-excluded-row');
        const open = el('button', 'runway-excluded-open');
        open.type = 'button';
        open.title = t('budget', 'Open transaction');
        open.append(
            el('span', 'runway-excluded-date', longDate(tx.date)),
            el('span', 'runway-excluded-description', tx.description || t('budget', '(no description)')),
            el('span', `runway-excluded-amount ${amountClass(signed)}`, money(signed, currency)),
        );
        open.addEventListener('click', () => runwayContext().openTransaction?.(tx.id));
        item.appendChild(open);
        list.appendChild(item);
    }
    if (ids.length > EXCLUDED_LIST_LIMIT) {
        const more = ids.length - EXCLUDED_LIST_LIMIT;
        list.appendChild(el('li', 'runway-muted', n('budget', 'and {count} more', 'and {count} more', more, { count: more })));
    }
    if (!list.children.length) {
        list.appendChild(el('li', 'runway-muted', t('budget', 'These transactions could not be loaded.')));
    }
}

function breakdown(data) {
    const wrap = el('div', 'runway-breakdown');
    const months = data.window?.effectiveMonths || 0;
    if (months < 1) {
        wrap.appendChild(el('div', 'empty-state-small', t('budget', 'Not enough history yet: the average needs at least one full month of transactions.')));
        return wrap;
    }

    const table = el('dl', 'runway-breakdown-rows');
    const add = (label, amount, className = '') => {
        const row = el('div', `runway-breakdown-row ${className}`.trim());
        row.append(el('dt', '', label), el('dd', '', amount));
        table.appendChild(row);
    };
    add(t('budget', 'Money out'), money(data.monthlyOut, data.currency));
    add(t('budget', 'Money in'), money(data.monthlyIn, data.currency));
    const drain = Number(data.scenarios?.current?.monthlyDrain ?? 0);
    add(drain > 0 ? t('budget', 'Net drain') : t('budget', 'Net surplus'), money(Math.abs(drain), data.currency), 'runway-breakdown-total');
    wrap.appendChild(table);

    const requested = data.window?.months || months;
    const caption = months < requested
        ? n('budget', 'A month, over the last {count} full month (that is all the history there is)', 'A month, over the last {count} full months (that is all the history there is)', months, { count: months })
        : n('budget', 'A month, over the last {count} full month', 'A month, over the last {count} full months', months, { count: months });
    wrap.appendChild(el('p', 'runway-muted', caption));

    const count = data.excluded?.count || 0;
    if (count > 0) {
        const details = el('details', 'runway-excluded');
        const summary = el('summary', '', n('budget', 'Left out of the average: {count} transaction, {amount}', 'Left out of the average: {count} transactions, {amount}',
            count, { count, amount: money(data.excluded.total, data.currency) }, AS_TEXT));
        details.appendChild(summary);
        details.appendChild(el('p', 'runway-muted', t('budget', 'These are marked "Exclude from forecast". Open one to change that.')));
        const list = el('ul', 'runway-excluded-list');
        details.appendChild(list);
        let filled = false;
        details.addEventListener('toggle', () => {
            if (details.open && !filled) {
                filled = true;
                fillExcluded(list, data);
            }
        });
        wrap.appendChild(details);
    }

    for (const skipped of data.skippedAccounts || []) {
        wrap.appendChild(el('p', 'runway-warning', t('budget', '{name} is left out: there is no exchange rate for its currency yet.', { name: skipped.name }, undefined, AS_TEXT)));
    }
    return wrap;
}

// ---------------------------------------------------------------------------
// Tiles
// ---------------------------------------------------------------------------

function renderHero(card, data) {
    const set = (part, text) => {
        const node = card.querySelector(`[data-runway-part="${part}"]`);
        if (!node) return;
        node.textContent = text;
        node.hidden = !text;
    };
    if (!data) {
        set('value', '--');
        set('until', t('budget', 'The runway could not be loaded'));
        set('budget', '');
        set('drain', '');
        return;
    }
    if (noAccounts(data)) {
        set('value', '--');
        set('until', t('budget', 'No accounts are counted'));
        set('budget', '');
        set('drain', '');
        return;
    }
    const current = data.scenarios.current;
    set('value', monthsPhrase(current));
    set('until', untilLine(current));
    set('budget', onBudgetLine(data.scenarios.onBudget));
    set('drain', drainLine(data));
}

const WIDGETS = {
    runwayChart: (body, data) => drawRunwayChart(body, data, 'runwayChart'),
    runwayOneOffs: (body, data) => renderOneOffs(body, data, { onChanged: refreshRunwayTiles }),
    runwayScenarios: (body, data) => body.appendChild(scenarios(data)),
    runwayBreakdown: (body, data) => body.appendChild(breakdown(data)),
    runwayOverview: (body, data) => {
        const grid = el('div', 'runway-overview');
        const left = el('div', 'runway-overview-summary');
        left.append(headline(data), scenarios(data));
        const right = el('div', 'runway-overview-detail');
        grid.append(left, right);
        // In the page before the chart reads its theme colours from it
        body.appendChild(grid);
        drawRunwayChart(right, data, 'runwayOverview');
        renderOneOffs(right, data, { onChanged: refreshRunwayTiles });
    },
};

/**
 * Draws one runway tile from the payload. A null payload draws the tile's
 * "could not be loaded" state.
 *
 * @param {string} key a registry key from RUNWAY_TILE_KEYS
 * @param {object|null} data the GET /api/runway payload
 */
export function renderRunwayTile(key, data) {
    const card = document.querySelector(`[data-widget-id="${key}"]`);
    if (!card) return;
    rendered.add(key);

    if (key === 'runway') {
        renderHero(card, data);
        return;
    }
    const draw = WIDGETS[key];
    const body = card.querySelector('[data-runway-body]');
    if (!draw || !body) return;

    body.textContent = '';
    if (!data) {
        body.appendChild(el('div', 'empty-state-small', t('budget', 'The runway could not be loaded')));
        return;
    }
    // One-offs can be planned before any account is chosen; everything
    // else needs a total to work from.
    if (noAccounts(data) && key !== 'runwayOneOffs') {
        body.appendChild(noAccountsMessage());
        return;
    }
    draw(body, data);
}

/**
 * Fetches the runway again and draws every runway tile drawn so far, after
 * a setup change or a one-off edit.
 *
 * @returns {Promise<void>}
 */
export async function refreshRunwayTiles() {
    let data = null;
    try {
        data = await loadRunway(true);
    } catch (error) {
        showError(error?.message || t('budget', 'The runway could not be loaded'));
    }
    for (const key of rendered) {
        renderRunwayTile(key, data);
    }
}

/**
 * Renders the runway setup into the tile settings dialog. Saving writes the
 * settings and then redraws every runway tile.
 *
 * @param {Function} [onSaved] called after a save and redraw
 * @param {HTMLElement} [container] defaults to the dialog's own section
 * @returns {Promise<void>}
 */
export async function openRunwaySetup(onSaved, container = document.getElementById('tile-settings-specific')) {
    if (!container) return;
    container.textContent = '';
    container.appendChild(el('div', 'runway-muted', t('budget', 'Loading...')));

    let data;
    try {
        data = await loadRunway();
    } catch (error) {
        container.textContent = '';
        container.appendChild(el('p', 'runway-warning', error?.message || t('budget', 'The runway could not be loaded')));
        return;
    }

    const save = async (body) => {
        try {
            await apiFetch('/apps/budget/api/settings', { method: 'PUT', body });
        } catch (error) {
            showError(error?.message || t('budget', 'Failed to save the runway settings'));
            return;
        }
        Object.assign(runwayContext().settings() || {}, body);
        showSuccess(t('budget', 'Runway settings saved'));
        await refreshRunwayTiles();
        // Draw the setup again from the new payload: the default-accounts
        // note and the ticks follow what was saved
        try {
            renderRunwaySetup(container, await loadRunway(), { onSave: save });
        } catch {
            // The tiles already say the runway could not be loaded
        }
        onSaved?.();
    };

    renderRunwaySetup(container, data, { onSave: save });
}

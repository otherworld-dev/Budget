/**
 * OnboardingModule - the first-run checklist on the dashboard, and the
 * sample-data banner.
 *
 * The server decides whether the checklist shows and which steps are done
 * (GET /api/onboarding, OnboardingService): each step ticks itself from what
 * the user really has, and an existing user with data never sees it. While
 * it shows, the dashboard's tiles step back behind it (a class on the view;
 * the saved layout is never touched) until the user asks for them.
 *
 * "Try with sample data" fills an empty budget; a banner then says so on
 * every view, with "Clear sample data" to wipe it through the factory reset
 * and return to the checklist.
 */

import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { apiFetch, ApiError } from '../../utils/api.js';
import { HELP_TOPICS, helpDocUrl } from '../help/HelpModule.js';

/** Link to a guide only when HELP_TOPICS knows the page exists */
function guideUrl(topic) {
    return HELP_TOPICS[topic] ? helpDocUrl(HELP_TOPICS[topic].doc) : null;
}

/**
 * The checklist's steps, in order, with their done state and actions.
 * Pure, so it can be tested without the DOM.
 *
 * @param {object} steps done flags from the server, keyed by step
 * @param {object} [options]
 * @param {boolean} [options.bankSyncEnabled] offer the bank-sync action
 * @return {Array<{id: string, title: string, text: string, done: boolean, actions: Array<{action: string, label: string, primary?: boolean}>, guide: ?string}>}
 */
export function checklistSteps(steps = {}, { bankSyncEnabled = false } = {}) {
    const dataActions = [{ action: 'import', label: t('budget', 'Import a statement'), primary: true }];
    if (bankSyncEnabled) {
        dataActions.push({ action: 'bank-sync', label: t('budget', 'Connect a bank') });
    }

    return [
        {
            id: 'currency',
            title: t('budget', 'Set your base currency'),
            text: t('budget', 'The currency your totals and reports are shown in.'),
            done: !!steps.currency,
            actions: [{ action: 'currency', label: t('budget', 'Open settings') }],
            guide: guideUrl('settings'),
        },
        {
            id: 'categories',
            title: t('budget', 'Create the default categories'),
            text: t('budget', 'A ready-made set of income and spending categories, which you can change later.'),
            done: !!steps.categories,
            actions: [{ action: 'categories', label: t('budget', 'Create default categories'), primary: true }],
            guide: guideUrl('categories'),
        },
        {
            id: 'account',
            title: t('budget', 'Add an account'),
            text: t('budget', 'A bank account, card or cash wallet to record money in and out of.'),
            done: !!steps.account,
            actions: [{ action: 'account', label: t('budget', 'Add an account') }],
            guide: guideUrl('accounts'),
        },
        {
            id: 'transactions',
            title: bankSyncEnabled
                ? t('budget', 'Import a statement or connect your bank')
                : t('budget', 'Import a statement'),
            text: t('budget', 'Bring in your transactions from a CSV, OFX or QIF file your bank gives you.'),
            done: !!steps.transactions,
            actions: dataActions,
            guide: guideUrl('import'),
        },
        {
            id: 'budget',
            title: t('budget', 'Set a budget for one category'),
            text: t('budget', 'Choose how much you want to spend on something each month.'),
            done: !!steps.budget,
            actions: [{ action: 'budget', label: t('budget', 'Open Budget') }],
            guide: guideUrl('budget'),
        },
    ];
}

export default class OnboardingModule {
    constructor(app) {
        this.app = app;
        this.state = null;
        this._busy = false;
        this._generation = 0;
        this._tilesShown = false;
    }

    /**
     * Fetch the checklist state and render the card and banner. Never
     * throws: a failure just leaves both hidden.
     */
    async load() {
        const generation = ++this._generation;
        let state = null;
        try {
            state = await apiFetch('/apps/budget/api/onboarding');
        } catch (error) {
            // A refused request just leaves the checklist hidden
            if (!(error instanceof ApiError)) {
                console.error('Failed to load the getting started checklist:', error);
            }
        }
        if (generation !== this._generation) return;
        this.state = state;
        this.render();
    }

    render() {
        this.renderBanner();
        this.renderChecklist();
    }

    bankSyncEnabled() {
        const nav = document.getElementById('bank-sync-nav');
        return !!nav && nav.style.display !== 'none';
    }

    renderChecklist() {
        const container = document.getElementById('onboarding-checklist');
        const view = document.getElementById('dashboard-view');
        if (!container) return;

        const show = !!this.state?.show;
        container.hidden = !show;
        view?.classList.toggle('onboarding-active', show);
        view?.classList.toggle('onboarding-tiles-shown', show && this._tilesShown);
        if (!show) {
            container.replaceChildren();
            return;
        }

        const steps = checklistSteps(this.state.steps || {}, { bankSyncEnabled: this.bankSyncEnabled() });
        const doneCount = steps.filter(s => s.done).length;

        const card = document.createElement('section');
        card.className = 'onboarding-card';
        card.setAttribute('aria-labelledby', 'onboarding-title');

        const head = document.createElement('div');
        head.className = 'onboarding-head';
        const heading = document.createElement('div');
        const title = document.createElement('h3');
        title.id = 'onboarding-title';
        title.textContent = t('budget', 'Get started with Budget');
        const intro = document.createElement('p');
        intro.className = 'onboarding-intro';
        intro.textContent = t('budget', 'A few steps to a working budget. Each one ticks itself off once it is done.');
        heading.append(title, intro);
        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'onboarding-dismiss icon-close';
        dismiss.dataset.action = 'dismiss';
        dismiss.title = t('budget', 'Hide this checklist');
        dismiss.setAttribute('aria-label', t('budget', 'Hide this checklist'));
        head.append(heading, dismiss);

        const progress = document.createElement('div');
        progress.className = 'onboarding-progress';
        const bar = document.createElement('div');
        bar.className = 'onboarding-progress-bar';
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', String(steps.length));
        bar.setAttribute('aria-valuenow', String(doneCount));
        bar.setAttribute('aria-label', t('budget', 'Getting started progress'));
        const fill = document.createElement('div');
        fill.className = 'onboarding-progress-fill';
        fill.style.width = `${Math.round((doneCount / steps.length) * 100)}%`;
        bar.appendChild(fill);
        const progressText = document.createElement('span');
        progressText.className = 'onboarding-progress-text';
        progressText.textContent = t('budget', '{done} of {total} done', { done: doneCount, total: steps.length });
        progress.append(bar, progressText);

        const list = document.createElement('ol');
        list.className = 'onboarding-steps';
        for (const step of steps) {
            list.appendChild(this.renderStep(step));
        }

        card.append(head, progress, list);

        if (this.state.canLoadSampleData) {
            const sample = document.createElement('div');
            sample.className = 'onboarding-sample';
            const text = document.createElement('p');
            text.textContent = t('budget', 'Just looking around? Fill Budget with sample accounts, transactions and budgets to explore, then clear them in one click when you are ready for your own.');
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.action = 'sample';
            button.textContent = t('budget', 'Try with sample data');
            sample.append(text, button);
            card.appendChild(sample);
        }

        const foot = document.createElement('div');
        foot.className = 'onboarding-foot';
        const guide = document.createElement('a');
        guide.href = helpDocUrl('getting-started');
        guide.target = '_blank';
        guide.rel = 'noopener';
        guide.textContent = t('budget', 'Read the getting started guide');
        const tiles = document.createElement('button');
        tiles.type = 'button';
        tiles.className = 'onboarding-tiles-toggle';
        tiles.dataset.action = 'tiles';
        tiles.setAttribute('aria-expanded', String(this._tilesShown));
        tiles.textContent = this._tilesShown
            ? t('budget', 'Hide the dashboard tiles')
            : t('budget', 'Show the dashboard tiles');
        foot.append(guide, tiles);
        card.appendChild(foot);

        card.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (target) this.handleAction(target.dataset.action);
        });

        container.replaceChildren(card);
    }

    renderStep(step) {
        const item = document.createElement('li');
        item.className = `onboarding-step ${step.done ? 'done' : 'todo'}`;
        item.dataset.step = step.id;

        const check = document.createElement('span');
        check.className = 'onboarding-step-check';
        check.setAttribute('aria-hidden', 'true');

        const body = document.createElement('div');
        body.className = 'onboarding-step-body';
        const title = document.createElement('span');
        title.className = 'onboarding-step-title';
        title.textContent = step.title;
        const status = document.createElement('span');
        status.className = 'hidden-visually';
        status.textContent = step.done ? t('budget', '(done)') : t('budget', '(not done yet)');
        title.append(' ', status);
        const text = document.createElement('span');
        text.className = 'onboarding-step-text';
        text.textContent = step.text;
        body.append(title, text);

        item.append(check, body);

        if (!step.done) {
            const actions = document.createElement('div');
            actions.className = 'onboarding-step-actions';
            for (const { action, label, primary } of step.actions) {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.action = action;
                if (primary) button.className = 'primary';
                button.textContent = label;
                actions.appendChild(button);
            }
            if (step.guide) {
                const guide = document.createElement('a');
                guide.className = 'onboarding-guide';
                guide.href = step.guide;
                guide.target = '_blank';
                guide.rel = 'noopener';
                guide.textContent = t('budget', 'Guide');
                actions.appendChild(guide);
            }
            item.appendChild(actions);
        }

        return item;
    }

    renderBanner() {
        const banner = document.getElementById('sample-data-banner');
        if (!banner) return;

        const show = !!this.state?.sampleData;
        banner.hidden = !show;
        if (!show) {
            banner.replaceChildren();
            return;
        }

        const text = document.createElement('div');
        text.className = 'sample-data-banner-text';
        const strong = document.createElement('strong');
        strong.textContent = t('budget', 'You\'re looking at sample data');
        const detail = document.createElement('span');
        detail.textContent = t('budget', 'None of it is real. Clear it when you are ready to add your own.');
        text.append(strong, detail);

        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = t('budget', 'Clear sample data');
        button.addEventListener('click', () => this.clearSampleData());

        banner.replaceChildren(text, button);
    }

    async handleAction(action) {
        switch (action) {
        case 'dismiss':
            return this.dismiss();
        case 'sample':
            return this.loadSampleData();
        case 'tiles':
            this._tilesShown = !this._tilesShown;
            this.renderChecklist();
            // Charts drawn while hidden measure themselves again
            window.dispatchEvent(new Event('resize'));
            return;
        case 'currency':
            this.app.showView('settings');
            setTimeout(() => document.getElementById('setting-default-currency')?.focus(), 0);
            return;
        case 'categories':
            return this.createDefaultCategories();
        case 'account':
            this.app.showView('accounts');
            this.app.showAccountModal?.();
            return;
        case 'import':
            return this.app.showView('import');
        case 'bank-sync':
            return this.app.showView('bank-sync');
        case 'budget':
            return this.app.showView('budget');
        }
    }

    async createDefaultCategories() {
        if (this._busy) return;
        this._busy = true;
        try {
            // The Categories page's own seeding, success toast included
            await this.app.categoriesModule.createDefaultCategories();
        } finally {
            this._busy = false;
        }
        await this.load();
    }

    async dismiss() {
        try {
            await apiFetch('/apps/budget/api/onboarding/dismiss', { method: 'POST' });
            this.state = { ...(this.state || {}), show: false };
            this.render();
        } catch (error) {
            console.error('Failed to hide the checklist:', error);
            showError(t('budget', 'Failed to hide the getting started checklist'));
        }
    }

    async loadSampleData() {
        if (this._busy) return;
        this._busy = true;
        const button = document.querySelector('#onboarding-checklist [data-action="sample"]');
        if (button) button.disabled = true;
        try {
            await apiFetch('/apps/budget/api/onboarding/sample-data', {
                method: 'POST',
                errorMessage: t('budget', 'Failed to add the sample data'),
            });
            showSuccess(t('budget', 'Sample data added. Have a look around.'));
            await this.refreshApp();
        } catch (error) {
            console.error('Failed to add sample data:', error);
            showError(error.message || t('budget', 'Failed to add the sample data'));
            if (button) button.disabled = false;
        } finally {
            this._busy = false;
        }
    }

    async clearSampleData() {
        if (this._busy) return;
        const confirmed = await confirmDialog(
            t('budget', 'This deletes everything in your budget: the sample data and anything you added while trying it. Your settings are kept.'),
            {
                title: t('budget', 'Clear sample data?'),
                confirmLabel: t('budget', 'Clear sample data'),
                destructive: true,
            }
        );
        if (!confirmed) return;

        this._busy = true;
        try {
            await apiFetch('/apps/budget/api/onboarding/sample-data', {
                method: 'DELETE',
                body: { confirmed: true },
                errorMessage: t('budget', 'Failed to clear the sample data'),
            });
            showSuccess(t('budget', 'Sample data cleared'));
            this._tilesShown = false;
            await this.refreshApp();
        } catch (error) {
            console.error('Failed to clear sample data:', error);
            showError(error.message || t('budget', 'Failed to clear the sample data'));
        } finally {
            this._busy = false;
        }
    }

    /** Reload what every view reads, then land on the dashboard. */
    async refreshApp() {
        await this.app.loadInitialData();
        this.app.showView('dashboard');
    }
}

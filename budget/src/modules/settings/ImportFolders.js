/**
 * The Import folder section of the settings page (#425).
 *
 * The user's import folder holds subfolders, each tied to one of their
 * accounts and an import template; a statement dropped into one is imported
 * in the background. This lists them, adds and removes them, and saves a
 * change to a row the moment it is made, as the rest of the page does.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { showError, showInfo } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { apiFetch, ApiError } from '../../utils/api.js';
import { importTargetAccounts } from '../../utils/accounts.js';

const API = '/apps/budget/api/import-folders';

/**
 * What to tell the user when an import folder was given a folder that
 * already held files: they are left where they are, and only files added
 * from then on are imported.
 *
 * @param {Object<string, number>|Array} counts How many files, by subfolder name
 * @return {string} Empty when there were none
 */
export function leftAloneNotice(counts) {
    const entries = Object.entries(counts && typeof counts === 'object' ? counts : {}).filter(([, count]) => count > 0);
    if (entries.length === 0) return '';
    if (entries.length === 1) {
        const [folder, count] = entries[0];
        return n(
            'budget',
            '{folder} already holds a file. It stays where it is: only files added from now on are imported.',
            '{folder} already holds %n files. They stay where they are: only files added from now on are imported.',
            count,
            { folder },
            { escape: false }
        );
    }
    return t('budget', 'Files already in {folders} stay where they are: only files added from now on are imported.', { folders: entries.map(([folder]) => folder).join(', ') }, undefined, { escape: false });
}

export default class ImportFolders {
    /**
     * @param {object} options
     * @param {Function} options.getAccounts The app's accounts, for the pickers
     */
    constructor({ getAccounts }) {
        this.getAccounts = getAccounts;
        this.folders = [];
        this.templates = [];
        this.bound = false;
    }

    async load() {
        try {
            const [data, templates] = await Promise.all([
                apiFetch(API),
                apiFetch('/apps/budget/api/import-templates'),
            ]);
            this.folders = data?.folders || [];
            this.templates = Array.isArray(templates) ? templates : [];
        } catch (error) {
            console.error('Failed to load import folders:', error);
            this.folders = [];
        }
        this.fillPickers();
        this.render();
        this.bind();
    }

    /** The rows again, as the server has them now. */
    async refresh() {
        try {
            const data = await apiFetch(API);
            this.folders = data?.folders || [];
            this.render();
        } catch (error) {
            console.error('Failed to reload import folders:', error);
        }
    }

    /** Own, open accounts only: an import cannot post into a shared account. */
    accounts(keepId) {
        return importTargetAccounts(this.getAccounts() || [], keepId ? [keepId] : []);
    }

    accountOptions(select, selected) {
        select.replaceChildren(...this.accounts(selected).map(account => new Option(account.name, String(account.id))));
        if (selected !== undefined && selected !== null) select.value = String(selected);
    }

    /** Import a new file straight away, or hold it until it has been looked at. */
    modeOptions(select, selected) {
        select.replaceChildren(
            new Option(t('budget', 'Import automatically'), 'auto'),
            new Option(t('budget', 'Hold for review'), 'review')
        );
        select.value = selected === 'review' ? 'review' : 'auto';
    }

    templateOptions(select, selected) {
        select.replaceChildren(
            new Option(t('budget', 'None (OFX, QIF and camt files only)'), ''),
            ...this.templates.map(template => new Option(`${template.name} (${String(template.format || 'csv').toUpperCase()})`, String(template.id)))
        );
        select.value = selected ? String(selected) : '';
    }

    fillPickers() {
        const account = document.getElementById('import-folder-account');
        const template = document.getElementById('import-folder-template');
        if (account) this.accountOptions(account);
        if (template) this.templateOptions(template);
    }

    render() {
        const list = document.getElementById('import-folders-list');
        const table = document.getElementById('import-folders-table');
        const empty = document.getElementById('import-folders-empty');
        if (!list) return;

        list.replaceChildren(...this.folders.map(folder => this.row(folder)));
        if (table) table.hidden = this.folders.length === 0;
        if (empty) empty.hidden = this.folders.length > 0;
    }

    row(folder) {
        const tr = document.createElement('tr');
        tr.dataset.id = String(folder.id);

        const name = document.createElement('th');
        name.scope = 'row';
        name.textContent = folder.name;

        const account = document.createElement('select');
        account.dataset.field = 'accountId';
        account.setAttribute('aria-label', t('budget', 'Account for {folder}', { folder: folder.name }, undefined, { escape: false }));
        this.accountOptions(account, folder.accountId);

        const template = document.createElement('select');
        template.dataset.field = 'templateId';
        template.setAttribute('aria-label', t('budget', 'Import template for {folder}', { folder: folder.name }, undefined, { escape: false }));
        this.templateOptions(template, folder.templateId);

        const mode = document.createElement('select');
        mode.dataset.field = 'mode';
        mode.setAttribute('aria-label', t('budget', 'When a file arrives in {folder}', { folder: folder.name }, undefined, { escape: false }));
        this.modeOptions(mode, folder.mode);

        const enabled = document.createElement('input');
        enabled.type = 'checkbox';
        enabled.dataset.field = 'enabled';
        enabled.checked = !!folder.enabled;
        enabled.setAttribute('aria-label', t('budget', 'Import files dropped into {folder}', { folder: folder.name }, undefined, { escape: false }));

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'icon-delete';
        remove.setAttribute('aria-label', t('budget', 'Remove {folder}', { folder: folder.name }, undefined, { escape: false }));
        remove.title = t('budget', 'Remove');

        for (const control of [name, account, template, mode, enabled, remove]) {
            if (control === name) {
                tr.append(control);
            } else {
                const td = document.createElement('td');
                td.append(control);
                tr.append(td);
            }
        }
        return tr;
    }

    /** Bound once: the section's DOM is permanent, only the rows change. */
    bind() {
        if (this.bound) return;
        this.bound = true;

        document.getElementById('import-folder-add')?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.add();
        });

        const list = document.getElementById('import-folders-list');
        list?.addEventListener('change', (event) => {
            const control = event.target.closest('[data-field]');
            const id = control?.closest('tr')?.dataset.id;
            if (control && id) this.save(Number(id), control);
        });
        list?.addEventListener('click', (event) => {
            const id = event.target.closest('button')?.closest('tr')?.dataset.id;
            if (id) this.remove(Number(id));
        });

        document.getElementById('import-folders-check')?.addEventListener('click', () => this.check());
    }

    showFailure(error, fallback) {
        console.error(fallback, error);
        showError(error instanceof ApiError ? error.message : fallback);
    }

    async add() {
        const name = document.getElementById('import-folder-name');
        const account = document.getElementById('import-folder-account');
        const template = document.getElementById('import-folder-template');
        const mode = document.getElementById('import-folder-mode');
        try {
            const folder = await apiFetch(API, {
                method: 'POST',
                body: {
                    name: name.value.trim(),
                    accountId: Number(account.value),
                    templateId: template.value === '' ? null : Number(template.value),
                    mode: mode?.value === 'review' ? 'review' : 'auto',
                },
                errorMessage: t('budget', 'Failed to add the import folder'),
            });
            const { leftAlone = 0, ...row } = folder || {};
            this.folders.push(row);
            this.folders.sort((a, b) => String(a.name).localeCompare(String(b.name)));
            name.value = '';
            this.render();
            const notice = leftAloneNotice({ [row.name]: leftAlone });
            if (notice) showInfo(notice);
        } catch (error) {
            this.showFailure(error, t('budget', 'Failed to add the import folder'));
        }
    }

    async save(id, control) {
        const field = control.dataset.field;
        let value;
        if (field === 'enabled') {
            value = control.checked;
        } else if (field === 'mode') {
            value = control.value;
        } else {
            value = control.value === '' ? null : Number(control.value);
        }
        try {
            const saved = await apiFetch(`${API}/${id}`, {
                method: 'PUT',
                body: { [field]: value },
                errorMessage: t('budget', 'Failed to update the import folder'),
            });
            this.folders = this.folders.map(folder => (folder.id === id ? saved : folder));
        } catch (error) {
            this.showFailure(error, t('budget', 'Failed to update the import folder'));
            this.render();
        }
    }

    async remove(id) {
        const folder = this.folders.find(f => f.id === id);
        if (!folder) return;
        const confirmed = await confirmDialog(
            t('budget', 'Stop importing from {folder}? The folder stays in your Files, with everything in it.', { folder: folder.name }, undefined, { escape: false }),
            { destructive: true }
        );
        if (!confirmed) return;
        try {
            await apiFetch(`${API}/${id}`, { method: 'DELETE', errorMessage: t('budget', 'Failed to remove the import folder') });
            this.folders = this.folders.filter(f => f.id !== id);
            this.render();
        } catch (error) {
            this.showFailure(error, t('budget', 'Failed to remove the import folder'));
        }
    }

    async check() {
        const button = document.getElementById('import-folders-check');
        const status = document.getElementById('import-folders-status');
        if (button) button.disabled = true;
        if (status) status.textContent = t('budget', 'Checking…');
        try {
            const result = await apiFetch(`${API}/check`, { method: 'POST', errorMessage: t('budget', 'Failed to check the import folder') });
            if (status) status.textContent = this.summary(result || {});
            // A folder whose file could not be moved is switched off by the
            // server; the table should say so
            await this.refresh();
        } catch (error) {
            if (status) status.textContent = '';
            this.showFailure(error, t('budget', 'Failed to check the import folder'));
        } finally {
            if (button) button.disabled = false;
        }
    }

    summary({ imported = 0, failed = 0, waiting = 0, review = 0 }) {
        const parts = [];
        if (imported > 0) parts.push(n('budget', '%n file imported', '%n files imported', imported));
        if (review > 0) parts.push(n('budget', '%n file ready to review', '%n files ready to review', review));
        if (failed > 0) parts.push(n('budget', '%n file could not be imported', '%n files could not be imported', failed));
        if (waiting > 0) parts.push(n('budget', '%n file still uploading or waiting its turn', '%n files still uploading or waiting their turn', waiting));
        return parts.length ? parts.join(', ') : t('budget', 'Nothing to import');
    }
}

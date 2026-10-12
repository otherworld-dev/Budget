/**
 * The "Waiting for review" list on the Import page (#425).
 *
 * An import folder set to hold its files keeps each dropped statement in a
 * Review folder until someone has looked at it. This lists them; Review
 * opens one in the import wizard (through the callback the wizard gives),
 * Skip moves it to the folder's Skipped folder.
 */
import { translate as t } from '@nextcloud/l10n';
import { showError } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { apiFetch, ApiError } from '../../utils/api.js';

const API = '/apps/budget/api/import-folders/review';

export default class ReviewQueue {
    /**
     * @param {object} options
     * @param {Function} options.getAccounts The app's accounts, to name each file's account
     * @param {Function} options.openInWizard Called with the upload response of the file to review
     */
    constructor({ getAccounts, openInWizard }) {
        this.getAccounts = getAccounts;
        this.openInWizard = openInWizard;
        this.files = [];
        this.bound = false;
    }

    async load() {
        try {
            const files = await apiFetch(API);
            this.files = Array.isArray(files) ? files : [];
        } catch (error) {
            console.error('Failed to load the files waiting for review:', error);
            this.files = [];
        }
        this.render();
        this.bind();
    }

    render() {
        const panel = document.getElementById('import-review-queue');
        const list = document.getElementById('import-review-list');
        if (!panel || !list) return;

        panel.hidden = this.files.length === 0;
        list.replaceChildren(...this.files.map(file => this.item(file)));
    }

    item(file) {
        const li = document.createElement('li');
        li.dataset.fileId = String(file.fileId);

        const text = document.createElement('div');
        text.className = 'import-review-file';
        const name = document.createElement('strong');
        name.textContent = file.name;
        const where = document.createElement('span');
        const account = (this.getAccounts() || []).find(a => String(a.id) === String(file.accountId));
        where.textContent = account
            ? t('budget', '{folder}, into {account}', { folder: file.folderName, account: account.name }, undefined, { escape: false })
            : file.folderName;
        text.append(name, where);
        if (file.modified > 0) {
            const date = new Date(file.modified * 1000);
            const arrived = document.createElement('time');
            arrived.dateTime = date.toISOString();
            arrived.textContent = date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
            text.append(arrived);
        }

        const review = document.createElement('button');
        review.type = 'button';
        review.className = 'primary';
        review.dataset.action = 'review';
        review.textContent = t('budget', 'Review');
        review.setAttribute('aria-label', t('budget', 'Review {file}', { file: file.name }, undefined, { escape: false }));

        const skip = document.createElement('button');
        skip.type = 'button';
        skip.className = 'secondary';
        skip.dataset.action = 'skip';
        skip.textContent = t('budget', 'Skip');
        skip.setAttribute('aria-label', t('budget', 'Skip {file}', { file: file.name }, undefined, { escape: false }));

        li.append(text, review, skip);
        return li;
    }

    /** Bound once: the panel's DOM is permanent, only its items change. */
    bind() {
        if (this.bound) return;
        const list = document.getElementById('import-review-list');
        if (!list) return;
        this.bound = true;

        list.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action]');
            const fileId = Number(button?.closest('li')?.dataset.fileId);
            if (!button || !fileId) return;
            if (button.dataset.action === 'review') {
                this.review(fileId, button);
            } else {
                this.skip(fileId);
            }
        });
    }

    async review(fileId, button) {
        if (button) button.disabled = true;
        try {
            const upload = await apiFetch(`${API}/${fileId}/open`, { method: 'POST', errorMessage: t('budget', 'Failed to open the file') });
            await this.openInWizard(upload);
        } catch (error) {
            console.error('Failed to open the file for review:', error);
            showError(error instanceof ApiError ? error.message : t('budget', 'Failed to open the file'));
        } finally {
            if (button) button.disabled = false;
        }
    }

    async skip(fileId) {
        const file = this.files.find(f => f.fileId === fileId);
        if (!file) return;
        const confirmed = await confirmDialog(
            t('budget', 'Skip {file}? It is moved to the Skipped folder and not imported.', { file: file.name }, undefined, { escape: false })
        );
        if (!confirmed) return;
        await this.finish(fileId, 'skipped');
    }

    /**
     * Move a file out of Review and reload the list. Never throws: after an
     * import, the import itself has already succeeded.
     *
     * @param {number} fileId
     * @param {'imported'|'skipped'} outcome
     */
    async finish(fileId, outcome) {
        try {
            await apiFetch(`${API}/${fileId}/finish`, { method: 'POST', body: { outcome }, errorMessage: t('budget', 'Failed to move the file') });
        } catch (error) {
            console.error('Failed to move the reviewed file:', error);
            showError(error instanceof ApiError ? error.message : t('budget', 'Failed to move the file'));
        }
        await this.load();
    }
}

/**
 * Shared modal handling (src/utils/modals.js).
 *
 * Two families of modal exist: static `.modal` markup shown by inline display,
 * and `.budget-modal-overlay` built in JS and removed on close. Escape, the
 * focus trap and "close when the user navigates away" only worked for the
 * first, and most static modals stayed aria-hidden="true" while on screen.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
}));

import {
    openModals, topModal, closeModal, closeAllModals, markModalOpen, markModalClosed,
} from '../../src/utils/modals.js';

describe('modals', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('finds open modals of both families, topmost last', () => {
        document.body.innerHTML = `
            <div id="a" class="modal" style="display: flex;"></div>
            <div id="b" class="modal" style="display: none;"></div>
            <div id="c" class="budget-modal-overlay"></div>`;
        expect(openModals().map(m => m.id)).toEqual(['a', 'c']);
        expect(topModal().id).toBe('c');
    });

    it('closes through the modal\'s own close control', () => {
        document.body.innerHTML = `
            <div id="m" class="modal" style="display: flex;">
                <button class="cancel-btn">Cancel</button>
            </div>`;
        const modal = document.getElementById('m');
        const onCancel = vi.fn(() => { modal.style.display = 'none'; });
        modal.querySelector('.cancel-btn').addEventListener('click', onCancel);

        closeModal(modal);

        expect(onCancel).toHaveBeenCalledOnce();
        expect(modal.style.display).toBe('none');
    });

    it('does not force a modal shut when its close handler keeps it open', () => {
        // A close handler that asks "discard changes?" leaves the modal up
        // until answered; closeModal must not hide it behind the question.
        document.body.innerHTML = `
            <div id="m" class="modal" style="display: flex;">
                <button class="close-btn">×</button>
            </div>`;
        const modal = document.getElementById('m');
        closeModal(modal);
        expect(modal.style.display).toBe('flex');
    });

    it('hides or removes a modal that has no close control', () => {
        document.body.innerHTML = `
            <div id="s" class="modal" style="display: flex;"></div>
            <div id="o" class="budget-modal-overlay"></div>`;
        closeAllModals();
        expect(document.getElementById('s').style.display).toBe('none');
        expect(document.getElementById('o')).toBeNull();
    });

    it('marks an opening modal as a labelled, visible dialog', () => {
        document.body.innerHTML = `
            <div id="m" class="modal" aria-hidden="true">
                <h3>Add to goal</h3>
                <button class="close-btn" title="Close window">×</button>
                <button class="modal-close">×</button>
            </div>`;
        const modal = document.getElementById('m');

        markModalOpen(modal);

        expect(modal.getAttribute('role')).toBe('dialog');
        expect(modal.getAttribute('aria-modal')).toBe('true');
        expect(modal.getAttribute('aria-hidden')).toBe('false');
        const labelId = modal.getAttribute('aria-labelledby');
        expect(document.getElementById(labelId).textContent).toBe('Add to goal');
        expect(modal.querySelector('.close-btn').getAttribute('aria-label')).toBe('Close window');
        expect(modal.querySelector('.modal-close').getAttribute('aria-label')).toBe('Close');

        markModalClosed(modal);
        expect(modal.getAttribute('aria-hidden')).toBe('true');
    });

    it('keeps an existing label', () => {
        document.body.innerHTML = `
            <div id="m" class="modal" aria-labelledby="own"><h3 id="own">Mine</h3><h4>Other</h4></div>`;
        const modal = document.getElementById('m');
        markModalOpen(modal);
        expect(modal.getAttribute('aria-labelledby')).toBe('own');
    });
});

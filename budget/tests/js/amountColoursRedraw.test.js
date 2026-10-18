/**
 * Charts draw on a canvas, so a colour-scheme change (or a system light/dark
 * flip while a scheme is active) has to rebuild the current view (#432).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { applyAmountColours, setupAmountColourRedraw } from '../../src/utils/amountColours.js';

describe('setupAmountColourRedraw', () => {
    let app;
    let mediaListeners;

    beforeEach(() => {
        applyAmountColours('default', '');
        mediaListeners = [];
        window.matchMedia = vi.fn(() => ({
            matches: false,
            addEventListener: (type, listener) => {
                if (type === 'change') mediaListeners.push(listener);
            },
        }));
        app = { reloadCurrentView: vi.fn() };
        setupAmountColourRedraw(app);
    });

    afterEach(() => {
        applyAmountColours('default', '');
        delete window.matchMedia;
        document.head.innerHTML = '';
    });

    it('reloads the view when the scheme changes', () => {
        applyAmountColours('colorblind', '');

        expect(app.reloadCurrentView).toHaveBeenCalledTimes(1);
    });

    it('does not reload on a no-op apply', () => {
        applyAmountColours('default', '');
        applyAmountColours('colorblind', '');
        app.reloadCurrentView.mockClear();

        applyAmountColours('colorblind', '');

        expect(app.reloadCurrentView).not.toHaveBeenCalled();
    });

    it('redraws charts on a colour-scheme flip when a scheme is active', () => {
        applyAmountColours('colorblind', '');
        app.reloadCurrentView.mockClear();

        expect(mediaListeners).toHaveLength(1);
        mediaListeners[0]();

        expect(app.reloadCurrentView).toHaveBeenCalledTimes(1);
    });

    it('ignores a colour-scheme flip under Default', () => {
        mediaListeners[0]();

        expect(app.reloadCurrentView).not.toHaveBeenCalled();
    });

    it('copes with a browser that has no matchMedia', () => {
        delete window.matchMedia;

        expect(() => setupAmountColourRedraw({ reloadCurrentView: vi.fn() })).not.toThrow();
    });
});

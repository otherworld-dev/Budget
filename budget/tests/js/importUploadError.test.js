/**
 * An upload the server refuses shows the server's reason (#424).
 *
 * Spreadsheets are converted on the server, so a password-protected or
 * unreadable one is refused there with a message saying what to do. The
 * import page used to replace every upload error with "Failed to upload
 * file", which would have hidden it.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/api.js', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, apiFetch: vi.fn() };
});

import ImportModule from '../../src/modules/import/ImportModule.js';
import { showError } from '../../src/utils/notifications.js';
import { apiFetch, ApiError } from '../../src/utils/api.js';

const file = () => new File(['x'], 'estratto.xls');

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('handleImportFile', () => {
    it('shows the reason the server gives', async () => {
        const message = 'This spreadsheet could not be read. If it is password-protected, remove the password, or save it as CSV and import that.';
        apiFetch.mockRejectedValue(new ApiError(message, { status: 400 }));

        await Object.create(ImportModule.prototype).handleImportFile(file());

        expect(showError).toHaveBeenCalledWith(message);
    });

    it('falls back to its own message when there is no reply to show', async () => {
        apiFetch.mockRejectedValue(new TypeError('Failed to fetch'));

        await Object.create(ImportModule.prototype).handleImportFile(file());

        expect(showError).toHaveBeenCalledWith('Failed to upload file');
    });
});

/**
 * apiFetch is the one path every request takes: the request token, JSON in
 * and out, and a failure turned into the server's own message.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { apiFetch, ApiError } from '../../src/utils/api.js';

const reply = (status, body, extra = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    statusText: extra.statusText || '',
    headers: { get: () => null },
    json: async () => {
        if (body instanceof Error) throw body;
        return body;
    },
    blob: async () => 'BLOB',
    text: async () => 'TEXT',
});

beforeEach(() => {
    global.OC = { generateUrl: (u) => '/index.php' + u, requestToken: 'tok' };
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
});

describe('apiFetch', () => {
    it('sends the request token and parses the JSON reply', async () => {
        global.fetch = vi.fn(async () => reply(200, { id: 1 }));

        const data = await apiFetch('/apps/budget/api/accounts');

        expect(data).toEqual({ id: 1 });
        expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/budget/api/accounts', {
            headers: { requesttoken: 'tok' },
        });
    });

    it('sends an object body as JSON with the JSON content type', async () => {
        global.fetch = vi.fn(async () => reply(200, {}));

        await apiFetch('/apps/budget/api/bills', { method: 'POST', body: { name: 'Rent' } });

        const [, init] = global.fetch.mock.calls[0];
        expect(init.method).toBe('POST');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(JSON.parse(init.body)).toEqual({ name: 'Rent' });
    });

    it('sends FormData untouched, leaving the content type to the browser', async () => {
        global.fetch = vi.fn(async () => reply(200, {}));
        const form = new FormData();
        form.append('file', 'x');

        await apiFetch('/apps/budget/api/import/upload', { method: 'POST', body: form });

        const [, init] = global.fetch.mock.calls[0];
        expect(init.body).toBe(form);
        expect(init.headers['Content-Type']).toBeUndefined();
        expect(init.headers.requesttoken).toBe('tok');
    });

    it('throws the server message, with its detail, on a failure', async () => {
        global.fetch = vi.fn(async () => reply(400, { error: 'Bad date', detail: 'SQLSTATE' }));

        const error = await apiFetch('/apps/budget/api/x', { errorMessage: 'Failed' }).catch(e => e);

        expect(error).toBeInstanceOf(ApiError);
        expect(error.message).toBe('Bad date (SQLSTATE)');
        expect(error.status).toBe(400);
        expect(error.data).toEqual({ error: 'Bad date', detail: 'SQLSTATE' });
    });

    it('falls back to the caller message when the failure body is not JSON', async () => {
        global.fetch = vi.fn(async () => reply(502, new SyntaxError('Unexpected token <')));

        const error = await apiFetch('/apps/budget/api/x', { errorMessage: 'Failed to save' }).catch(e => e);

        expect(error.message).toBe('Failed to save');
        expect(error.status).toBe(502);
        expect(error.data).toBeNull();
    });

    it('names the status when there is no message at all', async () => {
        global.fetch = vi.fn(async () => ({ ok: false, status: 500, statusText: 'Server Error' }));

        await expect(apiFetch('/apps/budget/api/x')).rejects.toThrow('HTTP 500: Server Error');
    });

    it('reads a download as a blob or hands back the response', async () => {
        global.fetch = vi.fn(async () => reply(200, null));

        expect(await apiFetch('/apps/budget/api/export', { responseType: 'blob' })).toBe('BLOB');
        expect(await apiFetch('/apps/budget/api/export', { responseType: 'text' })).toBe('TEXT');
        const response = await apiFetch('/apps/budget/api/export', { responseType: 'response' });
        expect(response.status).toBe(200);
    });

    it('returns null for an empty reply', async () => {
        global.fetch = vi.fn(async () => ({ ok: true, status: 204 }));

        expect(await apiFetch('/apps/budget/api/x', { method: 'DELETE' })).toBeNull();
    });
});

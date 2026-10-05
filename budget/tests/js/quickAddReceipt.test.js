/**
 * Receipts on the standalone Quick Add page (#419).
 *
 * The page could only record a transaction, so a receipt meant saving it and
 * then opening it again in the main app to attach the photo. It now takes the
 * receipt up front: when the server can read receipts the photo fills in the
 * form, the way the main app's scan does, and either way the file is attached
 * once the transaction is saved, as the upload needs its id.
 *
 * The page is a PHP template with its script inline, so this renders it the
 * way the server would, with the PHP swapped for what it prints, and runs the
 * script against a mocked server.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const TEMPLATE = fs.readFileSync(path.resolve(__dirname, '../../templates/quick-add.php'), 'utf8');

/** The contents of a single-quoted PHP string literal. */
const phpString = (literal) => literal.replace(/\\(['\\])/g, '$1');

/** The template as the server prints it, near enough for the script to run. */
function render(template) {
    return template
        .replace(/^<\?php[\s\S]*?\?>/, '')
        .replace(/<\?php foreach[\s\S]*?<\?php endforeach; \?>/g, '')
        .replace(/<\?php echo \$js\(\$l->t\('((?:[^'\\]|\\.)*)'\)\); \?>/g, (m, s) => JSON.stringify(phpString(s)))
        .replace(/<\?php p\(\$l->t\('((?:[^'\\]|\\.)*)'\)\); \?>/g, (m, s) => phpString(s))
        .replace(/<\?php echo date\('Y-m-d'\); \?>/g, '2026-10-05')
        .replace(/<\?php[\s\S]*?\?>/g, '');
}

// Quoted attributes skipped whole: the nonce's PHP has a -> in it.
const SCRIPT_SOURCE = TEMPLATE.match(/<script\b(?:[^>"]|"[^"]*")*>([\s\S]*?)<\/script>/)[1];

const DRAFT = {
    merchant: 'The Corner Deli',
    date: '2026-09-30',
    currency: 'GBP',
    total: '23.77',
    lineItems: [],
    suggestedCategoryId: 10,
    suggestedCategoryName: 'Groceries',
    warnings: [],
};

const respond = (status, body) => Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
});

/** A server answering the page's requests; override a route to change one answer. */
function server(routes = {}) {
    const table = {
        'GET /apps/budget/api/receipts/ocr-status': () => respond(200, { available: false, mimeTypes: [] }),
        'POST /apps/budget/api/receipts/extract': () => respond(200, DRAFT),
        'POST /apps/budget/api/transactions': () => respond(201, { id: 42 }),
        'POST /apps/budget/api/transactions/42/attachments/upload': () => respond(201, { id: 7 }),
        ...routes,
    };
    return vi.fn((url, options = {}) => {
        const key = `${options.method || 'GET'} ${url}`;
        if (!table[key]) {
            throw new Error(`unexpected request: ${key}`);
        }
        return table[key](options);
    });
}

const SCANNABLE = { 'GET /apps/budget/api/receipts/ocr-status': () => respond(200, { available: true, mimeTypes: ['image/jpeg', 'image/png', 'image/webp'] }) };

function mount(fetchMock) {
    globalThis.fetch = fetchMock;
    globalThis.OC = { generateUrl: (url) => url, requestToken: 'token' };

    document.body.innerHTML = render(TEMPLATE).replace(/<script[\s\S]*?<\/script>/, '');
    document.getElementById('qa-account').insertAdjacentHTML('beforeend', `
        <option value="1" data-owner="">Current account</option>
        <option value="2" data-owner="sam">Joint account</option>`);
    document.getElementById('qa-category').insertAdjacentHTML('beforeend', `
        <option value="10" data-type="expense" data-owner="">Groceries</option>
        <option value="11" data-type="income" data-owner="">Salary</option>`);

    new Function(render(SCRIPT_SOURCE))();
}

const $ = (id) => document.getElementById(id);
const photo = (type = 'image/jpeg', name = 'image.jpg') => new File(['receipt'], name, { type });
const calls = (fetchMock, key) => fetchMock.mock.calls.filter(([url, options = {}]) => `${options.method || 'GET'} ${url}` === key);
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function choose(file) {
    const input = $('qa-receipt');
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change'));
}

function fill({ account = '1', amount = '12.50', description = 'Lunch' } = {}) {
    $('qa-account').value = account;
    $('qa-account').dispatchEvent(new Event('change'));
    $('qa-amount').value = amount;
    $('qa-description').value = description;
}

const save = () => $('quick-add-form').dispatchEvent(new Event('submit', { cancelable: true }));

describe('Quick Add receipts (#419)', () => {
    beforeEach(() => {
        vi.useRealTimers();
    });

    afterEach(() => {
        delete globalThis.fetch;
        delete globalThis.OC;
        document.body.innerHTML = '';
    });

    it('attaches the chosen receipt to the transaction it saves', async () => {
        const fetchMock = server();
        mount(fetchMock);
        const file = photo();

        choose(file);
        fill();
        save();

        await vi.waitFor(() => expect(calls(fetchMock, 'POST /apps/budget/api/transactions/42/attachments/upload')).toHaveLength(1));
        const [, upload] = calls(fetchMock, 'POST /apps/budget/api/transactions/42/attachments/upload')[0];
        expect(upload.body.get('file')).toBe(file);
        expect(upload.headers.requesttoken).toBe('token');

        await vi.waitFor(() => expect($('qa-status').className).toContain('qa-success'));
        expect(calls(fetchMock, 'POST /apps/budget/api/transactions')).toHaveLength(1);
        // Nothing was read: this server can't scan.
        expect(calls(fetchMock, 'POST /apps/budget/api/receipts/extract')).toHaveLength(0);
        // Saved, so the next transaction starts without it.
        expect($('qa-receipt-chosen').hidden).toBe(true);
    });

    it('saves without an upload when no receipt was chosen', async () => {
        const fetchMock = server();
        mount(fetchMock);

        fill();
        save();

        await vi.waitFor(() => expect($('qa-status').className).toContain('qa-success'));
        expect(fetchMock.mock.calls.some(([url]) => url.includes('/attachments/'))).toBe(false);
    });

    it('fills in the form from the receipt when the server can read it', async () => {
        const fetchMock = server(SCANNABLE);
        mount(fetchMock);
        const file = photo();

        choose(file);

        await vi.waitFor(() => expect($('qa-amount').value).toBe('23.77'));
        const [, extract] = calls(fetchMock, 'POST /apps/budget/api/receipts/extract')[0];
        expect(extract.body.get('image')).toBe(file);
        expect($('qa-date').value).toBe('2026-09-30');
        expect($('qa-description').value).toBe('The Corner Deli');
        expect($('qa-vendor').value).toBe('The Corner Deli');
        expect($('qa-category').value).toBe('10');
        expect($('qa-receipt-note').textContent).toContain('Filled in: date, amount, description, vendor, category.');
        expect($('qa-receipt-note').textContent).toContain('The photo will be attached when you save.');
    });

    it('leaves the category alone when the form does not offer the one suggested', async () => {
        const fetchMock = server({
            ...SCANNABLE,
            // An income category, while the form is on Expense.
            'POST /apps/budget/api/receipts/extract': () => respond(200, { ...DRAFT, suggestedCategoryId: 11 }),
        });
        mount(fetchMock);

        choose(photo());

        await vi.waitFor(() => expect($('qa-amount').value).toBe('23.77'));
        expect($('qa-category').value).toBe('');
        expect($('qa-receipt-note').textContent).not.toContain('category');
    });

    it('attaches a file the scanner cannot read without trying to read it', async () => {
        const fetchMock = server(SCANNABLE);
        mount(fetchMock);

        choose(photo('application/pdf', 'invoice.pdf'));
        await settle();
        fill();
        save();

        await vi.waitFor(() => expect(calls(fetchMock, 'POST /apps/budget/api/transactions/42/attachments/upload')).toHaveLength(1));
        expect(calls(fetchMock, 'POST /apps/budget/api/receipts/extract')).toHaveLength(0);
    });

    it('keeps the photo when the receipt cannot be read', async () => {
        const fetchMock = server({
            ...SCANNABLE,
            'POST /apps/budget/api/receipts/extract': () => respond(422, { error: 'No total could be read from this receipt. Enter the details manually' }),
        });
        mount(fetchMock);

        choose(photo());

        await vi.waitFor(() => expect($('qa-receipt-note').textContent).toContain('No total could be read'));
        expect($('qa-receipt-note').textContent).toContain('The photo will be attached when you save.');
        expect($('qa-amount').value).toBe('');

        fill();
        save();
        await vi.waitFor(() => expect(calls(fetchMock, 'POST /apps/budget/api/transactions/42/attachments/upload')).toHaveLength(1));
    });

    it('cannot be saved while the receipt is being read', async () => {
        let finishReading;
        const fetchMock = server({
            ...SCANNABLE,
            'POST /apps/budget/api/receipts/extract': () => new Promise((resolve) => { finishReading = () => resolve(respond(200, DRAFT)); }),
        });
        mount(fetchMock);

        choose(photo());
        await vi.waitFor(() => expect(finishReading).toBeTypeOf('function'));
        expect($('qa-receipt-note').textContent).toContain('Reading the receipt');
        expect(document.querySelector('.qa-submit-btn').disabled).toBe(true);

        fill();
        save();
        await settle();
        expect(calls(fetchMock, 'POST /apps/budget/api/transactions')).toHaveLength(0);

        finishReading();
        await vi.waitFor(() => expect(document.querySelector('.qa-submit-btn').disabled).toBe(false));
    });

    it('fills nothing in from a reading that finishes after the form was cleared', async () => {
        let finishReading;
        const fetchMock = server({
            ...SCANNABLE,
            'POST /apps/budget/api/receipts/extract': () => new Promise((resolve) => { finishReading = () => resolve(respond(200, DRAFT)); }),
        });
        mount(fetchMock);

        choose(photo());
        await vi.waitFor(() => expect(finishReading).toBeTypeOf('function'));
        $('quick-add-form').reset();
        expect(document.querySelector('.qa-submit-btn').disabled).toBe(false);

        finishReading();
        await settle();
        await settle();
        expect($('qa-amount').value).toBe('');
        expect($('qa-receipt-chosen').hidden).toBe(true);
    });

    it('drops the receipt when it is removed', async () => {
        const fetchMock = server();
        mount(fetchMock);

        choose(photo());
        expect($('qa-receipt-chosen').hidden).toBe(false);
        expect($('qa-receipt-name').textContent).toBe('image.jpg');
        $('qa-receipt-remove').click();
        expect($('qa-receipt-chosen').hidden).toBe(true);

        fill();
        save();
        await vi.waitFor(() => expect($('qa-status').className).toContain('qa-success'));
        expect(fetchMock.mock.calls.some(([url]) => url.includes('/attachments/'))).toBe(false);
    });

    it('says the transaction was saved when only the receipt failed, so it is not saved twice', async () => {
        const fetchMock = server({
            'POST /apps/budget/api/transactions/42/attachments/upload': () => respond(500, { error: 'Failed to upload receipt' }),
        });
        mount(fetchMock);

        choose(photo());
        fill();
        save();

        await vi.waitFor(() => expect($('qa-status').className).toContain('qa-error'));
        expect($('qa-status').textContent).toContain('The transaction was saved, but the receipt could not be attached');
        // The transaction is recorded, so the form is cleared like any save.
        expect($('qa-amount').value).toBe('');
        expect(calls(fetchMock, 'POST /apps/budget/api/transactions')).toHaveLength(1);
    });

    it('does not upload into an account shared with you, and says so before saving', async () => {
        const fetchMock = server();
        mount(fetchMock);

        choose(photo());
        fill({ account: '2' });
        expect($('qa-receipt-note').textContent).toBe('Receipts can only be attached to transactions in your own accounts.');

        save();
        await vi.waitFor(() => expect($('qa-status').className).toContain('qa-error'));
        expect($('qa-status').textContent).toContain('The transaction was saved, but the receipt could not be attached');
        expect(fetchMock.mock.calls.some(([url]) => url.includes('/attachments/'))).toBe(false);
    });

    it('explains what a photo does when the server can read receipts', async () => {
        mount(server(SCANNABLE));
        await vi.waitFor(() => expect($('qa-receipt-note').hidden).toBe(false));
        expect($('qa-receipt-note').textContent).toContain('fills in the form');
    });

    it('waits for Nextcloud to define OC before asking whether receipts can be read', async () => {
        // Nextcloud's own scripts are modules, so they run after this inline
        // one: OC does not exist yet while the page is still loading.
        const fetchMock = server(SCANNABLE);
        const readyState = vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
        const script = render(SCRIPT_SOURCE);
        globalThis.fetch = fetchMock;
        document.body.innerHTML = render(TEMPLATE).replace(/<script[\s\S]*?<\/script>/, '');

        expect(() => new Function(script)()).not.toThrow();

        globalThis.OC = { generateUrl: (url) => url, requestToken: 'token' };
        readyState.mockRestore();
        document.dispatchEvent(new Event('DOMContentLoaded'));

        await vi.waitFor(() => expect($('qa-receipt-note').hidden).toBe(false));
        expect(calls(fetchMock, 'GET /apps/budget/api/receipts/ocr-status')).toHaveLength(1);
    });

    it('says nothing under the button when the server cannot read receipts', async () => {
        mount(server());
        await settle();
        expect($('qa-receipt-note').hidden).toBe(true);
    });

    it('puts translations into the script as JavaScript strings, so an apostrophe in one survives', () => {
        // p() escapes for HTML: inside a script that turns the apostrophe in
        // French "Enregistrer l'opération" into a visible &#039;.
        expect(SCRIPT_SOURCE).not.toMatch(/p\(\$l->t\(/);
    });
});

/**
 * The one way the frontend talks to its own back end.
 *
 * Every request needs the same things: the app route turned into a URL, the
 * CSRF request token, a JSON body with its content type, the reply parsed, and
 * a failure turned into a message the user can act on. Hundreds of call sites
 * used to do this by hand and drifted — some dropped the server's `error`,
 * some parsed the body of a failure that was not JSON and lost the translated
 * fallback to a SyntaxError.
 */

import { serverErrorMessage } from './helpers.js';

/**
 * A request the server answered with a non-2xx status.
 *
 * The message is ready to show; `status` and `data` are there for the callers
 * that treat a particular answer differently (409 conflict, 404 not found).
 */
export class ApiError extends Error {
    /**
     * @param {string} message - What to show the user
     * @param {object} details
     * @param {number} details.status - HTTP status code
     * @param {*} details.data - The parsed JSON error body, or null when there was none
     * @param {Response} details.response - The response itself
     */
    constructor(message, { status, data = null, response = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
        this.response = response;
    }
}

/**
 * Bodies that go on the wire as they are; anything else is sent as JSON.
 *
 * @param {*} body
 * @returns {boolean}
 */
function isRawBody(body) {
    return typeof body === 'string'
        || (typeof FormData !== 'undefined' && body instanceof FormData)
        || (typeof Blob !== 'undefined' && body instanceof Blob)
        || (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams)
        || (typeof ArrayBuffer !== 'undefined' && body instanceof ArrayBuffer);
}

/**
 * The JSON body of a failed response, or null. A failure is not always JSON —
 * a maintenance page, a proxy's 502 — and must never trade the message for a
 * parse error.
 *
 * @param {Response} response
 * @returns {Promise<*>}
 */
async function errorBody(response) {
    if (typeof response?.json !== 'function') {
        return null;
    }
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Call one of the app's routes.
 *
 * @param {string} path - App route such as '/apps/budget/api/accounts'; turned
 *   into a URL with OC.generateUrl
 * @param {object} [options]
 * @param {string} [options.method] - HTTP method (fetch's default, GET, when omitted)
 * @param {*} [options.body] - Plain objects and arrays are sent as JSON with a
 *   JSON content type. A string, FormData, Blob or URLSearchParams goes as it
 *   is; a string gets the JSON content type (every caller that sends a string
 *   sends JSON), the others let the browser set theirs, which a multipart
 *   upload needs for its boundary.
 * @param {object} [options.headers] - Extra headers, merged over the defaults
 * @param {'json'|'blob'|'text'|'response'} [options.responseType='json'] - How
 *   to read a successful reply. 'response' hands back the Response itself, for
 *   a download that needs its headers.
 * @param {string} [options.errorMessage] - Shown when a failure carries no
 *   message of its own; defaults to "HTTP <status>: <statusText>"
 * @param {AbortSignal} [options.signal]
 * @returns {Promise<*>} The parsed reply; null for an empty (204) one
 * @throws {ApiError} On a non-2xx status
 */
export async function apiFetch(path, options = {}) {
    const {
        method,
        body,
        headers = {},
        responseType = 'json',
        errorMessage,
        signal,
    } = options;

    const init = {
        headers: { 'requesttoken': OC.requestToken },
    };
    if (method) {
        init.method = method;
    }
    if (body !== undefined && body !== null) {
        if (isRawBody(body)) {
            init.body = body;
            if (typeof body === 'string') {
                init.headers['Content-Type'] = 'application/json';
            }
        } else {
            init.body = JSON.stringify(body);
            init.headers['Content-Type'] = 'application/json';
        }
    }
    Object.assign(init.headers, headers);
    if (signal) {
        init.signal = signal;
    }

    const response = await fetch(OC.generateUrl(path), init);

    if (!response.ok) {
        const data = await errorBody(response);
        const status = response.status;
        const fallback = errorMessage
            || `HTTP ${status}${response.statusText ? ': ' + response.statusText : ''}`;
        throw new ApiError(serverErrorMessage(data, fallback), { status, data, response });
    }

    switch (responseType) {
    case 'response':
        return response;
    case 'blob':
        return response.blob();
    case 'text':
        return response.text();
    default:
        if (response.status === 204 || typeof response.json !== 'function') {
            return null;
        }
        if (response.headers?.get?.('Content-Length') === '0') {
            return null;
        }
        return response.json();
    }
}

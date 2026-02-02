/**
 * API Client wrapper for centralized fetch calls with auth headers
 */
export default class ApiClient {
    constructor(appState) {
        this.state = appState;
    }

    /**
     * Get auth headers including session token if available
     * @returns {object} Headers object
     */
    getHeaders() {
        const headers = {
            'Content-Type': 'application/json',
            'requesttoken': OC.requestToken
        };

        // Add session token if password protection is enabled
        const sessionToken = this.state ? this.state.get('sessionToken') : null;
        if (sessionToken) {
            headers['X-Budget-Session'] = sessionToken;
        }

        return headers;
    }

    /**
     * Perform GET request
     * @param {string} url - API endpoint (relative URL)
     * @param {object} options - Additional fetch options
     * @returns {Promise} Response data
     */
    async get(url, options = {}) {
        const response = await fetch(OC.generateUrl(url), {
            method: 'GET',
            headers: {
                ...this.getHeaders(),
                ...options.headers
            },
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response.json();
    }

    /**
     * Perform POST request
     * @param {string} url - API endpoint (relative URL)
     * @param {object} data - Data to send in request body
     * @param {object} options - Additional fetch options
     * @returns {Promise} Response data
     */
    async post(url, data = {}, options = {}) {
        const response = await fetch(OC.generateUrl(url), {
            method: 'POST',
            headers: {
                ...this.getHeaders(),
                ...options.headers
            },
            body: JSON.stringify(data),
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response.json();
    }

    /**
     * Perform PUT request
     * @param {string} url - API endpoint (relative URL)
     * @param {object} data - Data to send in request body
     * @param {object} options - Additional fetch options
     * @returns {Promise} Response data
     */
    async put(url, data = {}, options = {}) {
        const response = await fetch(OC.generateUrl(url), {
            method: 'PUT',
            headers: {
                ...this.getHeaders(),
                ...options.headers
            },
            body: JSON.stringify(data),
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response.json();
    }

    /**
     * Perform DELETE request
     * @param {string} url - API endpoint (relative URL)
     * @param {object} options - Additional fetch options
     * @returns {Promise} Response data
     */
    async delete(url, options = {}) {
        const response = await fetch(OC.generateUrl(url), {
            method: 'DELETE',
            headers: {
                ...this.getHeaders(),
                ...options.headers
            },
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response.json();
    }

    /**
     * Upload file (multipart/form-data)
     * @param {string} url - API endpoint (relative URL)
     * @param {FormData} formData - Form data with file
     * @returns {Promise} Response data
     */
    async upload(url, formData) {
        const headers = {
            'requesttoken': OC.requestToken
        };

        // Add session token if available
        const sessionToken = this.state ? this.state.get('sessionToken') : null;
        if (sessionToken) {
            headers['X-Budget-Session'] = sessionToken;
        }

        const response = await fetch(OC.generateUrl(url), {
            method: 'POST',
            headers: headers,
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response.json();
    }
}

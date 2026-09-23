/**
 * Where webpack fetches the views that load on first visit (reports,
 * forecast, import, ...), and the nonce their script tags carry.
 *
 * Imported first by main.js. The chunks sit next to budget-app.js in the
 * app's js/ folder, which may live under apps/, custom_apps/ or anywhere an
 * admin put the app, so the path is asked of Nextcloud rather than assumed.
 * Nextcloud's Content-Security-Policy only runs scripts carrying its nonce,
 * which is derived from the request token.
 */

import { generateFilePath } from '@nextcloud/router';

// eslint-disable-next-line no-undef, camelcase
__webpack_public_path__ = generateFilePath('budget', '', 'js/');
// eslint-disable-next-line no-undef, camelcase
__webpack_nonce__ = btoa(OC.requestToken);

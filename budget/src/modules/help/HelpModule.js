/**
 * Help Module - the Help & Docs view plus the topic map behind the floating
 * help panel.
 *
 * The topic map is exported so the panel (main.js) and this view render from
 * one list; adding a doc page means editing HELP_TOPICS only.
 */
import { showSuccess } from '../../utils/notifications.js';
import { translate as t } from '@nextcloud/l10n';

// The documentation lives in the website project, not here — this app only
// links to the published pages. A topic's `doc` slug is the page name, so
// `dashboard` becomes https://budget.otherworld.dev/docs/dashboard.html.
const DOCS_BASE = 'https://budget.otherworld.dev/docs';

/**
 * Keyed by view name so the floating panel can look up the current view.
 * `doc` is the published page the "Read full guide" link points at; adding a
 * topic here means the page must exist on the site, or the link 404s.
 */
export const HELP_TOPICS = {
    dashboard: { title: () => t('budget', 'Dashboard'), summary: () => t('budget', 'Your financial overview with customizable tiles. Unlock the dashboard to rearrange, add, or remove tiles. Click the gear icon on the Accounts tile to reorder or hide accounts.'), doc: 'dashboard' },
    accounts: { title: () => t('budget', 'Accounts'), summary: () => t('budget', 'Manage bank accounts, credit cards, cash, and crypto across 45+ currencies. Click an account to see its transaction history and balance details.'), doc: 'accounts' },
    transactions: { title: () => t('budget', 'Transactions'), summary: () => t('budget', 'Add, edit, filter, and bulk-manage transactions. Use the Filters button for advanced search. Split transactions across categories.'), doc: 'transactions' },
    categories: { title: () => t('budget', 'Categories'), summary: () => t('budget', 'Organise transactions with a hierarchical category tree. Drag to reorder. Click a category to see spending analytics.'), doc: 'categories' },
    tags: { title: () => t('budget', 'Tags'), summary: () => t('budget', 'Create tag sets per category for multi-dimensional tracking (e.g. store, project, payment method).'), doc: 'tags' },
    budget: { title: () => t('budget', 'Budget'), summary: () => t('budget', 'Set spending limits per category. Switch between weekly, monthly, quarterly, and yearly periods. Use "Adjust budgets from this month" to set different values for future months.'), doc: 'budget' },
    income: { title: () => t('budget', 'Income'), summary: () => t('budget', 'Track recurring income sources. Use "Detect Income" to auto-find patterns. Mark income as received to create transactions.'), doc: 'income' },
    bills: { title: () => t('budget', 'Bills'), summary: () => t('budget', 'Track recurring payments with auto-pay, custom frequencies, and notifications. View the bills calendar in Reports.'), doc: 'bills' },
    transfers: { title: () => t('budget', 'Transfers'), summary: () => t('budget', 'Set up recurring or one-time transfers between your accounts with auto-pay support.'), doc: 'transfers' },
    'savings-goals': { title: () => t('budget', 'Savings Goals'), summary: () => t('budget', 'Set financial targets and track progress. Link goals to tags for automatic amount calculation.'), doc: 'savings-goals' },
    'debt-payoff': { title: () => t('budget', 'Debt Payoff'), summary: () => t('budget', 'Plan debt repayment using avalanche (highest interest first) or snowball (smallest balance first) strategies.'), doc: 'debt-payoff' },
    pensions: { title: () => t('budget', 'Pensions'), summary: () => t('budget', 'Track retirement accounts with contributions and growth projections.'), doc: 'pensions' },
    assets: { title: () => t('budget', 'Assets'), summary: () => t('budget', 'Track non-liquid assets like property, vehicles, and collectibles with value snapshots over time.'), doc: 'assets' },
    'shared-expenses': { title: () => t('budget', 'Shared Expenses'), summary: () => t('budget', 'Split expenses with contacts and track who owes whom. Record settlements to clear debts.'), doc: 'shared-expenses' },
    forecast: { title: () => t('budget', 'Forecast'), summary: () => t('budget', 'Predict future account balances using historical spending patterns and scenario modeling.'), doc: 'forecast' },
    reports: { title: () => t('budget', 'Reports'), summary: () => t('budget', 'Six report types: budget analysis, spending by category, income vs expenses, year-over-year, bills calendar, and net worth history.'), doc: 'reports' },
    import: { title: () => t('budget', 'Import'), summary: () => t('budget', 'Import bank statements from CSV, OFX, or QIF files. Auto-detects delimiters and supports European number formats.'), doc: 'import' },
    rules: { title: () => t('budget', 'Rules'), summary: () => t('budget', 'Auto-categorise transactions with pattern-based rules. Build complex criteria with AND/OR/NOT logic and preview matches.'), doc: 'rules' },
    'exchange-rates': { title: () => t('budget', 'Exchange Rates'), summary: () => t('budget', 'View and manage currency exchange rates. ECB rates for fiat, CoinGecko for crypto. Add manual overrides.'), doc: 'exchange-rates' },
    sharing: { title: () => t('budget', 'Sharing'), summary: () => t('budget', 'Share your budget data with other Nextcloud users for household or team financial management.'), doc: 'sharing' },
    'bank-sync': { title: () => t('budget', 'Bank Sync'), summary: () => t('budget', 'Connect external bank accounts for automatic transaction imports via GoCardless (UK/EU) or SimpleFIN (US). Beta feature.'), doc: 'bank-sync' },
    'receipt-scanning': { title: () => t('budget', 'Receipt Scanning'), summary: () => t('budget', 'Choose who will read photographed receipts — a local model, this server\'s AI provider, or a hosted service. Off by default; the scan buttons themselves arrive in the next release.'), doc: 'receipt-scanning' },
    api: { title: () => t('budget', 'REST API'), summary: () => t('budget', 'Read your budget and record transactions from outside the web UI — a phone app, a script, or an automation workflow. Authenticates with a Nextcloud app password.'), doc: 'api' },
    settings: { title: () => t('budget', 'Settings'), summary: () => t('budget', 'Configure currency, date format, number format, notifications, import preferences, security, and data migration.'), doc: 'settings' },
    help: { title: () => t('budget', 'Help & Docs'), summary: () => t('budget', 'Every guide, the Quick Add page URL, and the system information to paste into a bug report.'), doc: 'help' },
};

export function helpDocUrl(doc) {
    return `${DOCS_BASE}/${doc}.html`;
}

/** The docs hub — the site's index of every guide. */
export const DOCS_INDEX_URL = `${DOCS_BASE}/`;

export default class HelpModule {
    constructor(app) {
        this.app = app;
        this.quickAddCopyBound = false;
    }

    /**
     * Router entry point for the Help & Docs view.
     */
    loadHelpView() {
        this.renderDocLinks();
        this.setupQuickAddUrlCopy();
        this.setupShortcutsButton();
        this.setupReportIssueButton();
        this.loadSystemInfo();
    }

    /**
     * One card per documented topic, in the order they appear in HELP_TOPICS.
     * The Help & Docs entry itself is skipped — it would only link to the
     * page you are already on.
     */
    renderDocLinks() {
        const grid = document.getElementById('help-docs-grid');
        if (!grid) return;

        grid.innerHTML = Object.entries(HELP_TOPICS)
            .filter(([view]) => view !== 'help')
            .map(([, topic]) => `
                <a class="help-doc-card" href="${helpDocUrl(topic.doc)}" target="_blank" rel="noopener">
                    <span class="help-doc-title">${topic.title()}</span>
                    <span class="help-doc-summary">${topic.summary()}</span>
                </a>
            `).join('');
    }

    setupShortcutsButton() {
        const btn = document.getElementById('help-view-shortcuts-btn');
        if (!btn || btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => this.app.keyboardShortcuts.openShortcuts());
    }

    setupReportIssueButton() {
        this.bindExternalLink('help-report-issue-btn', 'https://github.com/otherworld-dev/budget/issues');
        this.bindExternalLink('help-docs-site-btn', DOCS_INDEX_URL);
    }

    /** Header buttons that open an external page, bound once. */
    bindExternalLink(id, url) {
        const btn = document.getElementById(id);
        if (!btn || btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => window.open(url, '_blank', 'noopener'));
    }

    setupQuickAddUrlCopy() {
        const copyBtn = document.getElementById('copy-quick-add-url');
        const urlInput = document.getElementById('quick-add-url');
        if (!copyBtn || !urlInput || this.quickAddCopyBound) return;
        this.quickAddCopyBound = true;

        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(urlInput.value).then(() => {
                showSuccess(t('budget', 'URL copied to clipboard'));
            }).catch(() => {
                urlInput.select();
                document.execCommand('copy');
                showSuccess(t('budget', 'URL copied to clipboard'));
            });
        });
    }

    async loadSystemInfo() {
        const container = document.getElementById('system-info-content');
        if (!container) return;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/setup/system-info'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load');
            const info = await response.json();

            const browser = `${navigator.userAgent.match(/(?:Firefox|Chrome|Safari|Edge)\/[\d.]+/)?.[0] || navigator.userAgent.substring(0, 50)}`;
            const diag = window.budgetDiagnostics || { errors: [], failedRequests: [] };

            const lines = [
                ['Budget Version', info.appVersion],
                ['Nextcloud Version', info.nextcloudVersion],
                ['PHP Version', info.phpVersion],
                ['Database', info.database],
                ['Browser', browser],
                ['Accounts', info.accounts],
                ['Transactions', info.transactions],
                ['Categories', info.categories],
                ['Rules', `${info.activeRules} active / ${info.rules} total`],
                ['Bills', info.bills],
                ['Bank Sync', info.bankSyncConnections > 0 ? `${info.bankSyncConnections} connection(s)` : 'None'],
                ['Sharing', info.sharingOut > 0 || info.sharingIn > 0
                    ? `${info.sharingOut} outgoing, ${info.sharingIn} incoming`
                    : 'None'],
                ['Screen', `${window.screen.width}x${window.screen.height} (viewport: ${window.innerWidth}x${window.innerHeight})`],
            ];

            let html = `<table class="system-info-table">${lines.map(([label, value]) =>
                `<tr><td class="system-info-label">${label}</td><td class="system-info-value">${value}</td></tr>`
            ).join('')}</table>`;

            // Failed API requests
            if (diag.failedRequests.length > 0) {
                html += `<h4 class="system-info-subhead">${t('budget', 'Failed API Requests')} (${diag.failedRequests.length})</h4>`;
                html += `<div class="system-info-log">${diag.failedRequests.map(r =>
                    `<div class="log-entry error">${r.time.substring(11, 19)} ${r.method} ${r.url} → ${r.status}</div>`
                ).join('')}</div>`;
            }

            // JS Errors
            if (diag.errors.length > 0) {
                html += `<h4 class="system-info-subhead">${t('budget', 'JavaScript Errors')} (${diag.errors.length})</h4>`;
                html += `<div class="system-info-log">${diag.errors.map(e =>
                    `<div class="log-entry error">${e.time.substring(11, 19)} ${e.message}${e.source ? ' (' + e.source + ':' + e.line + ')' : ''}</div>`
                ).join('')}</div>`;
            }

            if (diag.failedRequests.length === 0) {
                html += `<p class="system-info-ok">&#10004; ${t('budget', 'No failed API requests this session')}</p>`;
            }
            if (diag.errors.length === 0) {
                html += `<p class="system-info-ok">&#10004; ${t('budget', 'No JavaScript errors this session')}</p>`;
            }

            // Server logs (admin only)
            if (info.serverLogs && info.serverLogs.length > 0) {
                const levelMap = { 0: 'DEBUG', 1: 'INFO', 2: 'WARN', 3: 'ERROR', 4: 'FATAL' };
                html += `<h4 class="system-info-subhead">${t('budget', 'Server Logs (Budget)')} (${info.serverLogs.length})</h4>`;
                html += `<div class="system-info-log">${info.serverLogs.map(l =>
                    `<div class="log-entry ${l.level >= 3 ? 'error' : ''}">${l.time.substring(11, 19)} [${levelMap[l.level] || l.level}] ${l.message}</div>`
                ).join('')}</div>`;
            } else if (info.serverLogs !== undefined) {
                html += `<p class="system-info-ok">&#10004; ${t('budget', 'No server errors logged')}</p>`;
            }

            container.innerHTML = html;

            // Build clipboard text
            let clipText = lines.map(([l, v]) => `${l}: ${v}`).join('\n');
            clipText += '\n\nFailed API Requests: ' + (diag.failedRequests.length === 0 ? 'None' : diag.failedRequests.length);
            if (diag.failedRequests.length > 0) {
                clipText += '\n' + diag.failedRequests.map(r =>
                    `  ${r.time.substring(11, 19)} ${r.method} ${r.url} → ${r.status}`
                ).join('\n');
            }
            clipText += '\nJavaScript Errors: ' + (diag.errors.length === 0 ? 'None' : diag.errors.length);
            if (diag.errors.length > 0) {
                clipText += '\n' + diag.errors.map(e =>
                    `  ${e.time.substring(11, 19)} ${e.message}${e.source ? ' (' + e.source + ':' + e.line + ')' : ''}`
                ).join('\n');
            }
            if (info.serverLogs !== undefined) {
                const levelMap = { 0: 'DEBUG', 1: 'INFO', 2: 'WARN', 3: 'ERROR', 4: 'FATAL' };
                clipText += '\nServer Logs: ' + (info.serverLogs.length === 0 ? 'None' : info.serverLogs.length);
                if (info.serverLogs.length > 0) {
                    clipText += '\n' + info.serverLogs.map(l =>
                        `  ${l.time.substring(11, 19)} [${levelMap[l.level] || l.level}] ${l.message}`
                    ).join('\n');
                }
            }
            container.dataset.plaintext = clipText;

            // Copy button
            const copyBtn = document.getElementById('copy-system-info-btn');
            if (copyBtn) {
                copyBtn.onclick = () => {
                    navigator.clipboard.writeText(container.dataset.plaintext).then(() => {
                        showSuccess(t('budget', 'Copied to clipboard'));
                    }).catch(() => {
                        // Fallback
                        const ta = document.createElement('textarea');
                        ta.value = container.dataset.plaintext;
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        ta.remove();
                        showSuccess(t('budget', 'Copied to clipboard'));
                    });
                };
            }
        } catch (error) {
            container.innerHTML = `<p>${t('budget', 'Failed to load system info')}</p>`;
        }
    }
}

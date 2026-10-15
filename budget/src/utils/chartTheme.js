/**
 * Chart.js text and grid colours from the Nextcloud theme.
 *
 * Chart.js draws on a canvas, so CSS does not reach it: left alone, every
 * axis label, legend and grid line is its built-in grey, which is too faint on
 * the dark theme. The defaults are set once here from Nextcloud's own
 * variables, so every chart that does not pick its own colours follows the
 * theme.
 */

const FALLBACK_TEXT = '#6b6b6b';
const FALLBACK_BORDER = '#dbdbdb';

/**
 * Read a CSS custom property. Nextcloud's theme variables are defined for
 * :root and re-defined on <body> by the dark themes, so read from body.
 *
 * @param {string} name
 * @param {string} fallback
 * @returns {string}
 */
function themeVar(name, fallback) {
    const el = document.body || document.documentElement;
    const value = getComputedStyle(el).getPropertyValue(name).trim();
    return value || fallback;
}

/**
 * Point Chart.js's default text and border colours at the current theme.
 *
 * @param {object} Chart - the Chart.js constructor (its `defaults` are shared)
 */
export function applyChartTheme(Chart) {
    Chart.defaults.color = themeVar('--color-text-maxcontrast', FALLBACK_TEXT);
    Chart.defaults.borderColor = themeVar('--color-border', FALLBACK_BORDER);
}

/**
 * Apply the theme now, and again if the system colour scheme flips while the
 * page is open (Nextcloud's "system default" theme follows it live). Charts
 * already on screen are redrawn so they pick the new colours up.
 *
 * @param {object} Chart - the Chart.js constructor
 */
export function setupChartTheme(Chart) {
    applyChartTheme(Chart);
    if (typeof window.matchMedia !== 'function') return;
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
        applyChartTheme(Chart);
        Object.values(Chart.instances || {}).forEach(chart => {
            try {
                chart.update('none');
            } catch (e) {
                // A chart whose canvas has gone is not worth failing over.
            }
        });
    };
    if (typeof query.addEventListener === 'function') {
        query.addEventListener('change', onChange);
    } else if (typeof query.addListener === 'function') {
        query.addListener(onChange);
    }
}

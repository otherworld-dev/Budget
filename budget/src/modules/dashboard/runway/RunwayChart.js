/**
 * The runway chart: the total over the last year as a solid line, then a
 * dashed projection to zero for current spending (the theme's primary
 * colour) and for spending on budget (the warning colour), diamonds on the
 * planned one-offs and a dotted line at today.
 */

import { translate as t } from '@nextcloud/l10n';
import Chart from '../../../utils/chart.js';
import { chartDatasets, eventMarkers } from '../../../utils/runway.js';
import { runwayContext, cssVar, el, money, moneyCompact, longDate, shortMonth } from './runwayData.js';

/**
 * Chart.js has no "today" marker without the annotation plugin, which the
 * app doesn't ship, so this draws one: a dotted vertical line with a label.
 *
 * @param {number} index today's position on the category axis
 * @param {string} colour
 * @returns {object} an inline Chart.js plugin
 */
function todayLine(index, colour) {
    return {
        id: 'runwayToday',
        afterDatasetsDraw(chart) {
            const x = chart.scales?.x?.getPixelForValue(index);
            const area = chart.chartArea;
            if (index < 0 || !area || !Number.isFinite(x)) return;
            const ctx = chart.ctx;
            ctx.save();
            ctx.strokeStyle = colour;
            ctx.fillStyle = colour;
            ctx.lineWidth = 1;
            ctx.setLineDash([2, 3]);
            ctx.beginPath();
            ctx.moveTo(x, area.top);
            ctx.lineTo(x, area.bottom);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.font = '11px sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText(t('budget', 'Today'), x + 4, area.top + 10);
            ctx.restore();
        },
    };
}

/**
 * Draws the chart into `host`, replacing any chart already registered under
 * `chartKey`.
 *
 * @param {HTMLElement} host
 * @param {object} data the runway payload
 * @param {string} chartKey where the dashboard keeps it (one per tile)
 */
export function drawRunwayChart(host, data, chartKey) {
    const charts = runwayContext().charts() || {};
    if (charts[chartKey]) {
        charts[chartKey].destroy?.();
        delete charts[chartKey];
    }

    const series = data.series || {};
    const points = (series.history?.length || 0) + (series.current?.length || 0);
    if (points < 2 || !data.accounts?.length) {
        host.appendChild(el('div', 'chart-empty-state runway-chart-empty', t('budget', 'Nothing to draw yet')));
        return;
    }

    const wrap = el('div', 'chart-container runway-chart');
    const canvas = document.createElement('canvas');
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', t('budget', 'Runway forecast chart'));
    wrap.appendChild(canvas);
    host.appendChild(wrap);

    const primary = cssVar(wrap, '--color-primary-element', '#0082c9');
    const onBudget = cssVar(wrap, '--color-warning', '#e65100');
    const muted = cssVar(wrap, '--color-text-maxcontrast', '#6b6b6b');
    const background = cssVar(wrap, '--color-main-background', '#ffffff');
    const currency = data.currency;

    const datasets = chartDatasets(series, [primary, onBudget]).map((set) => ({ ...set, borderWidth: 2 }));
    const oneOffs = (data.events || []).filter((event) => event.source === 'oneOff');
    const markers = eventMarkers(data.events, series.current);
    if (markers.length) {
        datasets.push({
            label: t('budget', 'Planned one-off'),
            data: markers.map((marker) => ({ x: marker.x, y: marker.y })),
            showLine: false,
            pointStyle: 'rectRot',
            pointRadius: 6,
            pointHoverRadius: 8,
            borderWidth: 2,
            borderColor: primary,
            backgroundColor: background,
            runwayOneOffs: true,
        });
    }

    // A category axis of every date any line has, in order, so the lines
    // share one axis and today sits where both meet.
    const labels = [...new Set(datasets.flatMap((set) => set.data.map((point) => point.x)))].sort();
    const todayDate = series.current?.[0]?.date ?? series.history?.[series.history.length - 1]?.date;

    const chart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { boxWidth: 18, boxHeight: 2, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        title: (items) => longDate(items?.[0]?.raw?.x),
                        label: (ctx) => {
                            if (ctx.dataset.runwayOneOffs) {
                                const event = oneOffs[ctx.dataIndex];
                                return event ? `${event.description}: ${money(event.amount, currency)}` : '';
                            }
                            return `${ctx.dataset.label}: ${money(ctx.parsed.y, currency)}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        autoSkip: true,
                        maxTicksLimit: 8,
                        maxRotation: 0,
                        callback: (value) => shortMonth(labels[value]),
                    },
                },
                y: {
                    beginAtZero: true,
                    ticks: { callback: (value) => moneyCompact(value, currency) },
                },
            },
        },
        plugins: [todayLine(labels.indexOf(todayDate), muted)],
    });
    charts[chartKey] = chart;
}

/**
 * Chart.js with only the parts this app draws.
 *
 * `chart.js/auto` registers every chart type, scale and plugin Chart.js ships
 * — radar, polar area, bubble, scatter, the time scales, decimation — and all
 * of it went into the bundle every page load pays for. The app draws line,
 * bar and doughnut charts on category and linear axes (the Sankey report
 * registers its own controller), so those are what is registered here. A new
 * chart type or scale has to be added to this list, or Chart.js throws
 * '"x" is not a registered controller' when the chart is created.
 */

import {
    Chart,
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Colors,
    DoughnutController,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';

Chart.register(
    LineController,
    BarController,
    DoughnutController,
    LineElement,
    PointElement,
    BarElement,
    ArcElement,
    CategoryScale,
    LinearScale,
    Filler,
    Legend,
    Tooltip,
    Title,
    Colors,
);

// Curved lines are drawn monotone: a plain `tension` curve overshoots between
// points, so a month's line could dip below zero or peak above a value that
// never happened. Monotone curves pass through the points without that.
Chart.defaults.elements.line.cubicInterpolationMode = 'monotone';

export default Chart;

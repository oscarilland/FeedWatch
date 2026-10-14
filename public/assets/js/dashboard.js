// The live dashboard (FR-05): polls the API every 2 seconds, shows "stale"
// when it can't, and fills in the tiles, list, map, alert feed and detail.
// All server text goes into the page with textContent, never as HTML.

import { basePath, getJson } from './api.js';
import { bannerText } from './chaos.js';
import { formatClock, formatCount, formatDuration, formatMs, formatType } from './format.js';
import { createMap } from './map.js';
import { initialPollState, nextPollState, POLL_MS } from './poll-state.js';
import { latestSecond, lineFor, mergeSeconds } from './series.js';
import { bySeverity, changedStatuses, statusElement } from './status.js';

const ALERTS_EVERY_MS = 10000;
const DETAIL_EVERY_MS = 5000;

const base = basePath(document);
const $ = (id) => document.getElementById(id);
const main = document.querySelector('.dashboard');

let pollState = initialPollState();
let exchanges = [];
let selected = null;
let points = [];
let chart = null;
let lastDetailAt = 0;
let pollTimer = null;
let lastFocused = null;
let openedFromUrl = false;

const map = createMap($('map'), (code) => select(code));

// Phone: list or map, starting with the map the page was rendered with.
// Wider screens show both (CSS).
main.dataset.view = main.dataset.view === 'list' ? 'list' : 'map';
for (const [id, view] of [['show-list', 'list'], ['show-map', 'map']]) {
    $(id).addEventListener('click', () => {
        main.dataset.view = view;
        $('show-list').setAttribute('aria-pressed', String(view === 'list'));
        $('show-map').setAttribute('aria-pressed', String(view === 'map'));
        if (view === 'map') {
            map.invalidate();
            if (selected) {
                map.reveal(selected);
            }
        }
    });
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function renderTiles(summary) {
    $('tile-healthy').textContent = `${summary.healthy} / ${summary.exchanges}`;
    $('tile-incidents').textContent = formatCount(summary.open_incidents);
    $('tile-rate').textContent = formatCount(summary.messages_per_second);
    $('tile-lost').textContent = formatCount(summary.messages_lost_today);
}

function renderList() {
    const list = $('exchange-list');
    list.replaceChildren(...bySeverity(exchanges).map((exchange) => {
        const button = el('button', 'exchange-button');
        button.type = 'button';
        button.dataset.code = exchange.code;
        button.setAttribute('aria-expanded', String(exchange.code === selected));
        button.setAttribute('aria-controls', 'detail');
        button.append(
            el('span', 'exchange-button__code', exchange.code),
            el('span', 'exchange-button__name', `${exchange.name}, ${exchange.city}`),
            el('span', 'exchange-button__latency', `A ${formatMs(exchange.latency_5s_ms?.A)}`),
        );
        const status = statusElement(document, exchange.status, exchange.status_reason);
        status.classList.add('exchange-button__status');
        button.append(status);
        button.addEventListener('click', () => select(exchange.code));
        const item = el('li');
        item.append(button);

        return item;
    }));
}

function announce(changes) {
    if (changes.length > 0) {
        $('announcer').textContent = changes.map((e) => `${e.code}: ${e.status_reason}`).join('. ');
    }
}

async function poll() {
    clearTimeout(pollTimer);
    if (document.hidden) {
        // Hidden tabs don't poll, so idle mode can let the server rest.
        pollTimer = setTimeout(poll, POLL_MS);

        return;
    }
    try {
        const data = await getJson(`${base}/api/exchanges`);
        const changes = changedStatuses(exchanges, data.exchanges);
        exchanges = data.exchanges;
        pollState = nextPollState(pollState, true, Date.now());
        renderTiles(data.summary);
        renderChaosBanner(data.faults);
        renderList();
        map.update(exchanges, selected, new Set(changes.map((e) => e.code)));
        announce(changes);
        // ?exchange=THX opens that exchange's detail, for links in a demo.
        const wanted = new URLSearchParams(window.location.search).get('exchange');
        if (!openedFromUrl && wanted && exchanges.some((e) => e.code === wanted)) {
            openedFromUrl = true;
            select(wanted, false);
        } else if (selected) {
            await refreshDetail();
        }
    } catch {
        pollState = nextPollState(pollState, false, Date.now());
    }
    renderStale();
    pollTimer = setTimeout(poll, pollState.nextDelayMs);
}

/** "Simulated fault in progress" for every visitor while chaos is active (ADR-007). */
function renderChaosBanner(faults) {
    const banner = $('chaos-banner');
    const text = bannerText(faults, formatClock);
    banner.textContent = text || '';
    banner.hidden = text === null;
}

function renderStale() {
    const banner = $('stale-banner');
    if (pollState.stale) {
        banner.textContent = `Data stale since ${formatClock(new Date(pollState.staleSince))}. Retrying...`;
        banner.hidden = false;
    } else {
        banner.hidden = true;
    }
}

async function pollAlerts() {
    try {
        const data = await getJson(`${base}/api/incidents?limit=8`);
        const feed = $('alert-feed');
        feed.replaceChildren(...data.incidents.map((incident) => {
            const item = el('li', `alert-item alert-item--${incident.severity}`);
            const link = el('a', '', `${incident.exchange} ${formatType(incident.type)}${incident.feed === 'AB' ? '' : ` on ${incident.feed}`}`);
            link.href = `${base}/incidents?id=${encodeURIComponent(incident.id)}`;
            const meta = el('p', 'alert-item__meta',
                `${incident.severity} - ${formatClock(new Date(incident.opened_at))} - ${incident.ongoing ? 'ongoing' : formatDuration(incident.duration_s)}`);
            item.append(link, meta);

            return item;
        }));
        $('alert-empty').hidden = data.incidents.length > 0;
    } catch {
        // The stale banner already covers a lost connection.
    }
    setTimeout(pollAlerts, ALERTS_EVERY_MS);
}

/**
 * @param {boolean} moveFocus false when opened from a link, so focus stays where the page put it
 */
function select(code, moveFocus = true) {
    if (selected !== code) {
        points = [];
        lastDetailAt = 0;
    }
    lastFocused = document.activeElement;
    selected = code;
    $('detail').hidden = false;
    renderList();
    map.update(exchanges, selected, new Set());
    map.reveal(code);
    refreshDetail().then(() => {
        if (moveFocus) {
            const heading = $('detail-heading');
            heading.tabIndex = -1;
            heading.focus();
        }
    });
}

function closeDetail() {
    selected = null;
    $('detail').hidden = true;
    renderList();
    map.update(exchanges, null, new Set());
    const back = document.querySelector(`.exchange-button[data-code="${CSS.escape(lastFocusedCode())}"]`);
    (back || lastFocused)?.focus?.();
}

function lastFocusedCode() {
    return lastFocused?.dataset?.code || '';
}

$('detail-close').addEventListener('click', closeDetail);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && selected) {
        closeDetail();
    }
});

async function refreshDetail() {
    const exchange = exchanges.find((e) => e.code === selected);
    if (!exchange) {
        return;
    }
    $('detail-heading').textContent = `${exchange.code} ${exchange.name}, ${exchange.city}`;
    $('detail-status').replaceChildren(statusElement(document, exchange.status, exchange.status_reason));

    $('detail-feeds').replaceChildren(...['A', 'B'].map((feed) => {
        const card = el('div', 'feed-card');
        const silent = exchange.silent_feeds.includes(feed);
        card.append(
            el('p', '', `Feed ${feed}${silent ? ' (silent)' : ''}`),
            el('p', 'feed-card__value', formatMs(exchange.latency_5s_ms?.[feed])),
            el('p', 'tile__label', `baseline ${formatMs(exchange.baseline_ms?.[feed])}`),
        );

        return card;
    }));

    const since = latestSecond(points);
    try {
        const latency = await getJson(`${base}/api/latency?exchange=${encodeURIComponent(exchange.code)}${since === null ? '' : `&since=${since}`}`);
        points = mergeSeconds(points, latency.seconds, Math.floor(Date.now() / 1000));
        drawChart();
    } catch {
        // Keep the chart as it is; the stale banner covers a lost connection.
    }
    $('latency-summary').textContent = `Latest: A ${formatMs(exchange.latency_5s_ms?.A)} (baseline ${formatMs(exchange.baseline_ms?.A)}), `
        + `B ${formatMs(exchange.latency_5s_ms?.B)} (baseline ${formatMs(exchange.baseline_ms?.B)}).`;

    if (Date.now() - lastDetailAt >= DETAIL_EVERY_MS) {
        lastDetailAt = Date.now();
        try {
            renderDetailExtras(await getJson(`${base}/api/exchange?code=${encodeURIComponent(exchange.code)}`));
        } catch {
            // As above.
        }
    }
}

function renderDetailExtras(detail) {
    const win = detail.win_share;
    const share = $('win-share');
    if (win.accepted === 0) {
        share.replaceChildren(el('p', 'empty', 'No arrivals in the last minute.'));
    } else {
        const bar = el('div', 'win-share__bar');
        bar.setAttribute('aria-hidden', 'true');
        const a = el('span', 'win-share__a');
        a.style.width = `${win.a_percent}%`;
        const b = el('span', 'win-share__b');
        b.style.width = `${win.b_percent}%`;
        bar.append(a, b);
        share.replaceChildren(
            bar,
            el('p', '', `A won ${win.a_percent}% and B won ${win.b_percent}% of ${formatCount(win.accepted)} messages. ${formatCount(win.duplicates)} second copies dropped.`),
        );
    }

    $('recent-gaps').replaceChildren(...detail.recent_gaps.map((gap) => el('li', '',
        `${gap.classification === 'data_loss' ? 'Data loss' : `Line gap on ${gap.scope}`}: ${gap.count} message${gap.count === 1 ? '' : 's'} `
        + `(${gap.first}-${gap.last}) at ${formatClock(new Date(gap.detected_at))}`)));
    $('gaps-empty').hidden = detail.recent_gaps.length > 0;
}

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function drawChart() {
    const Chart = window.Chart;
    if (!Chart) {
        return;
    }
    const datasets = [
        { label: 'Feed A', data: lineFor(points, 'A'), borderColor: cssVar('--color-series-a'), backgroundColor: cssVar('--color-series-a') },
        { label: 'Feed B', data: lineFor(points, 'B'), borderColor: cssVar('--color-series-b'), backgroundColor: cssVar('--color-series-b'), borderDash: [6, 3] },
    ];
    if (chart) {
        chart.data.datasets[0].data = datasets[0].data;
        chart.data.datasets[1].data = datasets[1].data;
        chart.update('none');

        return;
    }
    const text = cssVar('--color-text-muted');
    const grid = cssVar('--color-border');
    chart = new Chart($('latency-chart'), {
        type: 'line',
        data: { datasets: datasets.map((d) => ({ ...d, pointRadius: 0, borderWidth: 2, spanGaps: false })) },
        options: {
            animation: false,
            maintainAspectRatio: false,
            parsing: false,
            interaction: { mode: 'nearest', intersect: false },
            scales: {
                x: { type: 'linear', ticks: { color: text, maxTicksLimit: 6, callback: (v) => formatClock(new Date(v * 1000)).slice(0, 5) }, grid: { color: grid } },
                y: { beginAtZero: true, title: { display: true, text: 'ms', color: text }, ticks: { color: text }, grid: { color: grid } },
            },
            plugins: { legend: { labels: { color: text } }, tooltip: { callbacks: { title: (items) => formatClock(new Date(items[0].parsed.x * 1000)) } } },
        },
    });
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        poll();
    }
});

poll();
pollAlerts();

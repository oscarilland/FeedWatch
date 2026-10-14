// The incident log page (FR-06 AC-3, AC-4): filters, a newest-first list,
// and a timeline for the chosen incident. Filters and the chosen incident
// live in the URL, so a view can be shared or reloaded.

import { basePath, getJson } from './api.js';
import { formatClock, formatDuration, formatType } from './format.js';

const base = basePath(document);
const $ = (id) => document.getElementById(id);
const form = $('filters');

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

/** A date field (yyyy-mm-dd) as Unix seconds at midnight UTC. */
function dateToUnix(value) {
    return value ? Math.floor(Date.parse(`${value}T00:00:00Z`) / 1000) : null;
}

function filtersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    for (const name of ['exchange', 'type', 'from', 'to']) {
        if (form.elements[name]) {
            form.elements[name].value = params.get(name) || '';
        }
    }

    return params;
}

function apiQuery() {
    const query = new URLSearchParams({ limit: '100' });
    const exchange = form.elements.exchange.value;
    const type = form.elements.type.value;
    const from = dateToUnix(form.elements.from.value);
    const to = dateToUnix(form.elements.to.value);
    if (exchange) query.set('exchange', exchange);
    if (type) query.set('type', type);
    if (from !== null) query.set('from', String(from));
    // "To" includes the whole of that day.
    if (to !== null) query.set('to', String(to + 86400));

    return query;
}

async function loadList(selectedId) {
    $('incidents-loading').hidden = false;
    try {
        const data = await getJson(`${base}/api/incidents?${apiQuery()}`);
        $('incident-list').replaceChildren(...data.incidents.map((incident) => {
            const link = el('a', 'incident-link');
            link.href = `?${new URLSearchParams({ ...Object.fromEntries(new URLSearchParams(window.location.search)), id: String(incident.id) })}`;
            if (String(incident.id) === selectedId) {
                link.setAttribute('aria-current', 'true');
            }
            link.append(
                el('strong', '', `${incident.exchange} ${formatType(incident.type)}${incident.feed === 'AB' ? '' : ` on ${incident.feed}`}`),
                el('span', '', ' '),
                el('span', 'badge', incident.severity),
                el('p', 'alert-item__meta', `${new Date(incident.opened_at).toLocaleString('en-GB')} - ${incident.ongoing ? 'ongoing' : formatDuration(incident.duration_s)}`),
            );
            link.addEventListener('click', (event) => {
                event.preventDefault();
                history.pushState(null, '', link.href);
                route();
            });
            const item = el('li');
            item.append(link);

            return item;
        }));
        $('incidents-empty').hidden = data.incidents.length > 0;
    } catch {
        $('incident-list').replaceChildren();
        $('incidents-empty').textContent = 'Incidents could not be loaded. Try again shortly.';
        $('incidents-empty').hidden = false;
    }
    $('incidents-loading').hidden = true;
}

async function loadDetail(id) {
    const panel = $('incident-detail');
    if (!id) {
        panel.hidden = true;

        return;
    }
    panel.hidden = false;
    try {
        const incident = await getJson(`${base}/api/incident?id=${encodeURIComponent(id)}`);
        $('incident-error').hidden = true;
        $('incident-detail-heading').textContent = `${incident.exchange} ${formatType(incident.type)}${incident.feed === 'AB' ? '' : ` on feed ${incident.feed}`}`;
        $('incident-summary').textContent = incident.ongoing
            ? `Ongoing for ${formatDuration(incident.duration_s)}${incident.clearing ? ', clearing' : ''}.`
            : `Lasted ${formatDuration(incident.duration_s)}.`;

        const facts = [
            ['Severity', incident.severity],
            ['Opened', new Date(incident.opened_at).toLocaleString('en-GB')],
            ['Closed', incident.closed_at ? new Date(incident.closed_at).toLocaleString('en-GB') : 'Not yet'],
        ];
        if (incident.details.messages_lost !== undefined) facts.push(['Messages lost', String(incident.details.messages_lost)]);
        if (incident.details.peak_latency_ms !== undefined) facts.push(['Peak latency', `${incident.details.peak_latency_ms.toFixed(2)} ms`]);
        if (incident.details.line_gaps !== undefined) facts.push(['Line gaps', String(incident.details.line_gaps)]);
        $('incident-facts').replaceChildren(...facts.flatMap(([term, value]) => [el('dt', '', term), el('dd', '', value)]));

        $('incident-timeline').replaceChildren(...incident.timeline.map((event) => {
            const item = el('li');
            const time = el('time', '', formatClock(new Date(event.at)));
            time.dateTime = event.at;
            item.append(time, el('span', '', event.text));

            return item;
        }));

        $('incident-notes').replaceChildren(...incident.notes.map((note) => el('li', '',
            `${new Date(note.at).toLocaleString('en-GB')} - ${note.user}: ${note.text}`)));
        $('notes-empty').hidden = incident.notes.length > 0;
        $('note-form').hidden = session.csrf === undefined;
        $('note-form').dataset.id = String(incident.id);
    } catch (error) {
        $('note-form').hidden = true;
        $('incident-detail-heading').textContent = 'Incident';
        $('incident-summary').textContent = '';
        $('incident-facts').replaceChildren();
        $('incident-timeline').replaceChildren();
        $('incident-error').textContent = error.status === 404 ? "That incident doesn't exist." : 'The incident could not be loaded. Try again shortly.';
        $('incident-error').hidden = false;
    }
}

function route() {
    const params = filtersFromUrl();
    const id = params.get('id');
    loadList(id);
    loadDetail(id);
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    const params = new URLSearchParams();
    for (const name of ['exchange', 'type', 'from', 'to']) {
        if (form.elements[name].value) params.set(name, form.elements[name].value);
    }
    history.pushState(null, '', `?${params}`);
    route();
});

// The admin can add notes (FR-06). Visitors get no session and no form.
let session = {};
$('note-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const noteForm = event.target;
    const message = $('note-message');
    try {
        const response = await fetch(`${base}/api/incident-note`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': session.csrf || '', 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            body: new URLSearchParams({ id: noteForm.dataset.id || '', text: noteForm.elements.text.value }),
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }
        noteForm.elements.text.value = '';
        message.textContent = 'Note added.';
        loadDetail(noteForm.dataset.id);
    } catch (error) {
        message.textContent = `Not added: ${error.message}`;
    }
    message.hidden = false;
});

window.addEventListener('popstate', route);
getJson(`${base}/api/session`).then((data) => { session = data; }).catch(() => {}).finally(route);

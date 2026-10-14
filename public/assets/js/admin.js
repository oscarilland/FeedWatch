// The chaos panel (FR-07): presets, custom faults, restore, thresholds and
// the admin log. Every change is a POST with the CSRF token in a header.

import { basePath, getJson } from './api.js';
import { describeFault } from './chaos.js';
import { formatClock } from './format.js';

const base = basePath(document);
const $ = (id) => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

function say(text, isError = false) {
    const message = $('admin-message');
    message.textContent = text;
    message.classList.toggle('form-message--error', isError);
    message.hidden = false;
}

/** POSTs form fields with the CSRF token; returns the JSON, or throws with the server's error. */
async function post(path, fields) {
    const response = await fetch(`${base}${path}`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: new URLSearchParams(fields),
        credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
}

function renderFaults(faults) {
    $('active-faults').replaceChildren(...faults.map((fault) => {
        const item = el('li', 'fault-item');
        const restore = el('button', 'button-secondary', `Restore ${fault.exchange}`);
        restore.type = 'button';
        restore.addEventListener('click', () => act({ action: 'restore', exchange: fault.exchange }, `${fault.exchange} restored.`));
        item.append(
            el('span', '', `${describeFault(fault)}, until ${formatClock(new Date(fault.expires_at))}`),
            restore,
        );

        return item;
    }));
    $('no-faults').hidden = faults.length > 0;
}

async function act(fields, done) {
    try {
        const data = await post('/api/chaos', fields);
        renderFaults(data.faults);
        say(done);
        loadLog();
    } catch (error) {
        say(`Not done: ${error.message}`, true);
    }
}

for (const button of document.querySelectorAll('[data-preset]')) {
    button.addEventListener('click', () => act({ action: 'preset', name: button.dataset.preset }, `${button.textContent} applied. Watch the dashboard.`));
}
$('restore-all').addEventListener('click', () => act({ action: 'restore_all' }, 'Everything restored.'));
$('custom-fault').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.target;
    act({
        action: 'set',
        exchange: form.elements.exchange.value,
        feed: form.elements.feed.value,
        kind: form.elements.kind.value,
        value: form.elements.kind.value === 'silence' ? '0' : form.elements.value.value,
    }, 'Fault applied.');
});

async function loadFaults() {
    try {
        renderFaults((await getJson(`${base}/api/exchanges`)).faults || []);
    } catch {
        // Shown again after the next action.
    }
}

async function loadLog() {
    try {
        const data = await getJson(`${base}/api/admin-log`);
        $('admin-log').replaceChildren(...data.log.map((entry) => {
            const item = el('li');
            const time = el('time', '', formatClock(new Date(entry.at)));
            time.dateTime = entry.at;
            item.append(time, el('span', '', `${entry.user}: ${entry.action} ${entry.target}${entry.details ? ` (${entry.details})` : ''}`));

            return item;
        }));
    } catch {
        // The log is a convenience; the panel works without it.
    }
}

async function loadSettings() {
    try {
        const data = await getJson(`${base}/api/settings`);
        $('settings').replaceChildren(...data.settings.map((setting) => {
            const row = el('div', 'settings-row');
            const label = el('label', '', setting.name);
            const input = el('input');
            input.type = 'number';
            input.step = 'any';
            input.min = String(setting.min);
            input.max = String(setting.max);
            input.value = setting.value;
            input.name = setting.name;
            input.setAttribute('aria-describedby', `range-${setting.name}`);
            label.append(input);
            const range = el('span', 'tile__label', `${setting.min} to ${setting.max} (default ${setting.default})`);
            range.id = `range-${setting.name}`;
            const save = el('button', 'button-secondary', 'Save');
            save.type = 'button';
            save.addEventListener('click', async () => {
                try {
                    await post('/api/settings', { name: setting.name, value: input.value });
                    say(`${setting.name} set to ${input.value}.`);
                    loadLog();
                } catch (error) {
                    say(`Not saved: ${error.message}`, true);
                }
            });
            row.append(label, range, save);

            return row;
        }));
    } catch {
        $('settings').replaceChildren(el('p', 'empty', 'Settings could not be loaded.'));
    }
}

loadFaults();
loadLog();
loadSettings();

// Status language (UX design direction): every status has a shape and a
// label as well as a colour, so it survives greyscale (FR-05 AC-3).

export const STATUSES = {
    down: { label: 'Down', shape: 'square', rank: 0 },
    degraded: { label: 'Degraded', shape: 'triangle', rank: 1 },
    healthy: { label: 'Healthy', shape: 'circle', rank: 2 },
    unknown: { label: 'Unknown', shape: 'dashed circle', rank: 3 },
};

export function statusOf(value) {
    return Object.hasOwn(STATUSES, value) ? value : 'unknown';
}

/** Worst first, then by exchange code, for the phone's list (UX direction). */
export function bySeverity(exchanges) {
    return [...exchanges].sort((x, y) => {
        const rank = STATUSES[statusOf(x.status)].rank - STATUSES[statusOf(y.status)].rank;

        return rank !== 0 ? rank : String(x.code).localeCompare(String(y.code));
    });
}

/** The exchanges whose status changed since the last poll, for pulses and announcements. */
export function changedStatuses(previous, current) {
    const before = new Map(previous.map((e) => [e.code, e.status]));

    return current.filter((e) => before.has(e.code) && before.get(e.code) !== e.status);
}

/**
 * A status element: shape plus label. Built with DOM methods, never HTML
 * strings, so server text can't inject markup.
 */
export function statusElement(document, status, reason) {
    const key = statusOf(status);
    const wrap = document.createElement('span');
    wrap.className = `status status--${key}`;
    const shape = document.createElement('span');
    shape.className = 'status__shape';
    shape.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = reason || STATUSES[key].label;
    wrap.append(shape, text);

    return wrap;
}

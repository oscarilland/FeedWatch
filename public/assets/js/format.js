// Formatting for people. Pure functions, tested in tests/tests.js.

/** "2.04 ms", or an em-dash-free "n/a" when there's no value. */
export function formatMs(ms) {
    return typeof ms === 'number' && Number.isFinite(ms) ? `${ms.toFixed(2)} ms` : 'n/a';
}

/** A clock time in the viewer's time zone, 24-hour: "10:42:13". */
export function formatClock(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** "under 1 s", "45 s", "2 min 14 s", "1 h 3 min" (matches the server's wording). */
export function formatDuration(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    if (s < 1) {
        return 'under 1 s';
    }
    if (s < 60) {
        return `${s} s`;
    }
    if (s < 3600) {
        return `${Math.floor(s / 60)} min ${s % 60} s`;
    }

    return `${Math.floor(s / 3600)} h ${Math.floor((s % 3600) / 60)} min`;
}

/** Whole numbers with thousands separators: "1,234". */
export function formatCount(n) {
    return typeof n === 'number' ? n.toLocaleString('en-GB') : 'n/a';
}

/** "feed_down" -> "Feed down". */
export function formatType(type) {
    const text = String(type).replaceAll('_', ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

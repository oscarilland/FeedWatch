// Tests for the pure JavaScript modules (plan 006). Each check prints a
// PASS or FAIL line; the page title ends as "ALL PASS" or "FAILURES: n".

import { bannerText, describeFault } from '../chaos.js';
import { formatClock, formatCount, formatDuration, formatMs, formatType } from '../format.js';
import { BACKOFF_MS, initialPollState, nextPollState, POLL_MS } from '../poll-state.js';
import { latestSecond, lineFor, mergeSeconds, WINDOW_SECONDS } from '../series.js';
import { bySeverity, changedStatuses, statusElement, statusOf, STATUSES } from '../status.js';

const lines = [];
let failures = 0;

function check(name, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (!ok) {
        failures++;
    }
    lines.push(`${ok ? 'PASS' : 'FAIL'}  ${name}${ok ? '' : `\n      expected ${JSON.stringify(expected)}\n      got      ${JSON.stringify(actual)}`}`);
}

// format.js
check('formatMs shows two decimals', formatMs(2.039), '2.04 ms');
check('formatMs without a value', formatMs(null), 'n/a');
check('formatClock is 24-hour local time', formatClock(new Date(2026, 9, 4, 9, 5, 7)), '09:05:07');
check('formatDuration under a second', formatDuration(0.4), 'under 1 s');
check('formatDuration seconds', formatDuration(45), '45 s');
check('formatDuration minutes', formatDuration(134), '2 min 14 s');
check('formatDuration hours', formatDuration(3780), '1 h 3 min');
check('formatCount separates thousands', formatCount(12345), '12,345');
check('formatType reads naturally', formatType('feed_down'), 'Feed down');

// status.js
check('every status has a shape and a label', Object.values(STATUSES).every((s) => s.shape && s.label), true);
check('an unknown status is "unknown"', statusOf('exploded'), 'unknown');
check('worst first, then by code', bySeverity([
    { code: 'THX', status: 'healthy' },
    { code: 'KTX', status: 'down' },
    { code: 'HDX', status: 'degraded' },
    { code: 'ABC', status: 'healthy' },
]).map((e) => e.code), ['KTX', 'HDX', 'ABC', 'THX']);
check('only changed statuses are reported', changedStatuses(
    [{ code: 'THX', status: 'healthy' }, { code: 'KTX', status: 'healthy' }],
    [{ code: 'THX', status: 'degraded' }, { code: 'KTX', status: 'healthy' }, { code: 'NEW', status: 'down' }],
).map((e) => e.code), ['THX']);
const element = statusElement(document, 'degraded', '<b>Degraded: running on B only</b>');
check('status element has a shape and the reason as text', [element.className, element.firstChild.className, element.lastChild.textContent], ['status status--degraded', 'status__shape', '<b>Degraded: running on B only</b>']);
check('status element never renders server text as HTML', element.querySelector('b'), null);

// poll-state.js
const t0 = 1_000_000;
let state = nextPollState(initialPollState(), true, t0);
check('a good poll is fresh and polls again in 2 s', [state.stale, state.nextDelayMs], [false, POLL_MS]);
state = nextPollState(state, false, t0 + 2000);
check('a failed poll is stale since the last good data and backs off', [state.stale, state.staleSince, state.nextDelayMs], [true, t0, BACKOFF_MS]);
state = nextPollState(state, false, t0 + 7000);
check('stale-since stays at the first loss of data', [state.staleSince, state.failures], [t0, 2]);
state = nextPollState(state, true, t0 + 12000);
check('it recovers on the next good poll', [state.stale, state.staleSince, state.failures], [false, null, 0]);
check('stale with no good data yet starts now', nextPollState(initialPollState(), false, t0).staleSince, t0);

// series.js
const now = 2_000_000;
const p = (t, a, b) => ({ t, A: a === null ? null : { mean_ms: a }, B: b === null ? null : { mean_ms: b } });
const merged = mergeSeconds([p(now - 3, 2, 3.5), p(now - 2, 2.1, 3.4)], [p(now - 2, 2.2, 3.3), p(now - 1, 2.3, 3.6)], now);
check('merging keeps one point per second, the newest copy', merged.map((x) => [x.t, x.A.mean_ms]), [[now - 3, 2], [now - 2, 2.2], [now - 1, 2.3]]);
check('merging trims to 15 minutes', mergeSeconds([p(now - WINDOW_SECONDS - 1, 1, 1), p(now - 5, 2, 2)], [], now).map((x) => x.t), [now - 5]);
check('the next "since" is the newest second held', latestSecond(merged), now - 1);
check('no points means no "since"', latestSecond([]), null);
check('a silent feed leaves a gap in its line', lineFor([p(now - 2, 2, null), p(now - 1, 2.1, 3.4)], 'B'), [{ x: now - 2, y: null }, { x: now - 1, y: 3.4 }]);

// chaos.js
check('a silenced feed reads naturally', describeFault({ exchange: 'KTX', feed: 'A', kind: 'silence', value: 0 }), 'KTX A silenced');
check('a delay reads naturally', describeFault({ exchange: 'RHX', feed: 'A', kind: 'delay', value: 40 }), 'RHX A 40 ms slower');
check('a burst reads naturally', describeFault({ exchange: 'KTX', feed: 'B', kind: 'burst', value: 6 }), 'KTX B losing the next 6 messages');
check('no faults means no banner', bannerText([], () => ''), null);
check('the banner lists faults and the latest restore time', bannerText([
    { exchange: 'KTX', feed: 'A', kind: 'silence', value: 0, expires_at: '2026-10-04T12:05:00Z' },
    { exchange: 'PRX', feed: 'A', kind: 'silence', value: 0, expires_at: '2026-10-04T12:07:00Z' },
], (d) => d.toISOString().slice(11, 16)), 'Simulated fault in progress: KTX A silenced; PRX A silenced. Restores by 12:07.');
check('the banner keeps long lists short', bannerText(['KTX', 'PRX', 'HBX', 'THX'].map((code) => ({ exchange: code, feed: 'A', kind: 'silence', value: 0, expires_at: '2026-10-04T12:05:00Z' })), () => '12:05').endsWith('and 1 more. Restores by 12:05.'), true);

document.getElementById('results').textContent = lines.join('\n');
document.title = failures === 0 ? 'ALL PASS' : `FAILURES: ${failures}`;

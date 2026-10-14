// Describing chaos faults for people (plan 007). Pure; tested in tests/tests.js.

const KIND_TEXT = {
    silence: () => 'silenced',
    drop: (value) => `dropping ${value}% of messages`,
    delay: (value) => `${value} ms slower`,
    burst: (value) => `losing the next ${value} messages`,
};

/** "KTX A silenced", "RHX A 40 ms slower". */
export function describeFault(fault) {
    const text = KIND_TEXT[fault.kind] ? KIND_TEXT[fault.kind](fault.value) : fault.kind;

    return `${fault.exchange} ${fault.feed} ${text}`;
}

/**
 * The banner every visitor sees while chaos is active (ADR-007), or null.
 * @param {Array<object>} faults from /api/exchanges
 * @param {(date: Date) => string} clock formats the restore time
 */
export function bannerText(faults, clock) {
    if (!Array.isArray(faults) || faults.length === 0) {
        return null;
    }
    const latest = faults.map((f) => Date.parse(f.expires_at)).reduce((a, b) => Math.max(a, b));
    const shown = faults.slice(0, 3).map(describeFault).join('; ');
    const more = faults.length > 3 ? ` and ${faults.length - 3} more` : '';

    return `Simulated fault in progress: ${shown}${more}. Restores by ${clock(new Date(latest))}.`;
}

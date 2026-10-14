// The world map (ADR-006): Leaflet, vendored, with OpenStreetMap's standard
// tiles, darkened by a CSS filter for the dark console (app.css). If the
// tiles can't load, the markers still show on a plain background.

import { STATUSES, statusOf } from './status.js';

const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

// Labels go below each marker, except for neighbours close enough at world
// zoom for their labels to collide (London and Frankfurt; New York beside Chicago).
const LABEL_SIDE = { THX: 'left', RHX: 'right', HDX: 'right' };

/**
 * @param {HTMLElement} element where the map goes
 * @param {(code: string) => void} onSelect called when a marker is chosen
 */
export function createMap(element, onSelect) {
    const L = window.L;
    if (!L) {
        element.textContent = 'The map could not load. The exchange list shows the same information.';

        return { update() {}, reveal() {}, invalidate() {} };
    }

    // Wide panels start one zoom level in, so the world fills the panel.
    const minZoom = element.clientWidth >= 700 ? 2 : 1;
    const map = L.map(element, { worldCopyJump: true, minZoom, zoomControl: true, attributionControl: true });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 8,
        attribution: ATTRIBUTION,
    }).addTo(map);
    map.setView([25, 20], minZoom);

    const markers = new Map();
    let fitted = false;

    function icon(exchange, selected, pulse) {
        const status = statusOf(exchange.status);
        const wrap = document.createElement('div');
        wrap.className = `marker marker--${status}${selected ? ' marker--selected' : ''}${pulse ? ' marker--pulse' : ''}`;
        const shape = document.createElement('span');
        shape.className = 'marker__shape';
        const label = document.createElement('span');
        label.className = `marker__label marker__label--${LABEL_SIDE[exchange.code] || 'below'}`;
        label.textContent = exchange.code;
        wrap.append(shape, label);

        return L.divIcon({ html: wrap, className: '', iconSize: [26, 26] });
    }

    return {
        /**
         * @param {Array<object>} exchanges from /api/exchanges
         * @param {?string} selectedCode
         * @param {Set<string>} pulseCodes exchanges whose status just changed
         */
        update(exchanges, selectedCode, pulseCodes) {
            for (const exchange of exchanges) {
                const title = `${exchange.code} ${exchange.name}, ${exchange.city}: ${exchange.status_reason || STATUSES[statusOf(exchange.status)].label}`;
                let marker = markers.get(exchange.code);
                if (!marker) {
                    marker = L.marker([exchange.latitude, exchange.longitude], { keyboard: true, title, alt: title, riseOnHover: true })
                        .addTo(map)
                        .on('click keypress', () => onSelect(exchange.code));
                    markers.set(exchange.code, marker);
                }
                marker.setIcon(icon(exchange, exchange.code === selectedCode, pulseCodes.has(exchange.code)));
                const el = marker.getElement();
                if (el) {
                    el.setAttribute('aria-label', title);
                    el.setAttribute('role', 'button');
                    el.title = title;
                }
            }

            if (!fitted && exchanges.length > 0) {
                map.fitBounds(exchanges.map((e) => [e.latitude, e.longitude]), { padding: [20, 20], maxZoom: 3 });
                if (map.getZoom() < minZoom) {
                    map.setZoom(minZoom);
                }
                fitted = true;
            }
        },
        /**
         * Brings an exchange's marker into view if it isn't comfortably on
         * screen already. Instant when the viewer prefers reduced motion.
         */
        reveal(code) {
            const marker = markers.get(code);
            if (!marker || element.offsetParent === null) {
                return;
            }
            const position = marker.getLatLng();
            // Shrink the visible area by 15% each side, so a marker at the very edge counts as out of view.
            if (!map.getBounds().pad(-0.15).contains(position)) {
                const animate = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                map.panTo(position, { animate, duration: 0.6 });
            }
        },
        invalidate() {
            map.invalidateSize();
        },
    };
}

<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $basePath */
echo View::render('layout-start', [
    'title' => 'FeedWatch - live feed health',
    'basePath' => $basePath,
    'page' => 'dashboard',
    'styles' => ['vendor/leaflet-1.9.4/leaflet.css'],
    'scripts' => ['vendor/leaflet-1.9.4/leaflet.js', 'vendor/chart.js-4.5.1/chart.umd.min.js'],
    'module' => 'js/dashboard.js',
]);
?>
<main id="main" class="dashboard" data-view="map">
    <h1 class="visually-hidden">Market data feed health</h1>

    <section class="tiles" aria-label="Summary">
        <div class="tile"><p class="tile__label">Exchanges healthy</p><p class="tile__value" id="tile-healthy"><span class="skeleton">&nbsp;</span></p></div>
        <div class="tile"><p class="tile__label">Open incidents</p><p class="tile__value" id="tile-incidents"><span class="skeleton">&nbsp;</span></p></div>
        <div class="tile"><p class="tile__label">Messages per second</p><p class="tile__value" id="tile-rate"><span class="skeleton">&nbsp;</span></p></div>
        <div class="tile"><p class="tile__label">Messages lost today</p><p class="tile__value" id="tile-lost"><span class="skeleton">&nbsp;</span></p></div>
    </section>

    <div class="view-switch" role="group" aria-label="View">
        <button type="button" class="view-switch__button" id="show-list" aria-pressed="false">List</button>
        <button type="button" class="view-switch__button" id="show-map" aria-pressed="true">Map</button>
    </div>

    <section class="map-panel" aria-label="World map of exchanges">
        <div id="map" class="map" role="presentation"></div>
    </section>

    <section class="exchange-list" aria-labelledby="exchange-list-heading">
        <h2 id="exchange-list-heading" class="panel-heading">Exchanges</h2>
        <ul id="exchange-list" class="exchange-list__items">
            <li class="skeleton-row"><span class="skeleton">&nbsp;</span></li>
            <li class="skeleton-row"><span class="skeleton">&nbsp;</span></li>
            <li class="skeleton-row"><span class="skeleton">&nbsp;</span></li>
        </ul>
    </section>

    <section class="alerts" aria-labelledby="alerts-heading">
        <h2 id="alerts-heading" class="panel-heading">Latest incidents</h2>
        <ul id="alert-feed" class="alerts__items"></ul>
        <p id="alert-empty" class="empty" hidden>No incidents yet. Everything is running normally.</p>
        <p class="alerts__more"><a href="<?= View::e($basePath) ?>/incidents">All incidents</a></p>
    </section>

    <section id="detail" class="detail" aria-labelledby="detail-heading" hidden>
        <div class="detail__header">
            <h2 id="detail-heading" class="detail__title"></h2>
            <button type="button" class="detail__close" id="detail-close">Close<span class="visually-hidden"> exchange detail</span></button>
        </div>
        <p id="detail-status" class="status-line"></p>
        <div class="feeds" id="detail-feeds"></div>
        <h3 class="detail__subheading">Latency, last 15 minutes</h3>
        <div class="chart-wrap"><canvas id="latency-chart" role="img" aria-describedby="latency-summary"></canvas></div>
        <p id="latency-summary" class="chart-summary"></p>
        <h3 class="detail__subheading">A/B win share, last minute</h3>
        <div id="win-share" class="win-share"></div>
        <h3 class="detail__subheading">Recent gaps</h3>
        <ul id="recent-gaps" class="gap-list"></ul>
        <p id="gaps-empty" class="empty" hidden>No gaps recorded.</p>
    </section>

    <p class="visually-hidden" aria-live="polite" id="announcer"></p>
</main>
</body>
</html>

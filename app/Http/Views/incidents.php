<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $basePath */
echo View::render('layout-start', [
    'title' => 'Incidents - FeedWatch',
    'basePath' => $basePath,
    'page' => 'incidents',
    'module' => 'js/incidents.js',
]);
?>
<main id="main" class="incidents-page">
    <h1 class="page-title">Incidents</h1>

    <form id="filters" class="filters" aria-label="Filter incidents">
        <label>Exchange
            <select name="exchange">
                <option value="">All</option>
                <option>THX</option><option>RHX</option><option>HDX</option><option>LKX</option>
                <option>PLX</option><option>KTX</option><option>PRX</option><option>HBX</option>
            </select>
        </label>
        <label>Type
            <select name="type">
                <option value="">All</option>
                <option value="feed_down">Feed down</option>
                <option value="failover">Failover</option>
                <option value="data_loss">Data loss</option>
                <option value="latency">Latency</option>
                <option value="line_gaps">Line gaps</option>
            </select>
        </label>
        <label>From <input type="date" name="from"></label>
        <label>To <input type="date" name="to"></label>
        <button type="submit">Apply</button>
    </form>

    <div class="incidents-layout">
        <section aria-labelledby="incident-list-heading">
            <h2 id="incident-list-heading" class="visually-hidden">Incident list</h2>
            <p id="incidents-loading" class="loading">Loading incidents...</p>
            <ul id="incident-list" class="incident-list"></ul>
            <p id="incidents-empty" class="empty" hidden>No incidents match these filters. With no chaos active, FeedWatch's feeds stay healthy.</p>
        </section>

        <section id="incident-detail" class="incident-detail" aria-labelledby="incident-detail-heading" hidden>
            <h2 id="incident-detail-heading" class="detail__title"></h2>
            <p id="incident-summary" class="status-line"></p>
            <dl id="incident-facts" class="facts"></dl>
            <h3 class="detail__subheading">Timeline</h3>
            <ol id="incident-timeline" class="timeline"></ol>
            <h3 class="detail__subheading">Notes</h3>
            <ul id="incident-notes" class="gap-list"></ul>
            <p id="notes-empty" class="empty">No notes.</p>
            <form id="note-form" class="note-form" hidden>
                <label>Add a note <textarea name="text" rows="3" maxlength="500" required></textarea></label>
                <button type="submit">Add note</button>
                <p id="note-message" class="form-message" role="status" hidden></p>
            </form>
            <p id="incident-error" class="empty" hidden>That incident doesn't exist.</p>
        </section>
    </div>
</main>
</body>
</html>

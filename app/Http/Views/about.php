<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $basePath */
echo View::render('layout-start', [
    'title' => 'About - FeedWatch',
    'basePath' => $basePath,
    'page' => 'about',
]);
$base = View::e($basePath);
?>
<main id="main" class="about">
    <header class="about__intro">
        <p class="eyebrow">About FeedWatch</p>
        <h1 class="page-title">A health monitor for market data feeds</h1>
        <p class="about__lead">FeedWatch watches simulated stock exchange feeds and raises the alarm when messages go missing, a route fails or a feed slows down. This page explains the real-world problem, how the app works, and how it was built.</p>
        <nav class="about__contents" aria-label="On this page">
            <a href="#problem">The problem</a>
            <a href="#how-it-works">How it works</a>
            <a href="#architecture">Architecture</a>
            <a href="#process">How it was built</a>
            <a href="#limits">Limits</a>
        </nav>
    </header>

    <section class="about__section" id="problem" aria-labelledby="problem-heading">
        <h2 id="problem-heading">The real-world problem</h2>
        <p>Every stock exchange publishes a non-stop stream of small messages: new orders, cancellations and trades. Trading firms build their view of the market from that stream, so every message matters. If one goes missing, their picture of the market is wrong.</p>
        <p>Each message carries a <strong>sequence number</strong> (1, 2, 3 and so on), so a missing one shows up as a skipped number, called a <strong>gap</strong>. To guard against gaps, exchanges send everything twice, over two separate network routes called the <strong>A feed</strong> and the <strong>B feed</strong>. The receiver keeps whichever copy arrives first. If one route breaks, the other carries on and nothing is lost, but there is no longer a safety net.</p>
        <p>The companies that run these networks give their clients monitoring dashboards that show, live, which feeds are healthy, which have failed over to their backup route, and where data has been lost. <strong>FeedWatch is a small, simulated version of that kind of dashboard</strong>, inspired by the real ones. Every exchange and every message in it is fictional.</p>

        <div class="table-wrap">
            <table class="about__table">
                <caption>The real world, and how FeedWatch models it</caption>
                <thead>
                    <tr><th scope="col">In the real world</th><th scope="col">In FeedWatch</th></tr>
                </thead>
                <tbody>
                    <tr><td>Exchanges in cities around the world</td><td>Eight fictional exchanges on a world map</td></tr>
                    <tr><td>Each message sent on an A and a B route</td><td>A simulator sends every numbered message on both feeds, each with its own delay</td></tr>
                    <tr><td>A network fault drops messages or cuts a route</td><td>The chaos panel breaks a feed, drops messages on both, or adds delay</td></tr>
                    <tr><td>The receiver keeps the first copy of each message</td><td>Arbitration: first copy wins, duplicates are dropped</td></tr>
                    <tr><td>An operations team watches a monitoring dashboard</td><td>The live dashboard: map, status list, latency chart and alerts</td></tr>
                    <tr><td>An outage report explains what went wrong and for how long</td><td>The incident log, with start, end, duration and a timeline</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="about__section" id="how-it-works" aria-labelledby="how-heading">
        <h2 id="how-heading">How it works, in five steps</h2>
        <ol class="steps">
            <li class="steps__item">
                <h3>Simulate</h3>
                <p>Each exchange sends about five numbered messages a second, on both its A and B feed. A usually arrives a few milliseconds sooner, because it's the faster route.</p>
            </li>
            <li class="steps__item">
                <h3>Arbitrate</h3>
                <p>For each message number, the first copy to arrive wins and the later copy is dropped as a duplicate. The dashboard shows how often A wins compared with B.</p>
            </li>
            <li class="steps__item">
                <h3>Detect</h3>
                <p>If a number is skipped, FeedWatch waits two seconds in case it's just late, then checks the other feed. If B had it, only the backup copy was lost; several of those in five minutes turn the exchange amber. If neither feed had it, data is gone and the exchange turns red. A feed that goes quiet for two seconds means the exchange is running on one route. Latency is compared with what's normal for that feed.</p>
            </li>
            <li class="steps__item">
                <h3>Record</h3>
                <p>Each problem opens an incident, which stays open while the problem lasts and closes with an honest duration. A feed that flickers off and on counts as one incident.</p>
            </li>
            <li class="steps__item">
                <h3>Show</h3>
                <p>Your browser asks the server for the latest state every two seconds and updates the map, tiles, chart and alerts. If the connection drops, the page says how old its data is instead of pretending.</p>
            </li>
        </ol>
        <p>Want to see it react? An administrator can use the chaos panel to break a feed on purpose. Everyone sees a banner while a fault is active, and faults undo themselves after five minutes.</p>
    </section>

    <section class="about__section" id="architecture" aria-labelledby="architecture-heading">
        <h2 id="architecture-heading">Architecture</h2>
        <p>FeedWatch is deliberately small, so every part can be explained. It runs on ordinary shared web hosting, which can't keep a program running in the background. So instead of a simulator that runs all the time, each request asks "what should have happened since last time?", and the server works that out, saves it and answers.</p>

        <figure class="diagram">
            <svg class="diagram__svg" viewBox="0 0 380 604" role="img" aria-labelledby="diagram-title diagram-desc">
                <title id="diagram-title">FeedWatch architecture</title>
                <desc id="diagram-desc">The browser asks the JSON API for the latest state every two seconds. The API asks the engine to catch up to now. Inside the engine, messages are simulated, arbitrated, checked for problems and recorded as incidents. Cron runs the same engine every minute. The engine saves its results in MariaDB, and the API reads them from there.</desc>
                <defs>
                    <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path class="diagram__arrowhead" d="M0,0 L10,5 L0,10 z"/>
                    </marker>
                </defs>

                <rect class="diagram__box" x="10" y="10" width="360" height="60" rx="8"/>
                <text class="diagram__title" x="190" y="35">Browser: the dashboard</text>
                <text class="diagram__sub" x="190" y="55">map, tiles, chart, incident log</text>

                <line class="diagram__line" x1="190" y1="72" x2="190" y2="114" marker-end="url(#arrow)"/>
                <text class="diagram__label" x="200" y="97">asks every 2 s</text>

                <rect class="diagram__box" x="10" y="118" width="360" height="60" rx="8"/>
                <text class="diagram__title" x="190" y="143">JSON API</text>
                <text class="diagram__sub" x="190" y="163">one PHP entry point, security headers</text>

                <line class="diagram__line" x1="208" y1="180" x2="208" y2="222" marker-end="url(#arrow)"/>
                <text class="diagram__label" x="218" y="205">catch up to now</text>

                <rect class="diagram__group" x="96" y="226" width="224" height="262" rx="10"/>
                <text class="diagram__title" x="208" y="248">Engine (plain PHP)</text>

                <rect class="diagram__box diagram__box--step" x="112" y="258" width="192" height="44" rx="6"/>
                <text class="diagram__title" x="208" y="277">Simulate</text>
                <text class="diagram__sub" x="208" y="294">A and B messages</text>
                <line class="diagram__line" x1="208" y1="303" x2="208" y2="314" marker-end="url(#arrow)"/>

                <rect class="diagram__box diagram__box--step" x="112" y="316" width="192" height="44" rx="6"/>
                <text class="diagram__title" x="208" y="335">Arbitrate</text>
                <text class="diagram__sub" x="208" y="352">first copy wins</text>
                <line class="diagram__line" x1="208" y1="361" x2="208" y2="372" marker-end="url(#arrow)"/>

                <rect class="diagram__box diagram__box--step" x="112" y="374" width="192" height="44" rx="6"/>
                <text class="diagram__title" x="208" y="393">Detect</text>
                <text class="diagram__sub" x="208" y="410">gaps, silence, latency</text>
                <line class="diagram__line" x1="208" y1="419" x2="208" y2="430" marker-end="url(#arrow)"/>

                <rect class="diagram__box diagram__box--step" x="112" y="432" width="192" height="44" rx="6"/>
                <text class="diagram__title" x="208" y="451">Record</text>
                <text class="diagram__sub" x="208" y="468">open and close incidents</text>

                <rect class="diagram__box" x="10" y="300" width="72" height="80" rx="8"/>
                <text class="diagram__title" x="46" y="331">Cron</text>
                <text class="diagram__sub" x="46" y="349">every</text>
                <text class="diagram__sub" x="46" y="365">minute</text>
                <line class="diagram__line" x1="83" y1="340" x2="94" y2="340" marker-end="url(#arrow)"/>

                <line class="diagram__line" x1="208" y1="490" x2="208" y2="528" marker-end="url(#arrow)"/>
                <text class="diagram__label" x="218" y="514">saves results</text>

                <line class="diagram__line diagram__line--dashed" x1="350" y1="530" x2="350" y2="182" marker-end="url(#arrow)"/>
                <text class="diagram__label" x="362" y="356" transform="rotate(-90 362 356)">API reads results</text>

                <path class="diagram__box" d="M10,540 a180,10 0 0,0 360,0 v44 a180,10 0 0,1 -360,0 z"/>
                <ellipse class="diagram__box" cx="190" cy="540" rx="180" ry="10"/>
                <text class="diagram__title" x="190" y="572">MariaDB database</text>
                <text class="diagram__sub" x="190" y="590">messages, statistics, gaps, incidents</text>
            </svg>
            <figcaption>How a request flows through FeedWatch. The engine does the same work whether a browser or the minute-by-minute cron job asks, so the feeds keep running when nobody is watching.</figcaption>
        </figure>

        <dl class="about__parts">
            <dt>Browser dashboard</dt>
            <dd>One HTML page per screen, filled in by small JavaScript modules. No framework and no build step. The map uses Leaflet and the chart uses Chart.js, both served by FeedWatch itself.</dd>
            <dt>JSON API</dt>
            <dd>Every request goes through one PHP entry point, which looks up the route, runs the handler and adds security headers. Answers are cached for a second, so many viewers don't mean many times the work.</dd>
            <dt>Engine</dt>
            <dd>The simulation and detection logic is plain PHP with no database calls inside it. Time and randomness are passed in, so the same inputs always give the same result, which makes it easy to test thoroughly.</dd>
            <dt>Database</dt>
            <dd>MariaDB keeps only the last 15 minutes of raw messages, plus per-second and per-minute summaries, gaps and incidents, so it stays small on shared hosting.</dd>
            <dt>Cron</dt>
            <dd>Runs every minute to keep the simulation going, summarise and tidy up old data. It stops simulating after 30 minutes with no viewers, so it doesn't load the server for nothing.</dd>
        </dl>
    </section>

    <section class="about__section" id="process" aria-labelledby="process-heading">
        <h2 id="process-heading">How it was built</h2>
        <p>FeedWatch was built with AI coding agents doing much of the drafting and typing, inside a process that keeps the design decisions with a person. Nothing is built until it has been specified, decided and planned, and each of those steps leaves a written record in the project's documentation.</p>
        <ol class="process">
            <li class="process__item"><h3>Research</h3><p>Background on market data, feeds and monitoring was gathered and written up as short reference pages.</p></li>
            <li class="process__item"><h3>Specify</h3><p>Each feature became a requirement with checkable acceptance criteria, for example "if neither feed received message 1040, it counts as data loss and raises a critical alert".</p></li>
            <li class="process__item"><h3>Decide</h3><p>Each design choice, such as "polling or websockets?", got a decision record with the options, trade-offs and a recommendation. The author made every decision.</p></li>
            <li class="process__item"><h3>Plan</h3><p>Each slice of work got a written plan: tasks, tests and what changes. No code was written until the plan was approved.</p></li>
            <li class="process__item"><h3>Build</h3><p>Code and tests were written together. Every change runs the automated tests, static analysis and a colour-contrast check, locally and in continuous integration.</p></li>
            <li class="process__item"><h3>Explain</h3><p>Each part has an "explain it in 30 seconds" summary and likely questions, so the author can explain every component without notes.</p></li>
            <li class="process__item"><h3>Release</h3><p>Each deploy ends with a smoke test of the live site: it checks the new version is running, the database is reachable, and private files can't be reached from the web.</p></li>
        </ol>
    </section>

    <section class="about__section" id="limits" aria-labelledby="limits-heading">
        <h2 id="limits-heading">Technology and limits</h2>
        <p><strong>Built with</strong> PHP 8.4 with no framework, MariaDB, HTML, CSS and JavaScript modules, Leaflet with OpenStreetMap tiles, and Chart.js. It is hosted on ordinary shared web hosting.</p>
        <ul class="about__list">
            <li>All data is simulated and every exchange is fictional. FeedWatch doesn't connect to real market data.</li>
            <li>It monitors feed health (sequence, timing and availability), not prices or trading.</li>
            <li>It is sized for a demonstration, at a few dozen messages a second. Real feeds carry millions, which would need a different design: a long-running receiver, streaming instead of polling, and a time-series store.</li>
        </ul>
        <p><a href="<?= $base ?>/">Back to the dashboard</a></p>
    </section>
</main>
</body>
</html>

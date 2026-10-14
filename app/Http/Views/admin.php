<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $basePath */
/** @var string $csrf */
/** @var array<string, string> $presets name => label */
echo View::render('layout-start', [
    'title' => 'Chaos panel - FeedWatch',
    'basePath' => $basePath,
    'page' => 'admin',
    'module' => 'js/admin.js',
    'csrf' => $csrf,
]);
?>
<main id="main" class="admin-page">
    <div class="admin-header">
        <h1 class="page-title">Chaos panel</h1>
        <form method="post" action="<?= View::e($basePath) ?>/admin/logout">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button type="submit" class="button-secondary">Log out</button>
        </form>
    </div>
    <p class="lead">Faults take effect from the next simulation step and restore themselves after the configured time. Every visitor sees a "simulated fault in progress" banner while one is active.</p>
    <p id="admin-message" class="form-message" role="status" hidden></p>

    <section class="admin-section" aria-labelledby="presets-heading">
        <h2 id="presets-heading" class="panel-heading">Scenarios</h2>
        <div class="button-row">
<?php foreach ($presets as $name => $label): ?>
            <button type="button" class="button-chaos" data-preset="<?= View::e($name) ?>"><?= View::e($label) ?></button>
<?php endforeach; ?>
            <button type="button" class="button-restore" id="restore-all">Restore all</button>
        </div>
    </section>

    <section class="admin-section" aria-labelledby="custom-heading">
        <h2 id="custom-heading" class="panel-heading">Custom fault</h2>
        <form id="custom-fault" class="filters">
            <label>Exchange
                <select name="exchange">
                    <option>THX</option><option>RHX</option><option>HDX</option><option>LKX</option>
                    <option>PLX</option><option selected>KTX</option><option>PRX</option><option>HBX</option>
                </select>
            </label>
            <label>Feed <select name="feed"><option>A</option><option>B</option></select></label>
            <label>Fault
                <select name="kind">
                    <option value="silence">Silence the feed</option>
                    <option value="drop">Drop a percentage (1-100)</option>
                    <option value="delay">Add delay, ms (1-2000)</option>
                    <option value="burst">Drop the next N messages (1-500)</option>
                </select>
            </label>
            <label>Value <input type="number" name="value" min="0" max="2000" value="0"></label>
            <button type="submit">Apply</button>
        </form>
    </section>

    <section class="admin-section" aria-labelledby="active-heading">
        <h2 id="active-heading" class="panel-heading">Active faults</h2>
        <ul id="active-faults" class="incident-list"></ul>
        <p id="no-faults" class="empty">No faults. Everything is running normally.</p>
    </section>

    <section class="admin-section" aria-labelledby="settings-heading">
        <h2 id="settings-heading" class="panel-heading">Thresholds</h2>
        <p class="tile__label">Changes take effect on the next request. Each value is checked against its allowed range.</p>
        <form id="settings" class="settings-form"></form>
    </section>

    <section class="admin-section" aria-labelledby="log-heading">
        <h2 id="log-heading" class="panel-heading">Admin log</h2>
        <ol id="admin-log" class="timeline"></ol>
    </section>
</main>
</body>
</html>

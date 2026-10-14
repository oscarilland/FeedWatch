<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $title */
/** @var string $basePath */
/** @var ?string $page "dashboard", "incidents", "about", "admin" or "error" */
/** @var ?list<string> $styles extra stylesheets, relative to assets/ */
/** @var ?list<string> $scripts classic scripts, relative to assets/, loaded before the module */
/** @var ?string $module the page's JavaScript module, relative to assets/ */
/** @var ?string $csrf the admin's CSRF token, for the page's JavaScript */
$csrf ??= null;
$page ??= 'error';
$styles ??= [];
$scripts ??= [];
$module ??= null;
$asset = static fn (string $path): string => View::e($basePath . '/assets/' . $path);
?>
<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title><?= View::e($title) ?></title>
<?php if ($csrf !== null): ?>
    <meta name="csrf-token" content="<?= View::e($csrf) ?>">
<?php endif; ?>
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= $asset($style) ?>">
<?php endforeach; ?>
    <link rel="stylesheet" href="<?= $asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= $asset('css/app.css') ?>">
<?php foreach ($scripts as $script): ?>
    <script src="<?= $asset($script) ?>" defer></script>
<?php endforeach; ?>
<?php if ($module !== null): ?>
    <script type="module" src="<?= $asset($module) ?>"></script>
<?php endif; ?>
</head>
<body data-base-path="<?= View::e($basePath) ?>" data-page="<?= View::e($page) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <a class="brand" href="<?= View::e($basePath) ?>/">
        <span class="brand__mark" aria-hidden="true"></span>
        <span>FeedWatch</span>
    </a>
    <nav class="site-nav" aria-label="Main">
        <a href="<?= View::e($basePath) ?>/"<?= $page === 'dashboard' ? ' aria-current="page"' : '' ?>>Dashboard</a>
        <a href="<?= View::e($basePath) ?>/incidents"<?= $page === 'incidents' ? ' aria-current="page"' : '' ?>>Incidents</a>
        <a href="<?= View::e($basePath) ?>/about"<?= $page === 'about' ? ' aria-current="page"' : '' ?>>About</a>
        <a href="<?= View::e($basePath) ?>/admin"<?= $page === 'admin' ? ' aria-current="page"' : '' ?>>Admin</a>
    </nav>
    <p class="site-header__note">Simulated data</p>
</header>
<div class="stale-banner" id="stale-banner" role="alert" hidden></div>
<div class="chaos-banner" id="chaos-banner" role="status" hidden></div>

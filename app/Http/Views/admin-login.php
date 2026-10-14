<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $basePath */
/** @var string $csrf */
/** @var ?string $message */
/** @var bool $configured */
echo View::render('layout-start', ['title' => 'Admin login - FeedWatch', 'basePath' => $basePath, 'page' => 'admin']);
?>
<main id="main" class="admin-page admin-page--narrow">
    <h1 class="page-title">Admin login</h1>
    <p class="lead">The chaos panel is for the presenter. Everyone else can watch the dashboard.</p>
<?php if ($message !== null): ?>
    <p class="form-message" role="alert"><?= View::e($message) ?></p>
<?php endif; ?>
<?php if (!$configured): ?>
    <p class="empty">The admin login is switched off on this site.</p>
<?php else: ?>
    <form class="login-form" method="post" action="<?= View::e($basePath) ?>/admin/login">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <label>Username <input type="text" name="username" autocomplete="username" required maxlength="64"></label>
        <label>Password <input type="password" name="password" autocomplete="current-password" required maxlength="200"></label>
        <button type="submit">Log in</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>

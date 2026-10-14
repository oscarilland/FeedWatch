<?php

declare(strict_types=1);

use FeedWatch\Http\View;

/** @var string $title */
/** @var string $basePath */
echo View::render('layout-start', ['title' => $title . ' - FeedWatch', 'basePath' => $basePath]);
?>
<main class="page">
    <p class="eyebrow">FeedWatch</p>
    <h1><?= View::e($title) ?></h1>
    <p class="lead"><a href="<?= View::e($basePath) ?>/">Back to FeedWatch</a></p>
</main>
</body>
</html>

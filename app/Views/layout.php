<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ru">
<head>
    <title><?= \App\Core\View::e($title ?? 'Workforce') ?></title>
    <?php require BASE_PATH . '/app/Views/partials/head.php'; ?>
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/navbar.php'; ?>
<main class="container">
<?= $content ?? '' ?>
</main>
</body>
</html>

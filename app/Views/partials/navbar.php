<?php
declare(strict_types=1);
$user = \App\Core\Auth::user();
?>
<header class="topbar">
    <a class="brand" href="/dashboard">Workforce</a>
    <?php if ($user): ?>
        <nav class="nav">
            <a href="/schedule">График</a>
            <?php if ($user['role'] === 'admin'): ?><a href="/admin">Админка</a><?php endif; ?>
            <span class="nav-user"><?= \App\Core\View::e($user['name']) ?></span>
            <form class="inline-form" method="post" action="/logout">
                <input type="hidden" name="_csrf" value="<?= \App\Core\View::e($csrf ?? '') ?>">
                <button class="link-btn" type="submit">Выйти</button>
            </form>
        </nav>
    <?php endif; ?>
</header>

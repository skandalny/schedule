<?php
declare(strict_types=1);

use App\Core\View;
?>
<section class="card auth-card">
    <h1>Вход</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= View::e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= View::e($success) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

        <label>
            Email
            <input type="email" name="email" required autocomplete="email">
        </label>

        <label>
            Пароль
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button type="submit">Войти</button>
    </form>

    <p class="muted">Нет аккаунта? <a href="/register">Зарегистрироваться</a></p>
</section>

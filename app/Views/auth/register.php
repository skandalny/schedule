<?php
declare(strict_types=1);

use App\Core\View;
?>
<section class="card auth-card">
    <h1>Регистрация</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= View::e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= View::e($success) ?></div>
    <?php endif; ?>

    <form method="post" action="/register" class="form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

        <label>
            Имя
            <input type="text" name="name" required autocomplete="name">
        </label>

        <label>
            Email
            <input type="email" name="email" required autocomplete="email">
        </label>

        <label>
            Пароль
            <input type="password" name="password" required autocomplete="new-password">
        </label>

        <label>
            Повтор пароля
            <input type="password" name="password_confirm" required autocomplete="new-password">
        </label>

        <button type="submit">Создать аккаунт</button>
    </form>

    <p class="muted">Уже есть аккаунт? <a href="/login">Войти</a></p>
</section>

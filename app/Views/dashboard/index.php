<?php
declare(strict_types=1);
?>
<section class="hero-card">
    <div>
        <span class="eyebrow">WORKFORCE</span>
        <h1>Панель управления</h1>
        <p class="muted">Создавайте месяцы, задавайте потребность по времени и запускайте автоматическое построение графика.</p>
    </div>
    <div class="hero-actions">
        <a class="button-link" href="/schedule">Открыть график</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <a class="button-link secondary" href="/admin">Администрирование</a>
        <?php endif; ?>
    </div>
</section>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-label">Пользователь</span>
        <strong><?= \App\Core\View::e($user['name'] ?? '') ?></strong>
        <small><?= \App\Core\View::e($user['role'] ?? '') ?></small>
    </div>
    <div class="stat-card">
        <span class="stat-label">Что дальше</span>
        <strong>Создайте месяц</strong>
        <small>и задайте потребность</small>
    </div>
</div>

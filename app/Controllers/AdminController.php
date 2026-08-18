<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Database;
use App\Core\View;
use App\Models\User;

final class AdminController
{
    public function page(): void
    {
        Auth::requireAuth();
        if (!Authorization::canManageUsers()) {
            http_response_code(403);
            exit('403 Forbidden');
        }

        View::render('admin/index', [
            'title' => 'Администрирование',
            'user' => Auth::user(),
            'csrf' => Csrf::token(),
        ]);
    }

    public function users(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageUsers()) Response::json(['error' => 'Нет прав'], 403);
        Response::json(['users' => User::allEmployees()]);
    }

    public function updateRole(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageUsers()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $id = (int)$_POST['id'];
        $role = (string)$_POST['role'];
        if ($id === (int)Auth::user()['id'] && $role !== 'admin') {
            Response::json(['error' => 'Нельзя снять роль admin у текущего пользователя'], 422);
        }
        User::updateRole($id, $role);
        Response::json(['ok' => true]);
    }
}

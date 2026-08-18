<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

final class DashboardController
{
    public function index(): void
    {
        Auth::requireAuth();

        View::render('dashboard/index', [
            'title' => 'Панель',
            'user' => Auth::user(),
            'csrf' => Csrf::token(),
            'success' => \App\Core\Flash::get('success'),
        ]);
    }
}

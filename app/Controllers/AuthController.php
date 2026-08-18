<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\User;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }

        View::render('auth/login', [
            'title' => 'Вход',
            'csrf' => Csrf::token(),
            'error' => Flash::get('error'),
            'success' => Flash::get('success'),
        ]);
    }

    public function login(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = User::findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Flash::set('error', 'Неверный email или пароль.');
            header('Location: /login');
            exit;
        }

        Auth::login((int) $user['id']);
        Flash::set('success', 'Вход выполнен.');
        header('Location: /dashboard');
        exit;
    }

    public function showRegister(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }

        View::render('auth/register', [
            'title' => 'Регистрация',
            'csrf' => Csrf::token(),
            'error' => Flash::get('error'),
            'success' => Flash::get('success'),
        ]);
    }

    public function register(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            Flash::set('error', 'Заполни все поля.');
            header('Location: /register');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Некорректный email.');
            header('Location: /register');
            exit;
        }

        if (strlen($password) < 8) {
            Flash::set('error', 'Пароль должен быть не короче 8 символов.');
            header('Location: /register');
            exit;
        }

        if ($password !== $passwordConfirm) {
            Flash::set('error', 'Пароли не совпадают.');
            header('Location: /register');
            exit;
        }

        if (User::findByEmail($email)) {
            Flash::set('error', 'Пользователь с таким email уже существует.');
            header('Location: /register');
            exit;
        }

        $role = 'employee';
        $userId = User::create($name, $email, $password, $role);
        Auth::login($userId);

        Flash::set('success', 'Регистрация завершена.');
        header('Location: /dashboard');
        exit;
    }

    public function logout(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }

        Auth::logout();
        header('Location: /login');
        exit;
    }
}

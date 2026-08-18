<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

set_exception_handler(function (\Throwable $e): void {
    $logDir = BASE_PATH . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents(
        $logDir . '/app.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . ' in ' .
        $e->getFile() . ':' . $e->getLine() . PHP_EOL,
        FILE_APPEND
    );

    $isApi = str_starts_with((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/api/');
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo 'Произошла ошибка сервера.';
    }
});

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) require $file;
});

\App\Core\Config::load(BASE_PATH . '/config/config.php');
date_default_timezone_set(\App\Core\Config::get('app.timezone', 'UTC'));

$router = new \App\Core\Router();

$authController = new \App\Controllers\AuthController();
$dashboardController = new \App\Controllers\DashboardController();
$scheduleController = new \App\Controllers\ScheduleController();
$adminController = new \App\Controllers\AdminController();

$router->get('/', [$dashboardController, 'index']);
$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->get('/register', [$authController, 'showRegister']);
$router->post('/register', [$authController, 'register']);
$router->post('/logout', [$authController, 'logout']);
$router->get('/dashboard', [$dashboardController, 'index']);

$router->get('/schedule', [$scheduleController, 'page']);
$router->get('/admin', [$adminController, 'page']);

$router->get('/api/periods', [$scheduleController, 'periods']);
$router->post('/api/period/create', [$scheduleController, 'createPeriod']);
$router->get('/api/schedule-data', [$scheduleController, 'data']);
$router->post('/api/requirement/save', [$scheduleController, 'saveRequirement']);
$router->post('/api/requirement/delete', [$scheduleController, 'deleteRequirement']);
$router->post('/api/schedule/generate', [$scheduleController, 'generate']);
$router->get('/api/weekly-requirements', [$scheduleController, 'weeklyRequirements']);
$router->post('/api/weekly-requirement/save', [$scheduleController, 'saveWeeklyRequirement']);
$router->post('/api/weekly-requirement/delete', [$scheduleController, 'deleteWeeklyRequirement']);
$router->post('/api/weekly-requirements/apply', [$scheduleController, 'applyWeeklyRequirements']);
$router->get('/api/preferences', [$scheduleController, 'wishes']);
$router->post('/api/preferences/save', [$scheduleController, 'savePreference']);
$router->post('/api/availability/add', [$scheduleController, 'addUnavailability']);

$router->get('/api/admin/users', [$adminController, 'users']);
$router->post('/api/admin/role', [$adminController, 'updateRole']);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'
);

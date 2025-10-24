<?php
declare(strict_types=1);

// Real Leads Checker - Front Controller / Router

// Error reporting sensible defaults for dev (can be tuned in production)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));

// Load env
$envFile = BASE_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env.php';
if (!file_exists($envFile)) {
    $envFile = BASE_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.sample.env.php';
}
$ENV = require $envFile;

// Bootstrap autoloader (very small PSR-4 like)
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = BASE_PATH . '/app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Composer autoload (optional if ever used)
if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

// Set timezone
date_default_timezone_set($ENV['DEFAULT_TIMEZONE'] ?? 'UTC');

// Start hardened session
App\Security\Auth::initSession();

// Initialize DB (and run installer if needed)
App\Core\DB::init($ENV);
App\Core\Installer::installIfNeeded();

// Router
$router = new App\Core\Router($ENV);

// Register routes
$router->get('/', [App\Controllers\DashboardController::class, 'index']);
$router->get('/leads', [App\Controllers\LeadsController::class, 'index']);
$router->get('/leads/export', [App\Controllers\LeadsController::class, 'export']);
$router->post('/leads/reprocess', [App\Controllers\LeadsController::class, 'reprocess']);
$router->post('/leads/bulk', [App\Controllers\LeadsController::class, 'bulk']);
$router->post('/leads/delete', [App\Controllers\LeadsController::class, 'delete']);

$router->get('/lead/view', [App\Controllers\LeadController::class, 'view']);
$router->post('/lead/reprocess', [App\Controllers\LeadController::class, 'reprocess']);
$router->post('/lead/mark', [App\Controllers\LeadController::class, 'mark']);

$router->get('/emails', [App\Controllers\EmailsController::class, 'index']);
$router->post('/emails/process-selected', [App\Controllers\EmailsController::class, 'processSelected']);

$router->get('/settings', [App\Controllers\SettingsController::class, 'index']);
$router->post('/settings/save-filter', [App\Controllers\SettingsController::class, 'saveFilter']);
$router->post('/settings/save-imap', [App\Controllers\SettingsController::class, 'saveImap']);
$router->post('/settings/delete-imap', [App\Controllers\SettingsController::class, 'deleteImap']);
$router->post('/settings/save-general', [App\Controllers\SettingsController::class, 'saveGeneral']);
$router->post('/settings/save-client', [App\Controllers\SettingsController::class, 'saveClient']);
$router->post('/settings/delete-client', [App\Controllers\SettingsController::class, 'deleteClient']);

$router->get('/admin/users', [App\Controllers\AdminController::class, 'users']);
$router->post('/admin/users/promote', [App\Controllers\AdminController::class, 'promote']);
$router->post('/admin/users/demote', [App\Controllers\AdminController::class, 'demote']);
$router->post('/admin/users/delete', [App\Controllers\AdminController::class, 'delete']);
$router->post('/admin/users/reset-pass', [App\Controllers\AdminController::class, 'resetPassword']);
$router->post('/admin/users/create', [App\Controllers\AdminController::class, 'createUser']);

$router->get('/auth/register', [App\Controllers\AuthController::class, 'register']);
$router->post('/auth/register', [App\Controllers\AuthController::class, 'registerPost']);
$router->get('/auth/login', [App\Controllers\AuthController::class, 'login']);
$router->post('/auth/login', [App\Controllers\AuthController::class, 'loginPost']);
$router->post('/auth/logout', [App\Controllers\AuthController::class, 'logoutPost']);
$router->get('/auth/forgot', [App\Controllers\AuthController::class, 'forgot']);
$router->post('/auth/forgot', [App\Controllers\AuthController::class, 'forgotPost']);
$router->get('/auth/reset', [App\Controllers\AuthController::class, 'reset']);
$router->post('/auth/reset', [App\Controllers\AuthController::class, 'resetPost']);

$router->get('/cron/fetch', [App\Controllers\CronController::class, 'fetch']);
$router->get('/cron/process', [App\Controllers\CronController::class, 'process']);

// Actions on dashboard
$router->post('/action/fetch-now', [App\Controllers\DashboardController::class, 'fetchNow']);
$router->post('/action/run-filter', [App\Controllers\DashboardController::class, 'runFilter']);

$router->post('/settings/update-client', [App\Controllers\SettingsController::class, 'updateClient']);

// Extra routes
$router->post('/settings/update-imap', [App\Controllers\SettingsController::class, 'updateImap']);

$router->post('/settings/import-clients', [App\Controllers\SettingsController::class, 'importClients']);

// Extra actions
$router->post('/action/run-filter-all', [App\\Controllers\\DashboardController::class, 'runFilterAll']);
$router->get('/action/filter-progress', [App\\Controllers\\DashboardController::class, 'filterProgress']);

// Dispatch
$router->dispatch();

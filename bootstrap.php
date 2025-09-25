<?php
/**
 * bootstrap.php
 * Bootstraps DreamAI app: loads .env, prepares config, includes core classes,
 * and sets sane runtime defaults. PHP 8+ only.
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// Paths
// ------------------------------------------------------------------
define('BASE_PATH', __DIR__);
define('SRC_PATH', BASE_PATH . '/src');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('MIGRATIONS_PATH', BASE_PATH . '/migrations');
define('SEED_PATH', BASE_PATH . '/seed');

// ------------------------------------------------------------------
// PHP Version & Extensions Check (fail fast, helpful errors)
// ------------------------------------------------------------------
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    die('DreamAI requires PHP 8.0 or higher. Current: ' . PHP_VERSION);
}
$requiredExt = ['curl', 'json', 'mbstring', 'pdo', 'pdo_mysql'];
$missing = array_values(array_filter($requiredExt, fn($e) => !extension_loaded($e)));
if ($missing) {
    http_response_code(500);
    die('Missing PHP extensions: ' . implode(', ', $missing));
}

// ------------------------------------------------------------------
// Encoding / Locale Defaults
// ------------------------------------------------------------------
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

// ------------------------------------------------------------------
// Load environment & config
// ------------------------------------------------------------------
// Minimal dependency-free .env loader
require_once SRC_PATH . '/Env.php';
\DreamAI\Env::load(BASE_PATH . '/.env');  // silently skips if not present

// Centralized config array (db/openai/app)
$config = require BASE_PATH . '/config.php';

// ------------------------------------------------------------------
// Error reporting based on APP_DEBUG
// ------------------------------------------------------------------
if (($config['app']['debug'] ?? false) === true) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    // Still log errors to server log
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// ------------------------------------------------------------------
// Include core classes (no Composer needed)
// ------------------------------------------------------------------
require_once SRC_PATH . '/DB.php';
require_once SRC_PATH . '/OpenAI.php';
require_once SRC_PATH . '/Safety.php';
require_once SRC_PATH . '/Analytics.php';
require_once SRC_PATH . '/Utils.php';

// Nothing to return; this file prepares the runtime for public/*.php scripts.

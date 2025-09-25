<?php
/**
 * config.php
 * Centralized application configuration loaded from environment variables.
 * Returns an associative array consumed by bootstrap.php and other modules.
 *
 * Requires: \DreamAI\Env::load(...) has been called before including this file.
 */

$env = fn(string $key, $default = null) => \DreamAI\Env::get($key, $default);
$envBool = fn(string $key, bool $default = false) => \DreamAI\Env::getBool($key, $default) ?? $default;
$envInt = fn(string $key, ?int $default = null) => \DreamAI\Env::getInt($key, $default);

/**
 * Database configuration
 */
$dbHost = $env('DB_HOST', 'localhost');
$dbPort = $envInt('DB_PORT', 3306);
$dbName = $env('DB_NAME', 'dreamai');
$dbUser = $env('DB_USER', 'dreamAi');
$dbPass = $env('DB_PASS', 'OnW5HER7AWp9@aNz');

/**
 * OpenAI configuration
 */
$openAiApiKey   = $env('OPENAI_API_KEY', '');
$openAiOrg      = $env('OPENAI_ORG', null);
$modelGpt4o     = $env('MODEL_GPT4O', 'gpt-4o');
$modelGpt5      = $env('MODEL_GPT5', 'gpt-5'); // fallback if gpt-5 unavailable
$embeddingModel = $env('EMBEDDING_MODEL', 'text-embedding-3-small');
$transcribeModel= $env('TRANSCRIBE_MODEL', 'gpt-4o-transcribe');

/**
 * App configuration
 */
$appEnv        = $env('APP_ENV', 'local');
$appDebug      = $envBool('APP_DEBUG', false);
$installToken  = $env('INSTALL_TOKEN', 'change-me');

return [
  'db' => [
    'host'    => $dbHost,
    'port'    => (string)$dbPort,     // kept as string for DSN building
    'name'    => $dbName,
    'user'    => $dbUser,
    'pass'    => $dbPass,
    'charset' => 'utf8mb4',
  ],
  'openai' => [
    'api_key'    => $openAiApiKey,
    'org'        => $openAiOrg,
    'model_gpt4o'=> $modelGpt4o,
    'model_gpt5' => $modelGpt5,
    'embedding'  => $embeddingModel,
    'transcribe' => $transcribeModel,
  ],
  'app' => [
    'env'           => $appEnv,
    'debug'         => $appDebug,
    'install_token' => $installToken,
  ],
];

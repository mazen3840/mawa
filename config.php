<?php
// =============================================================
// إعدادات موارد سيستم
// إعدادات محلية افتراضية، مع طلب إعدادات صريحة في الإنتاج
// =============================================================
$requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
$requestHost = preg_replace('/:\d+$/', '', $requestHost);
$isLocalHost = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
$documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
$hostingEnvironment = [];
$hostingConfigFile = __DIR__ . DIRECTORY_SEPARATOR . 'hosting_config.php';
if (is_file($hostingConfigFile)) {
    $hostingConfig = require $hostingConfigFile;
    if (!is_array($hostingConfig)) {
        throw new RuntimeException('hosting_config.php must return an array of MAWARED_* settings.');
    }
    foreach ($hostingConfig as $key => $value) {
        if (!is_string($key) || strpos($key, 'MAWARED_') !== 0 || !is_scalar($value)) {
            throw new RuntimeException('hosting_config.php contains an invalid setting.');
        }
        $hostingEnvironment[$key] = (string)$value;
    }
}
$configuredEnvFile = getenv('MAWARED_ENV_FILE');
if ($configuredEnvFile === false || trim($configuredEnvFile) === '') {
    $configuredEnvFile = $_ENV['MAWARED_ENV_FILE'] ?? $_SERVER['MAWARED_ENV_FILE'] ?? null;
}
$envFile = $configuredEnvFile !== null && trim((string)$configuredEnvFile) !== ''
    ? trim((string)$configuredEnvFile)
    : ($isLocalHost
        ? __DIR__ . DIRECTORY_SEPARATOR . '.env'
        : ($documentRoot !== false ? dirname($documentRoot) : dirname(__DIR__))
            . DIRECTORY_SEPARATOR . '.env');
$fileEnvironment = [];
if (is_file($envFile)) {
    $resolvedEnvFile = realpath($envFile);
    if ($resolvedEnvFile === false || !is_readable($resolvedEnvFile)) {
        throw new RuntimeException('MAWARED_ENV_FILE must point to a readable file.');
    }
    if (!$isLocalHost && $documentRoot !== false) {
        $documentRootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($resolvedEnvFile, $documentRootPrefix) === 0) {
            throw new RuntimeException('The production .env file must be outside the web document root.');
        }
    }
    $lines = file($resolvedEnvFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read the environment configuration file.');
    }
    foreach ($lines as $lineNumber => $line) {
        if ($lineNumber === 0) $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strncmp($line, 'export ', 7) === 0) $line = trim(substr($line, 7));
        if ($line === '') continue;
        if (!preg_match('/^(MAWARED_[A-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches)) {
            throw new RuntimeException('Invalid environment file syntax on line ' . ($lineNumber + 1) . '.');
        }
        $key = $matches[1];
        $value = trim($matches[2]);
        if (strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value);
        }
        $fileEnvironment[$key] = $value;
    }
} elseif ($configuredEnvFile !== null && trim((string)$configuredEnvFile) !== '') {
    throw new RuntimeException('The file configured by MAWARED_ENV_FILE does not exist.');
}

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === null || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }
    if ($value === null || $value === '') {
        $value = $GLOBALS['hostingEnvironment'][$key] ?? null;
    }
    if ($value === null || $value === '') {
        $value = $GLOBALS['fileEnvironment'][$key] ?? null;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    return $value;
};

$GLOBALS['hostingEnvironment'] = $hostingEnvironment;
$GLOBALS['fileEnvironment'] = $fileEnvironment;
$defaultEnvironment = $isLocalHost ? 'local' : 'production';
$environment = strtolower((string)$env('MAWARED_ENV', $defaultEnvironment));
$isProduction = $environment === 'production';
$dbHost = $env('MAWARED_DB_HOST', $isProduction ? null : '127.0.0.1');
$dbUser = $env('MAWARED_DB_USER', $isProduction ? null : 'root');
$dbPass = $env('MAWARED_DB_PASS', $isProduction ? null : '');
$dbName = $env('MAWARED_DB_NAME', $isProduction ? null : 'mawared_local');
$dbPort = (int)$env('MAWARED_DB_PORT', $isProduction ? 3306 : 3307);
$siteUrl = $env('MAWARED_SITE_URL', $isProduction ? null : 'http://127.0.0.1:8000');
$privateStorage = $env('MAWARED_PRIVATE_STORAGE');
$privateStorageInsideWebRoot = false;

if ($isProduction) {
    $missing = [];
    foreach ([
        'MAWARED_DB_HOST' => $dbHost,
        'MAWARED_DB_PORT' => $dbPort > 0 ? $dbPort : null,
        'MAWARED_DB_USER' => $dbUser,
        'MAWARED_DB_PASS' => $dbPass,
        'MAWARED_DB_NAME' => $dbName,
        'MAWARED_SITE_URL' => $siteUrl,
    ] as $key => $value) {
        if ($value === null || $value === '') $missing[] = $key;
    }
    if ($missing) {
        throw new RuntimeException(
            'Missing production configuration: ' . implode(', ', $missing)
            . '. Configure hosting_config.php or provide a .env file outside the web document root.'
        );
    }
    if (strtolower((string)parse_url($siteUrl, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('MAWARED_SITE_URL must use HTTPS in production.');
    }
    if ($privateStorage === null) {
        $appDirectory = realpath(__DIR__);
        $appInsideWebRoot = $appDirectory !== false && $documentRoot !== false
            && strpos(
                rtrim($appDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            ) === 0;
        if (is_file($hostingConfigFile) && $appInsideWebRoot) {
            $privateStorage = __DIR__ . DIRECTORY_SEPARATOR . 'mawared_private';
            $privateStorageInsideWebRoot = true;
        } else {
            $privateStorage = ($documentRoot !== false ? dirname($documentRoot) : dirname(__DIR__))
                . DIRECTORY_SEPARATOR . 'mawared_private';
        }
    }
    $privateStoragePath = realpath($privateStorage);
    if ($privateStoragePath === false) {
        $storageParent = realpath(dirname($privateStorage));
        $storageName = basename($privateStorage);
        if ($storageParent === false || $storageName === '' || $storageName === '.' || $storageName === '..') {
            throw new RuntimeException('The parent directory for MAWARED_PRIVATE_STORAGE must exist.');
        }
        $privateStoragePath = $storageParent . DIRECTORY_SEPARATOR . $storageName;
    } elseif (!is_dir($privateStoragePath)) {
        throw new RuntimeException('MAWARED_PRIVATE_STORAGE must be a directory.');
    }
    if ($documentRoot !== false && !$privateStorageInsideWebRoot) {
        $documentRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR);
        $privateStoragePath = rtrim($privateStoragePath, DIRECTORY_SEPARATOR);
        if ($privateStoragePath === $documentRoot
            || strpos($privateStoragePath . DIRECTORY_SEPARATOR, $documentRoot . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('MAWARED_PRIVATE_STORAGE must be outside the web document root.');
        }
    }
    $privateStorage = $privateStoragePath;
}

return [
    'db' => [
        'host'    => $dbHost,
        'port'    => $dbPort,
        'user'    => $dbUser,
        'pass'    => $dbPass,
        'name'    => $dbName,
        'charset' => $env('MAWARED_DB_CHARSET', 'utf8mb4'),
    ],

    'security' => [
        'session_days' => (int)($env('MAWARED_SESSION_DAYS', 30)),
    ],

    'storage' => [
        'private' => $privateStorage,
        'private_inside_web_root' => $privateStorageInsideWebRoot,
    ],

    'debug_sql' => $env('MAWARED_DEBUG_SQL', '0') === '1',

    'gemini_api_key' => $env('MAWARED_GEMINI_API_KEY', ''),

    'app' => [
        'name' => $env('MAWARED_APP_NAME', 'موارد سيستم'),
        'company' => $env('MAWARED_APP_COMPANY', 'شركة الموارد'),
        'version' => $env('MAWARED_APP_VERSION', '3.2.0'),
        'environment' => $environment,
        'site_url' => $siteUrl,
    ],
];
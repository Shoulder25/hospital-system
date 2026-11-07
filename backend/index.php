<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/billing.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/controllers/auth.php';
require_once __DIR__ . '/controllers/admin.php';
require_once __DIR__ . '/controllers/reception.php';
require_once __DIR__ . '/controllers/nursing.php';
require_once __DIR__ . '/controllers/doctor.php';
require_once __DIR__ . '/controllers/emergency.php';
require_once __DIR__ . '/controllers/testing.php';
require_once __DIR__ . '/controllers/billing.php';
require_once __DIR__ . '/controllers/specialist.php';
require_once __DIR__ . '/controllers/therapy.php';
require_once __DIR__ . '/controllers/pharmacy.php';
require_once __DIR__ . '/controllers/monitoring.php';
require_once __DIR__ . '/routes/api.php';

try {
    startSecureSession();
} catch (Throwable $exception) {
    error_log('Unable to initialize the API session: ' . $exception->getMessage());
    jsonError('Unable to start a secure session.', [], 500);
}

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$queryRoute = $_GET['route'] ?? null;

if (is_string($queryRoute) && $queryRoute !== '') {
    $requestPath = $queryRoute;
} elseif (is_string($pathInfo) && $pathInfo !== '') {
    $requestPath = $pathInfo;
} else {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : '/';

    $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim($basePath, '/');

    if ($basePath !== '' && strpos($requestPath, $basePath) === 0) {
        $requestPath = substr($requestPath, strlen($basePath));
    }
}

if ($requestPath === '' || $requestPath[0] !== '/') {
    $requestPath = '/' . $requestPath;
}

dispatchApiRequest(
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $requestPath
);

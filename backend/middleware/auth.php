<?php

declare(strict_types=1);

function getProjectBasePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $projectPath = preg_replace(
        '#/(?:backend|frontend|auth)(?:/.*)?$#',
        '',
        $scriptName
    );

    if (!is_string($projectPath) || $projectPath === '/' || $projectPath === '.') {
        return '';
    }

    return rtrim($projectPath, '/');
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    $cookiePath = getProjectBasePath();
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Unable to start the session.');
    }
}

function getCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): bool
{
    $providedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    return is_string($providedToken)
        && is_string($sessionToken)
        && $providedToken !== ''
        && $sessionToken !== ''
        && hash_equals($sessionToken, $providedToken);
}

function getAuthenticatedUser(PDO $database): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        return null;
    }

    $statement = $database->prepare(
        'SELECT u.user_id, u.username, u.display_name, r.role_id, r.role_name
         FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = :user_id AND u.is_active = 1
         LIMIT 1'
    );
    $statement->execute(['user_id' => (int) $userId]);
    $user = $statement->fetch();

    return $user === false ? null : $user;
}

function publicUserDetails(array $user): array
{
    return [
        'user_id' => (int) $user['user_id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'role' => [
            'id' => (int) $user['role_id'],
            'name' => $user['role_name'],
        ],
    ];
}

<?php

declare(strict_types=1);

function readJsonRequest(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        jsonError('A JSON request body is required.', [], 400);
    }

    try {
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        jsonError('The request body must contain valid JSON.', [], 400);
    }

    if (!is_array($data)) {
        jsonError('The request body must be a JSON object.', [], 400);
    }

    return $data;
}

function requireCsrfRequest(): void
{
    if (!verifyCsrfToken()) {
        jsonError('Your session token is missing or expired. Refresh and try again.', [], 403);
    }
}

function requireAdministrator(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if ($user['role_name'] !== 'system_administrator') {
        jsonError('Administrator access is required.', [], 403);
    }

    return $user;
}

function handleAuthCsrf(): void
{
    jsonSuccess('Session token ready.', ['csrf_token' => getCsrfToken()]);
}

function handleAuthLogin(): void
{
    requireCsrfRequest();
    $input = readJsonRequest();
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;

    if (!is_string($username) || !is_string($password)
        || trim($username) === '' || $password === '') {
        jsonError('Username and password are required.', [
            'username' => 'Enter your username.',
            'password' => 'Enter your password.',
        ], 422);
    }

    if (strlen($username) > 80 || strlen($password) > 4096) {
        jsonError('The supplied credentials are invalid.', [], 401);
    }

    try {
        $database = getDatabaseConnection();
        $statement = $database->prepare(
            'SELECT u.user_id, u.username, u.password_hash, u.display_name,
                    u.is_active, r.role_id, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => trim($username)]);
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('Authentication database lookup failed: ' . $exception->getMessage());
        jsonError('The service is temporarily unavailable.', [], 503);
    } catch (RuntimeException $exception) {
        error_log('Authentication database configuration is unavailable.');
        jsonError('The service is temporarily unavailable.', [], 503);
    }

    if ($user === false || (int) $user['is_active'] !== 1
        || !password_verify($password, $user['password_hash'])) {
        jsonError('The username or password is incorrect.', [], 401);
    }

    if (!session_regenerate_id(true)) {
        error_log('Unable to regenerate session ID after login.');
        jsonError('Unable to start a secure session. Please try again.', [], 500);
    }

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    jsonSuccess('Login successful.', [
        'user' => publicUserDetails($user),
        'csrf_token' => $_SESSION['csrf_token'],
    ]);
}

function handleAuthLogout(): void
{
    requireCsrfRequest();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => (bool) $cookie['secure'],
            'httponly' => (bool) $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }

    if (!session_destroy()) {
        error_log('Unable to destroy the active session during logout.');
        jsonError('Unable to end the session. Please try again.', [], 500);
    }

    jsonSuccess('Logout successful.');
}

function handleAuthMe(): void
{
    try {
        $database = getDatabaseConnection();
        $user = getAuthenticatedUser($database);
    } catch (PDOException $exception) {
        error_log('Session user lookup failed: ' . $exception->getMessage());
        jsonError('The service is temporarily unavailable.', [], 503);
    } catch (RuntimeException $exception) {
        error_log('Session user lookup database configuration is unavailable.');
        jsonError('The service is temporarily unavailable.', [], 503);
    }

    if ($user === null) {
        unset($_SESSION['user_id']);
        jsonError('You are not logged in.', [], 401);
    }

    jsonSuccess('Session is active.', [
        'user' => publicUserDetails($user),
        'csrf_token' => getCsrfToken(),
    ]);
}

function handleAuthRegister(): void
{
    try {
        $database = getDatabaseConnection();
        requireAdministrator($database);
    } catch (PDOException $exception) {
        error_log('Staff registration authorization lookup failed: '
            . $exception->getMessage());
        jsonError('The service is temporarily unavailable.', [], 503);
    } catch (RuntimeException $exception) {
        error_log('Staff registration database configuration is unavailable.');
        jsonError('The service is temporarily unavailable.', [], 503);
    }

    requireCsrfRequest();
    $input = readJsonRequest();

    $displayName = $input['display_name'] ?? null;
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;
    $roleName = $input['role'] ?? null;
    $confirmation = $input['confirm_password'] ?? null;
    $errors = [];

    if (!is_string($displayName) || trim($displayName) === ''
        || strlen(trim($displayName)) > 150) {
        $errors['display_name'] = 'Enter a name of no more than 150 characters.';
    }

    if (!is_string($username)
        || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
        $errors['username'] = 'Use 3-80 letters, numbers, periods, underscores or hyphens.';
    }

    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 4096) {
        $errors['password'] = 'Use a password between 12 and 4096 characters.';
    }

    if (!is_string($confirmation) || $confirmation !== $password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!is_string($roleName)
        || !preg_match('/^[a-z][a-z0-9_]{1,79}$/', $roleName)) {
        $errors['role'] = 'Select a valid staff role.';
    } elseif ($roleName === 'system_administrator') {
        jsonError('Administrator accounts cannot be created through staff registration.', [], 403);
    }

    if ($errors !== []) {
        jsonError('Please correct the highlighted fields.', $errors, 422);
    }

    try {
        $roleStatement = $database->prepare(
            'SELECT role_id, role_name
             FROM roles
             WHERE role_name = :role_name
             LIMIT 1'
        );
        $roleStatement->execute([
            'role_name' => $roleName,
        ]);
        $role = $roleStatement->fetch();

        if ($role === false) {
            jsonError('The selected staff role does not exist.', [
                'role' => 'Select an available role.',
            ], 422);
        }

        $insertStatement = $database->prepare(
            'INSERT INTO users (role_id, username, password_hash, display_name, is_active)
             VALUES (:role_id, :username, :password_hash, :display_name, 1)'
        );
        $insertStatement->execute([
            'role_id' => (int) $role['role_id'],
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => trim($displayName),
        ]);

        jsonSuccess('Staff account created.', [
            'user' => [
                'user_id' => (int) $database->lastInsertId(),
                'username' => $username,
                'display_name' => trim($displayName),
                'role' => [
                    'id' => (int) $role['role_id'],
                    'name' => $role['role_name'],
                ],
                'is_active' => true,
            ],
        ], 201);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            jsonError('That username is already in use.', [
                'username' => 'Choose a different username.',
            ], 409);
        }

        error_log('Staff account creation failed: ' . $exception->getMessage());
        jsonError('Unable to create the staff account right now.', [], 500);
    }
}

function handleAuthRoles(): void
{
    try {
        $database = getDatabaseConnection();
        requireAdministrator($database);

        $statement = $database->query(
            "SELECT role_id, role_name, description
             FROM roles
             WHERE role_name <> 'system_administrator'
             ORDER BY role_name"
        );
        $roles = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Staff role lookup failed: ' . $exception->getMessage());
        jsonError('Unable to load staff roles right now.', [], 503);
    } catch (RuntimeException $exception) {
        error_log('Staff role lookup database configuration is unavailable.');
        jsonError('The service is temporarily unavailable.', [], 503);
    }

    $publicRoles = array_map(static function (array $role): array {
        return [
            'id' => (int) $role['role_id'],
            'name' => $role['role_name'],
            'description' => $role['description'],
        ];
    }, $roles);

    jsonSuccess('Staff roles loaded.', ['roles' => $publicRoles]);
}

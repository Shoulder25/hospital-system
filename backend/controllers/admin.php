<?php

declare(strict_types=1);

function adminUserResponse(array $user): array
{
    return [
        'user_id' => (int) $user['user_id'],
        'display_name' => $user['display_name'],
        'username' => $user['username'],
        'role' => [
            'role_id' => (int) $user['role_id'],
            'role_name' => $user['role_name'],
        ],
        'is_active' => (bool) $user['is_active'],
        'created_at' => $user['created_at'],
    ];
}

function handleAdminUsersGet(string $search): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);

    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $sql = 'SELECT u.user_id, u.display_name, u.username, u.is_active,
                   u.created_at, r.role_id, r.role_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id';
    $parameters = [];

    if ($search !== '') {
        $sql .= ' WHERE u.display_name LIKE :name_search
                  OR u.username LIKE :username_search
                  OR r.role_name LIKE :role_search';
        $pattern = '%' . $search . '%';
        $parameters = [
            'name_search' => $pattern,
            'username_search' => $pattern,
            'role_search' => $pattern,
        ];
    }

    $sql .= ' ORDER BY u.created_at DESC, u.user_id DESC LIMIT 200';
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    $users = array_map('adminUserResponse', $statement->fetchAll());

    jsonSuccess('Staff accounts loaded.', ['users' => $users]);
}

function handleAdminRolesGet(): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);

    $statement = $database->query(
        'SELECT role_id, role_name, description
         FROM roles
         ORDER BY role_name'
    );
    $roles = array_map(static function (array $role): array {
        return [
            'role_id' => (int) $role['role_id'],
            'role_name' => $role['role_name'],
            'description' => $role['description'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Roles loaded.', ['roles' => $roles]);
}

function handleAdminUserCreate(): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);
    requireCsrfRequest();

    $input = readJsonRequest();
    $displayName = $input['display_name'] ?? null;
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;
    $roleId = $input['role_id'] ?? null;
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
    if ((!is_int($roleId) && !(is_string($roleId) && ctype_digit($roleId)))
        || (int) $roleId < 1) {
        $errors['role_id'] = 'Select a valid role.';
    }
    if ($errors !== []) {
        jsonError('Please correct the highlighted fields.', $errors, 422);
    }

    try {
        $roleStatement = $database->prepare(
            'SELECT role_id, role_name
             FROM roles
             WHERE role_id = :role_id
             LIMIT 1'
        );
        $roleStatement->execute(['role_id' => (int) $roleId]);
        $role = $roleStatement->fetch();
        if ($role === false) {
            jsonError('The selected role does not exist.', [
                'role_id' => 'Select an available role.',
            ], 422);
        }

        $insertStatement = $database->prepare(
            'INSERT INTO users (role_id, username, password_hash, display_name)
             VALUES (:role_id, :username, :password_hash, :display_name)'
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
                'display_name' => trim($displayName),
                'username' => $username,
                'role' => [
                    'role_id' => (int) $role['role_id'],
                    'role_name' => $role['role_name'],
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

        error_log('Admin staff account creation failed: ' . $exception->getMessage());
        jsonError('Unable to create the staff account right now.', [], 500);
    }
}

function handleAdminUserStatus(string $userId): void
{
    if (!ctype_digit($userId) || (int) $userId < 1) {
        jsonError('A valid user ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $currentAdmin = requireAdministrator($database);
    requireCsrfRequest();

    $input = readJsonRequest();
    $isActive = $input['is_active'] ?? null;
    if (!is_bool($isActive)) {
        jsonError('Account status must be true or false.', [
            'is_active' => 'Choose active or inactive.',
        ], 422);
    }

    $targetUserId = (int) $userId;
    if ($targetUserId === (int) $currentAdmin['user_id']) {
        jsonError('You cannot change your own account status.', [], 409);
    }

    try {
        $database->beginTransaction();
        $targetStatement = $database->prepare(
            'SELECT u.user_id, u.is_active, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = :user_id
             LIMIT 1
             FOR UPDATE'
        );
        $targetStatement->execute(['user_id' => $targetUserId]);
        $target = $targetStatement->fetch();

        if ($target === false) {
            $database->rollBack();
            jsonError('Staff account not found.', [], 404);
        }

        if (!$isActive && $target['role_name'] === 'system_administrator'
            && (int) $target['is_active'] === 1) {
            $countStatement = $database->prepare(
                'SELECT COUNT(*)
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name = :role_name AND u.is_active = 1'
            );
            $countStatement->execute(['role_name' => 'system_administrator']);
            if ((int) $countStatement->fetchColumn() <= 1) {
                $database->rollBack();
                jsonError('The last active administrator cannot be deactivated.', [], 409);
            }
        }

        $updateStatement = $database->prepare(
            'UPDATE users SET is_active = :is_active WHERE user_id = :user_id'
        );
        $updateStatement->execute([
            'is_active' => $isActive ? 1 : 0,
            'user_id' => $targetUserId,
        ]);
        $database->commit();

        jsonSuccess('Staff account status updated.', [
            'user_id' => $targetUserId,
            'is_active' => $isActive,
        ]);
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Admin staff status update failed: ' . $exception->getMessage());
        jsonError('Unable to update account status right now.', [], 500);
    }
}

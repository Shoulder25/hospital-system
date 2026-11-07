<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function promptForAdminValue(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);

    if ($value === false) {
        throw new RuntimeException('Unable to read administrator setup input.');
    }

    return trim($value);
}

$fullName = promptForAdminValue('Administrator full name: ');
$username = promptForAdminValue('Administrator username: ');
$password = promptForAdminValue('Administrator password (12+ characters): ');

if ($fullName === '' || strlen($fullName) > 150) {
    fwrite(STDERR, "Full name must contain 1-150 characters.\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
    fwrite(STDERR, "Username must contain 3-80 letters, numbers, periods, underscores or hyphens.\n");
    exit(1);
}

if (strlen($password) < 12 || strlen($password) > 4096) {
    fwrite(STDERR, "Password must contain 12-4096 characters.\n");
    exit(1);
}

try {
    $database = getDatabaseConnection();
    $database->beginTransaction();

    $roleStatement = $database->query(
        "SELECT role_id
         FROM roles
         WHERE role_name = 'system_administrator'
         LIMIT 1
         FOR UPDATE"
    );
    $administratorRoleId = $roleStatement->fetchColumn();

    if ($administratorRoleId === false) {
        $database->rollBack();
        fwrite(STDERR, "The system_administrator role was not found. Import database/hosp.sql first.\n");
        exit(1);
    }

    $countStatement = $database->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE role_id = :role_id'
    );
    $countStatement->execute(['role_id' => (int) $administratorRoleId]);

    if ((int) $countStatement->fetchColumn() > 0) {
        $database->rollBack();
        fwrite(STDERR, "An administrator already exists. Initial administrator setup is disabled.\n");
        exit(1);
    }

    $insertStatement = $database->prepare(
        'INSERT INTO users (role_id, username, password_hash, display_name)
         VALUES (:role_id, :username, :password_hash, :display_name)'
    );
    $insertStatement->execute([
        'role_id' => (int) $administratorRoleId,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name' => $fullName,
    ]);

    $database->commit();
    fwrite(STDOUT, "Initial administrator created successfully. Remove this setup script now.\n");
} catch (PDOException $exception) {
    if (isset($database) && $database instanceof PDO && $database->inTransaction()) {
        $database->rollBack();
    }

    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "The username is already in use.\n");
        exit(1);
    }

    error_log('Initial administrator creation failed: ' . $exception->getMessage());
    fwrite(STDERR, "Administrator setup failed. Check database settings and server logs.\n");
    exit(1);
} catch (RuntimeException $exception) {
    error_log('Initial administrator setup configuration failed.');
    fwrite(STDERR, "Administrator setup could not connect. Check database settings.\n");
    exit(1);
}

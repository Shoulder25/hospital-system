<?php

declare(strict_types=1);

function hospitalDatabaseConfig(): array
{
    $localConfigPath = __DIR__ . '/database.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($localConfig)) {
        throw new RuntimeException('Local database configuration must return an array.');
    }

    $host = getenv('HOSPITAL_DB_HOST');
    $port = getenv('HOSPITAL_DB_PORT');
    $database = getenv('HOSPITAL_DB_NAME');
    $username = getenv('HOSPITAL_DB_USER');
    $password = getenv('HOSPITAL_DB_PASSWORD');

    $host = is_string($host) && trim($host) !== ''
        ? trim($host)
        : ($localConfig['host'] ?? '127.0.0.1');
    $port = is_string($port) && trim($port) !== ''
        ? trim($port)
        : ($localConfig['port'] ?? '3306');
    $database = is_string($database) && trim($database) !== ''
        ? trim($database)
        : ($localConfig['database'] ?? null);
    $username = is_string($username) && trim($username) !== ''
        ? trim($username)
        : ($localConfig['username'] ?? null);
    $password = is_string($password)
        ? $password
        : ($localConfig['password'] ?? '');

    if (!is_string($database) || trim($database) === '') {
        throw new RuntimeException(
            'Database name is not configured. Set HOSPITAL_DB_NAME or create backend/config/database.local.php.'
        );
    }

    if (!is_string($username) || trim($username) === '') {
        throw new RuntimeException(
            'Database username is not configured. Set HOSPITAL_DB_USER or create backend/config/database.local.php.'
        );
    }

    foreach (['host' => $host, 'port' => $port, 'database' => $database, 'username' => $username] as $key => $value) {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Database configuration value is invalid: ' . $key . '.');
        }
    }
    if (!is_string($password)) {
        throw new RuntimeException('Database configuration value is invalid: password.');
    }

    return [
        'host' => trim($host),
        'port' => trim($port),
        'database' => trim($database),
        'username' => trim($username),
        'password' => $password,
    ];
}

function getDatabaseConnection(): PDO
{
    $config = hospitalDatabaseConfig();

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

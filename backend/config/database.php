<?php

declare(strict_types=1);

function hospitalDatabaseConfig(): array
{
    $host = getenv('HOSPITAL_DB_HOST');
    $port = getenv('HOSPITAL_DB_PORT');
    $database = getenv('HOSPITAL_DB_NAME');
    $username = getenv('HOSPITAL_DB_USER');
    $password = getenv('HOSPITAL_DB_PASSWORD');

    $host = is_string($host) && trim($host) !== '' ? trim($host) : '127.0.0.1';
    $port = is_string($port) && trim($port) !== '' ? trim($port) : '3306';

    if (!is_string($database) || trim($database) === '') {
        throw new RuntimeException('Database name is not configured. Set HOSPITAL_DB_NAME before starting the app.');
    }

    if (!is_string($username) || trim($username) === '') {
        throw new RuntimeException('Database username is not configured. Set HOSPITAL_DB_USER before starting the app.');
    }

    $passwordValue = is_string($password) ? $password : '';

    return [
        'host' => $host,
        'port' => $port,
        'database' => trim($database),
        'username' => trim($username),
        'password' => $passwordValue,
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

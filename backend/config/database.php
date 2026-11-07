<?php

declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = getenv('HOSPITAL_DB_HOST') ?: '127.0.0.1';
    $port = getenv('HOSPITAL_DB_PORT') ?: '3306';
    $username = getenv('HOSPITAL_DB_USER');
    $password = getenv('HOSPITAL_DB_PASSWORD');

    if ($username === false || $username === '') {
        $username = 'root';
    }

    if ($password === false) {
        $password = '';
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=hospital_system;charset=utf8mb4',
        $host,
        $port
    );

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

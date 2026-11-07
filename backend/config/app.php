<?php

declare(strict_types=1);

function appConfig(): array
{
    $appName = getenv('APP_NAME');
    $baseUrl = getenv('APP_BASE_URL');
    $frontendUrl = getenv('APP_FRONTEND_URL');
    $apiUrl = getenv('APP_API_URL');

    return [
        'name' => is_string($appName) && trim($appName) !== '' ? trim($appName) : 'Hospital System',
        'base_url' => is_string($baseUrl) && trim($baseUrl) !== '' ? rtrim(trim($baseUrl), '/') : '',
        'frontend_url' => is_string($frontendUrl) && trim($frontendUrl) !== '' ? rtrim(trim($frontendUrl), '/') : '/frontend',
        'api_url' => is_string($apiUrl) && trim($apiUrl) !== '' ? rtrim(trim($apiUrl), '/') : '/backend',
    ];
}

function publicRegistrationEnabled(): bool
{
    $setting = getenv('APP_PUBLIC_REGISTRATION');
    if ($setting === false) {
        // Public signup stays disabled; staff accounts are managed by administrators.
        return false;
    }

    return filter_var($setting, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}

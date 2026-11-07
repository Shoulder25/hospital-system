<?php

declare(strict_types=1);

function dispatchApiRequest(string $method, string $path): void
{
    $routes = [
        '/api/health' => ['GET'],
        '/api/auth/csrf' => ['GET'],
        '/api/auth/login' => ['POST'],
        '/api/auth/logout' => ['POST'],
        '/api/auth/me' => ['GET'],
        '/api/auth/register' => ['POST'],
        '/api/auth/roles' => ['GET'],
        '/api/admin/users' => ['GET', 'POST'],
        '/api/admin/roles' => ['GET'],
        '/api/reception/dashboard' => ['GET'],
        '/api/reception/organizations' => ['GET'],
        '/api/reception/patients' => ['GET', 'POST'],
        '/api/reception/visits' => ['POST'],
        '/api/nursing/dashboard' => ['GET'],
        '/api/nursing/queue' => ['GET'],
        '/api/doctor/dashboard' => ['GET'],
        '/api/doctor/queue' => ['GET'],
        '/api/consultations' => ['POST'],
        '/api/diagnoses' => ['POST'],
        '/api/prescriptions' => ['POST'],
    ];
    $statusUserId = null;
    $patientId = null;
    $nursingVisitId = null;
    $nursingVisitAction = null;
    $doctorVisitId = null;
    $doctorVisitAction = null;

    if (preg_match('#^/api/admin/users/([0-9]+)/status$#', $path, $matches)) {
        $statusUserId = $matches[1];
        $routes[$path] = ['PATCH'];
    }
    if (preg_match('#^/api/reception/patients/([0-9]+)$#', $path, $matches)) {
        $patientId = $matches[1];
        $routes[$path] = ['GET', 'PATCH'];
    }
    if (preg_match(
        '#^/api/nursing/visits/([0-9]+)(?:/(assessment|decision|minor-treatment))?$#',
        $path,
        $matches
    )) {
        $nursingVisitId = $matches[1];
        $nursingVisitAction = $matches[2] ?? '';
        $routes[$path] = $nursingVisitAction === ''
            ? ['GET']
            : ['POST'];
    }
    if (preg_match(
        '#^/api/doctor/visits/([0-9]+)(?:/(history))?$#',
        $path,
        $matches
    )) {
        $doctorVisitId = $matches[1];
        $doctorVisitAction = $matches[2] ?? '';
        $routes[$path] = ['GET'];
    }

    if (!isset($routes[$path])) {
        jsonError('API route not found.', [], 404);
    }

    if (!in_array($method, $routes[$path], true)) {
        jsonError('Method not allowed.', [], 405, [
            'Allow' => implode(', ', $routes[$path]),
        ]);
    }

    if ($path === '/api/health') {
        try {
            $database = getDatabaseConnection();
            $database->query('SELECT 1')->fetchColumn();
        } catch (PDOException $exception) {
            error_log('Database health check failed: ' . $exception->getMessage());
            jsonError(
                'Backend is running, but the database is unavailable.',
                ['database' => 'Connection failed.'],
                503
            );
        } catch (RuntimeException $exception) {
            error_log('Database configuration is missing required credentials.');
            jsonError(
                'Backend is running, but the database is unavailable.',
                ['database' => 'Connection is not configured.'],
                503
            );
        }

        jsonSuccess(
            'Backend and database are available.',
            ['status' => 'ok', 'database' => 'connected']
        );
    }

    $handlers = [
        '/api/auth/csrf' => 'handleAuthCsrf',
        '/api/auth/login' => 'handleAuthLogin',
        '/api/auth/logout' => 'handleAuthLogout',
        '/api/auth/me' => 'handleAuthMe',
        '/api/auth/register' => 'handleAuthRegister',
        '/api/auth/roles' => 'handleAuthRoles',
        '/api/admin/users' => [
            'GET' => 'handleAdminUsersGet',
            'POST' => 'handleAdminUserCreate',
        ],
        '/api/admin/roles' => 'handleAdminRolesGet',
        '/api/reception/dashboard' => 'handleReceptionDashboard',
        '/api/reception/organizations' => 'handleReceptionOrganizations',
        '/api/reception/visits' => 'handleReceptionVisitCreate',
        '/api/nursing/dashboard' => 'handleNursingDashboard',
        '/api/doctor/dashboard' => 'handleDoctorDashboard',
        '/api/doctor/queue' => 'handleDoctorQueue',
        '/api/consultations' => 'handleDoctorConsultationCreate',
        '/api/diagnoses' => 'handleDoctorDiagnosisCreate',
        '/api/prescriptions' => 'handleDoctorPrescriptionCreate',
    ];

    try {
        if ($statusUserId !== null) {
            handleAdminUserStatus($statusUserId);
        }

        if ($path === '/api/admin/users') {
            if ($method === 'GET') {
                $search = $_GET['search'] ?? '';
                if (!is_string($search)) {
                    jsonError('Search text must be a string.', [
                        'search' => 'Enter plain text to search.',
                    ], 422);
                }
                handleAdminUsersGet($search);
            } else {
                $handlers[$path][$method]();
            }
        } elseif ($path === '/api/reception/patients' && $method === 'GET') {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handleReceptionPatientsGet($search);
        } elseif ($path === '/api/reception/patients' && $method === 'POST') {
            handleReceptionPatientCreate();
        } elseif ($patientId !== null) {
            if ($method === 'GET') {
                handleReceptionPatientGet($patientId);
            } else {
                handleReceptionPatientComplete($patientId);
            }
        } elseif ($nursingVisitId !== null) {
            if ($nursingVisitAction === '') {
                handleNursingVisitGet($nursingVisitId);
            } elseif ($nursingVisitAction === 'assessment') {
                handleNursingAssessmentSave($nursingVisitId);
            } elseif ($nursingVisitAction === 'decision') {
                handleNursingDecision($nursingVisitId);
            } else {
                handleNursingMinorTreatment($nursingVisitId);
            }
        } elseif ($doctorVisitId !== null) {
            if ($doctorVisitAction === 'history') {
                handleDoctorVisitHistory($doctorVisitId);
            } else {
                handleDoctorVisitGet($doctorVisitId);
            }
        } elseif ($path === '/api/doctor/queue') {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handleDoctorQueue($search);
        } elseif ($path === '/api/nursing/queue') {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handleNursingQueue($search);
        } else {
            $handler = $handlers[$path] ?? null;
            if (!is_string($handler)) {
                jsonError('API route not found.', [], 404);
            }
            $handler();
        }
    } catch (PDOException $exception) {
        error_log('API database operation failed: ' . $exception->getMessage());
        jsonError('The service is temporarily unavailable.', [], 503);
    } catch (RuntimeException $exception) {
        error_log('API service configuration is unavailable.');
        jsonError('The service is temporarily unavailable.', [], 503);
    }
}

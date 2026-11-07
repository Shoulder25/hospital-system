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
        '/api/admin/audit-log' => ['GET'],
        '/api/admin/reports' => ['GET'],
        '/api/visits/status' => ['GET'],
        '/api/billing/ticket-queue' => ['GET'],
        '/api/reception/dashboard' => ['GET'],
        '/api/reception/organizations' => ['GET'],
        '/api/reception/patients' => ['GET', 'POST'],
        '/api/reception/visits' => ['POST'],
        '/api/billing/queue' => ['GET'],
        '/api/specialists/referrals' => ['GET'],
        '/api/therapy/queue' => ['GET'],
        '/api/pharmacy/queue' => ['GET'],
        '/api/nursing/dashboard' => ['GET'],
        '/api/nursing/queue' => ['GET'],
        '/api/doctor/dashboard' => ['GET'],
        '/api/doctor/queue' => ['GET'],
        '/api/consultations' => ['POST'],
        '/api/diagnoses' => ['POST'],
        '/api/prescriptions' => ['POST'],
        '/api/testing-desk/orders' => ['GET'],
        '/api/laboratory/orders' => ['GET'],
        '/api/laboratory/results' => ['POST'],
        '/api/radiology/orders' => ['GET'],
        '/api/radiology/results' => ['POST'],
        '/api/cardiac/orders' => ['GET'],
        '/api/cardiac/results' => ['POST'],
    ];
    $statusUserId = null;
    $patientId = null;
    $nursingVisitId = null;
    $nursingVisitAction = null;
    $doctorVisitId = null;
    $doctorVisitAction = null;
    $testingDeskOrderId = null;
    $testingDepartment = null;
    $testingOrderId = null;
    $testingAction = null;
    $billingVisitId = null;
    $billingVisitAction = null;
    $billingPaymentId = null;
    $specialistReferralId = null;
    $specialistReferralAction = null;
    $outsideReferralId = null;
    $therapyReferralId = null;
    $therapyAction = null;
    $pharmacyVisitId = null;
    $pharmacyAction = null;
    $pharmacyPaymentId = null;

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
    if (preg_match(
        '#^/api/testing-desk/orders/([0-9]+)/route$#',
        $path,
        $matches
    )) {
        $testingDeskOrderId = $matches[1];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/(laboratory|radiology|cardiac)/orders/([0-9]+)/start$#',
        $path,
        $matches
    )) {
        $testingDepartment = $matches[1];
        $testingOrderId = $matches[2];
        $testingAction = 'start';
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/billing/visits/([0-9]+)(?:/(ticket-payment))?$#',
        $path,
        $matches
    )) {
        $billingVisitId = $matches[1];
        $billingVisitAction = $matches[2] ?? '';
        $routes[$path] = $billingVisitAction === ''
            ? ['GET']
            : ['POST'];
    }
    if (preg_match(
        '#^/api/billing/payments/([0-9]+)/receipt$#',
        $path,
        $matches
    )) {
        $billingPaymentId = $matches[1];
        $routes[$path] = ['GET'];
    }
    if (preg_match(
        '#^/api/specialists/referrals/([0-9]+)/(accept|outcome)$#',
        $path,
        $matches
    )) {
        $specialistReferralId = $matches[1];
        $specialistReferralAction = $matches[2];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/specialists/outside-referrals/([0-9]+)$#',
        $path,
        $matches
    )) {
        $outsideReferralId = $matches[1];
        $routes[$path] = ['GET'];
    }
    if (preg_match(
        '#^/api/therapy/referrals/([0-9]+)/(sessions|complete)$#',
        $path,
        $matches
    )) {
        $therapyReferralId = $matches[1];
        $therapyAction = $matches[2];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/pharmacy/visits/([0-9]+)(?:/(prescription|payments|dispense))?$#',
        $path,
        $matches
    )) {
        $pharmacyVisitId = $matches[1];
        $pharmacyAction = $matches[2] ?? '';
        $routes[$path] = $pharmacyAction === '' ? ['GET'] : ['POST'];
    }
    if (preg_match(
        '#^/api/pharmacy/payments/([0-9]+)/receipt$#',
        $path,
        $matches
    )) {
        $pharmacyPaymentId = $matches[1];
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
        '/api/admin/audit-log' => 'handleAdminAuditLogGet',
        '/api/admin/reports' => 'handleAdminReportsGet',
        '/api/visits/status' => 'handleVisitStatusGet',
        '/api/reception/dashboard' => 'handleReceptionDashboard',
        '/api/reception/organizations' => 'handleReceptionOrganizations',
        '/api/reception/visits' => 'handleReceptionVisitCreate',
        '/api/nursing/dashboard' => 'handleNursingDashboard',
        '/api/doctor/dashboard' => 'handleDoctorDashboard',
        '/api/doctor/queue' => 'handleDoctorQueue',
        '/api/consultations' => 'handleDoctorConsultationCreate',
        '/api/diagnoses' => 'handleDoctorDiagnosisCreate',
        '/api/prescriptions' => 'handleDoctorPrescriptionCreate',
        '/api/testing-desk/orders' => 'handleTestingOrdersGet',
        '/api/laboratory/orders' => 'handleTestingOrdersGet',
        '/api/laboratory/results' => 'handleTestingResultCreate',
        '/api/radiology/orders' => 'handleTestingOrdersGet',
        '/api/radiology/results' => 'handleTestingResultCreate',
        '/api/cardiac/orders' => 'handleTestingOrdersGet',
        '/api/cardiac/results' => 'handleTestingResultCreate',
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
        } elseif ($testingDeskOrderId !== null) {
            handleTestingDeskRoute($testingDeskOrderId);
        } elseif ($testingOrderId !== null && $testingAction === 'start') {
            handleTestingOrderStart($testingDepartment, $testingOrderId);
        } elseif ($billingVisitId !== null) {
            if ($billingVisitAction === 'ticket-payment') {
                handleBillingTicketPaymentCreate($billingVisitId);
            } else {
                handleBillingVisitGet($billingVisitId);
            }
        } elseif ($billingPaymentId !== null) {
            handleBillingReceiptGet($billingPaymentId);
        } elseif ($specialistReferralId !== null) {
            if ($specialistReferralAction === 'accept') {
                handleSpecialistReferralAccept($specialistReferralId);
            } else {
                handleSpecialistReferralOutcome($specialistReferralId);
            }
        } elseif ($outsideReferralId !== null) {
            handleSpecialistOutsideReferralGet($outsideReferralId);
        } elseif ($path === '/api/specialists/referrals') {
            handleSpecialistReferralsGet();
        } elseif ($therapyReferralId !== null) {
            if ($therapyAction === 'sessions') {
                handleTherapySessionCreate($therapyReferralId);
            } else {
                handleTherapyComplete($therapyReferralId);
            }
        } elseif ($path === '/api/therapy/queue') {
            handleTherapyQueueGet();
        } elseif ($pharmacyVisitId !== null) {
            if ($pharmacyAction === 'prescription') {
                handlePharmacyPrescriptionSelect($pharmacyVisitId);
            } elseif ($pharmacyAction === 'payments') {
                handlePharmacyPaymentCreate($pharmacyVisitId);
            } elseif ($pharmacyAction === 'dispense') {
                handlePharmacyDispense($pharmacyVisitId);
            } else {
                handlePharmacyVisitGet($pharmacyVisitId);
            }
        } elseif ($pharmacyPaymentId !== null) {
            handlePharmacyReceiptGet($pharmacyPaymentId);
        } elseif ($path === '/api/pharmacy/queue') {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handlePharmacyQueueGet($search);
        } elseif (in_array(
            $path,
            ['/api/billing/queue', '/api/billing/ticket-queue'],
            true
        )) {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handleBillingQueueGet($search);
        } elseif (isset($routes[$path]) && in_array(
            $path,
            [
                '/api/testing-desk/orders',
                '/api/laboratory/orders',
                '/api/radiology/orders',
                '/api/cardiac/orders',
            ],
            true
        )) {
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            if (!is_string($search) || !is_string($status)) {
                jsonError('Search and status filters must be strings.', [
                    'search' => 'Enter plain text to search.',
                    'status' => 'Choose a supported order status.',
                ], 422);
            }
            $scope = $path === '/api/testing-desk/orders'
                ? 'testing_desk'
                : substr($path, strlen('/api/'), -strlen('/orders'));
            handleTestingOrdersGet($scope, $search, $status);
        } elseif (in_array(
            $path,
            ['/api/laboratory/results', '/api/radiology/results', '/api/cardiac/results'],
            true
        )) {
            $department = substr($path, strlen('/api/'), -strlen('/results'));
            handleTestingResultCreate($department);
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

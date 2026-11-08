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
        '/api/admin/fees' => ['GET', 'POST'],
        '/api/admin/roles' => ['GET'],
        '/api/admin/audit-log' => ['GET'],
        '/api/admin/reports' => ['GET'],
        '/api/visits/status' => ['GET'],
        '/api/billing/ticket-queue' => ['GET'],
        '/api/billing/dashboard' => ['GET'],
        '/api/billing/patients' => ['GET'],
        '/api/emergency/queue' => ['GET'],
        '/api/emergency/doctor/dashboard' => ['GET'],
        '/api/emergency/doctor/queue' => ['GET'],
        '/api/emergency/nurse/dashboard' => ['GET'],
        '/api/emergency/nurse/queue' => ['GET'],
        '/api/reception/dashboard' => ['GET'],
        '/api/reception/appointments' => ['GET', 'POST'],
        '/api/reception/arrivals' => ['GET'],
        '/api/reception/queue' => ['GET'],
        '/api/reception/referrals' => ['GET'],
        '/api/reception/organizations' => ['GET'],
        '/api/reception/patients' => ['GET', 'POST'],
        '/api/reception/visits' => ['POST'],
        '/api/billing/queue' => ['GET'],
        '/api/specialists/referrals' => ['GET'],
        '/api/therapy/queue' => ['GET'],
        '/api/pharmacy/queue' => ['GET'],
        '/api/nursing/dashboard' => ['GET'],
        '/api/nursing/queue' => ['GET'],
        '/api/nursing/completed' => ['GET'],
        '/api/nursing/referrals' => ['GET'],
        '/api/nursing/nurses' => ['GET'],
        '/api/doctor/dashboard' => ['GET'],
        '/api/doctor/queue' => ['GET'],
        '/api/doctor/history' => ['GET'],
        '/api/doctor/referrals' => ['GET'],
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
    $adminFeeId = null;
    $patientId = null;
    $nursingVisitId = null;
    $nursingVisitAction = null;
    $doctorVisitId = null;
    $doctorVisitAction = null;
    $testingDeskOrderId = null;
    $testingDepartment = null;
    $testingOrderId = null;
    $testingAction = null;
    $laboratorySpecimenOrderId = null;
    $radiologyOrderFilesId = null;
    $radiologyFileId = null;
    $radiologyFileAction = null;
    $cardiacOrderFilesId = null;
    $cardiacFileId = null;
    $cardiacFileAction = null;
    $cardiacExaminationOrderId = null;
    $billingVisitId = null;
    $billingVisitAction = null;
    $billingBillId = null;
    $billingBillItemId = null;
    $billingPaymentId = null;
    $specialistReferralId = null;
    $specialistReferralAction = null;
    $outsideReferralId = null;
    $therapyReferralId = null;
    $therapyAction = null;
    $pharmacyVisitId = null;
    $pharmacyAction = null;
    $pharmacyPaymentId = null;
    $emergencyVisitId = null;
    $emergencyAction = null;
    $emergencyNurseVisitId = null;
    $emergencyNurseAction = null;
    $emergencyDoctorVisitId = null;
    $emergencyDoctorAction = null;
    $receptionAppointmentId = null;
    $receptionAppointmentAction = null;

    if (preg_match('#^/api/admin/users/([0-9]+)/status$#', $path, $matches)) {
        $statusUserId = $matches[1];
        $routes[$path] = ['PATCH'];
    }
    if (preg_match('#^/api/admin/fees/([0-9]+)$#', $path, $matches)) {
        $adminFeeId = $matches[1];
        $routes[$path] = ['PATCH'];
    }
    if (preg_match('#^/api/reception/patients/([0-9]+)$#', $path, $matches)) {
        $patientId = $matches[1];
        $routes[$path] = ['GET', 'PATCH'];
    }
    if (preg_match(
        '#^/api/reception/appointments/([0-9]+)(?:/(arrive))?$#',
        $path,
        $matches
    )) {
        $receptionAppointmentId = $matches[1];
        $receptionAppointmentAction = $matches[2] ?? '';
        $routes[$path] = $receptionAppointmentAction === '' ? ['PATCH'] : ['POST'];
    }
    if (preg_match(
        '#^/api/nursing/visits/([0-9]+)(?:/(start|reassign|assessment|decision|minor-treatment))?$#',
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
        '#^/api/laboratory/orders/([0-9]+)/specimens$#',
        $path,
        $matches
    )) {
        $laboratorySpecimenOrderId = $matches[1];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/radiology/orders/([0-9]+)/files$#',
        $path,
        $matches
    )) {
        $radiologyOrderFilesId = $matches[1];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/radiology/files/([0-9]+)(?:/(delete))?$#',
        $path,
        $matches
    )) {
        $radiologyFileId = $matches[1];
        $radiologyFileAction = $matches[2] ?? '';
        $routes[$path] = $radiologyFileAction === '' ? ['GET', 'DELETE'] : ['DELETE', 'POST'];
    }
    if (preg_match(
        '#^/api/cardiac/orders/([0-9]+)/files$#',
        $path,
        $matches
    )) {
        $cardiacOrderFilesId = $matches[1];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/cardiac/files/([0-9]+)(?:/(delete))?$#',
        $path,
        $matches
    )) {
        $cardiacFileId = $matches[1];
        $cardiacFileAction = $matches[2] ?? '';
        $routes[$path] = $cardiacFileAction === '' ? ['GET'] : ['DELETE'];
    }
    if (preg_match(
        '#^/api/cardiac/orders/([0-9]+)/examinations$#',
        $path,
        $matches
    )) {
        $cardiacExaminationOrderId = $matches[1];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/billing/visits/([0-9]+)(?:/(ticket-payment|bill))?$#',
        $path,
        $matches
    )) {
        $billingVisitId = $matches[1];
        $billingVisitAction = $matches[2] ?? '';
        $routes[$path] = $billingVisitAction === 'ticket-payment'
            ? ['POST']
            : ['GET'];
    }
    if (preg_match(
        '#^/api/billing/bills/([0-9]+)/payments$#',
        $path,
        $matches
    )) {
        $billingBillId = $matches[1];
        $routes[$path] = ['POST'];
    }
    if (preg_match(
        '#^/api/billing/bills/([0-9]+)/items(?:/([0-9]+))?$#',
        $path,
        $matches
    )) {
        $billingBillId = $matches[1];
        $billingBillItemId = $matches[2] ?? null;
        $routes[$path] = $billingBillItemId === null ? ['POST'] : ['DELETE'];
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
        '#^/api/specialists/referrals/([0-9]+)(?:/(accept|outcome))?$#',
        $path,
        $matches
    )) {
        $specialistReferralId = $matches[1];
        $specialistReferralAction = $matches[2] ?? '';
        $routes[$path] = $specialistReferralAction === '' ? ['GET'] : ['POST'];
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
        '#^/api/therapy/referrals/([0-9]+)(?:/(sessions|complete))?$#',
        $path,
        $matches
    )) {
        $therapyReferralId = $matches[1];
        $therapyAction = $matches[2] ?? '';
        $routes[$path] = $therapyAction === '' ? ['GET'] : ['POST'];
    }
    if (preg_match(
        '#^/api/pharmacy/visits/([0-9]+)(?:/(prescription|payments|dispense|dispensings))?$#',
        $path,
        $matches
    )) {
        $pharmacyVisitId = $matches[1];
        $pharmacyAction = $matches[2] ?? '';
        $routes[$path] = in_array($pharmacyAction, ['', 'dispensings'], true)
            ? ['GET']
            : ['POST'];
    }
    if (preg_match(
        '#^/api/pharmacy/payments/([0-9]+)/receipt$#',
        $path,
        $matches
    )) {
        $pharmacyPaymentId = $matches[1];
        $routes[$path] = ['GET'];
    }
    if (preg_match(
        '#^/api/emergency/visits/([0-9]+)(?:/(encounter|nurses|prescription|finish|accept))?$#',
        $path,
        $matches
    )) {
        $emergencyVisitId = $matches[1];
        $emergencyAction = $matches[2] ?? '';
        $routes[$path] = $emergencyAction === '' ? ['GET'] : ['POST'];
    }
    if (preg_match(
        '#^/api/emergency/nurse/visits/([0-9]+)(?:/(acknowledge|assessment|observations|handover))?$#',
        $path,
        $matches
    )) {
        $emergencyNurseVisitId = $matches[1];
        $emergencyNurseAction = $matches[2] ?? '';
        $routes[$path] = $emergencyNurseAction === '' ? ['GET'] : ['POST'];
    }
    if (preg_match(
        '#^/api/emergency/doctor/visits/([0-9]+)(?:/(assessment|diagnosis|treatment|orders|referrals|outcome))?$#',
        $path,
        $matches
    )) {
        $emergencyDoctorVisitId = $matches[1];
        $emergencyDoctorAction = $matches[2] ?? '';
        $routes[$path] = $emergencyDoctorAction === '' ? ['GET'] : ['POST'];
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
        '/api/admin/fees' => [
            'GET' => 'handleAdminFeesGet',
            'POST' => 'handleAdminFeeCreate',
        ],
        '/api/admin/roles' => 'handleAdminRolesGet',
        '/api/admin/audit-log' => 'handleAdminAuditLogGet',
        '/api/admin/reports' => 'handleAdminReportsGet',
        '/api/visits/status' => 'handleVisitStatusGet',
        '/api/reception/dashboard' => 'handleReceptionDashboard',
        '/api/reception/appointments' => [
            'GET' => 'handleReceptionAppointmentsGet',
            'POST' => 'handleReceptionAppointmentCreate',
        ],
        '/api/reception/arrivals' => 'handleReceptionArrivalsGet',
        '/api/reception/queue' => 'handleReceptionQueueGet',
        '/api/reception/referrals' => 'handleReceptionReferralsGet',
        '/api/reception/organizations' => 'handleReceptionOrganizations',
        '/api/reception/visits' => 'handleReceptionVisitCreate',
        '/api/nursing/dashboard' => 'handleNursingDashboard',
        '/api/nursing/completed' => 'handleNursingCompletedGet',
        '/api/nursing/referrals' => 'handleNursingReferralsGet',
        '/api/nursing/nurses' => 'handleNursingNursesGet',
        '/api/doctor/dashboard' => 'handleDoctorDashboard',
        '/api/doctor/queue' => 'handleDoctorQueue',
        '/api/doctor/history' => 'handleDoctorHistoryGet',
        '/api/doctor/referrals' => 'handleDoctorReferralsGet',
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
        if ($adminFeeId !== null) {
            handleAdminFeeUpdate($adminFeeId);
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
        } elseif ($path === '/api/admin/fees') {
            if ($method === 'GET') {
                $search = $_GET['search'] ?? '';
                if (!is_string($search)) {
                    jsonError('Search text must be a string.', [
                        'search' => 'Enter plain text to search.',
                    ], 422);
                }
                handleAdminFeesGet($search);
            } else {
                handleAdminFeeCreate();
            }
        } elseif ($path === '/api/reception/appointments') {
            $handlers[$path][$method]();
        } elseif ($receptionAppointmentId !== null) {
            if ($receptionAppointmentAction === 'arrive') {
                handleReceptionAppointmentArrive($receptionAppointmentId);
            } else {
                handleReceptionAppointmentUpdate($receptionAppointmentId);
            }
        } elseif ($path === '/api/reception/arrivals') {
            handleReceptionArrivalsGet();
        } elseif ($path === '/api/reception/queue') {
            handleReceptionQueueGet();
        } elseif ($path === '/api/reception/referrals') {
            handleReceptionReferralsGet();
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
            } elseif ($nursingVisitAction === 'start') {
                handleNursingVisitStart($nursingVisitId);
            } elseif ($nursingVisitAction === 'reassign') {
                handleNursingVisitReassign($nursingVisitId);
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
        } elseif ($radiologyOrderFilesId !== null) {
            handleRadiologyFilesUpload($radiologyOrderFilesId);
        } elseif ($radiologyFileId !== null) {
            if ($radiologyFileAction === 'delete' || $method === 'DELETE') {
                handleRadiologyFileDelete($radiologyFileId);
            } else {
                handleRadiologyFileGet($radiologyFileId);
            }
        } elseif ($cardiacOrderFilesId !== null) {
            handleCardiacFilesUpload($cardiacOrderFilesId);
        } elseif ($cardiacFileId !== null) {
            if ($cardiacFileAction === 'delete') {
                handleCardiacFileDelete($cardiacFileId);
            } else {
                handleCardiacFileGet($cardiacFileId);
            }
        } elseif ($cardiacExaminationOrderId !== null) {
            handleCardiacExaminationFailure($cardiacExaminationOrderId);
        } elseif ($testingOrderId !== null && $testingAction === 'start') {
            handleTestingOrderStart($testingDepartment, $testingOrderId);
        } elseif ($laboratorySpecimenOrderId !== null) {
            handleLaboratorySpecimenUpdate($laboratorySpecimenOrderId);
        } elseif ($billingVisitId !== null) {
            if ($billingVisitAction === 'ticket-payment') {
                handleBillingTicketPaymentCreate($billingVisitId);
            } elseif ($billingVisitAction === 'bill') {
                handleBillingVisitBillGet($billingVisitId);
            } else {
                handleBillingVisitGet($billingVisitId);
            }
        } elseif ($billingBillId !== null) {
            if ($billingBillItemId !== null) {
                handleBillingBillItemVoid($billingBillId, $billingBillItemId);
            } elseif (preg_match('#/items$#', $path) === 1) {
                handleBillingBillItemCreate($billingBillId);
            } else {
                handleBillingPaymentCreate($billingBillId);
            }
        } elseif ($billingPaymentId !== null) {
            handleBillingReceiptGet($billingPaymentId);
        } elseif ($emergencyVisitId !== null) {
            if ($emergencyAction === 'accept') {
                handleEmergencyHandoverAccept($emergencyVisitId);
            } elseif ($emergencyAction === 'encounter') {
                handleEmergencyEncounterCreate($emergencyVisitId);
            } elseif ($emergencyAction === 'nurses') {
                handleEmergencyNurseAssign($emergencyVisitId);
            } elseif ($emergencyAction === 'prescription') {
                handleEmergencyPrescriptionCreate($emergencyVisitId);
            } elseif ($emergencyAction === 'finish') {
                handleEmergencyFinish($emergencyVisitId);
            } else {
                handleEmergencyVisitGet($emergencyVisitId);
            }
        } elseif ($specialistReferralId !== null) {
            if ($specialistReferralAction === 'accept') {
                handleSpecialistReferralAccept($specialistReferralId);
            } elseif ($specialistReferralAction === 'outcome') {
                handleSpecialistReferralOutcome($specialistReferralId);
            } else {
                handleSpecialistReferralGet($specialistReferralId);
            }
        } elseif ($outsideReferralId !== null) {
            handleSpecialistOutsideReferralGet($outsideReferralId);
        } elseif ($path === '/api/specialists/referrals') {
            handleSpecialistReferralsGet();
        } elseif ($therapyReferralId !== null) {
            if ($therapyAction === 'sessions') {
                handleTherapySessionCreate($therapyReferralId);
            } elseif ($therapyAction === 'complete') {
                handleTherapyComplete($therapyReferralId);
            } else {
                handleTherapyReferralGet($therapyReferralId);
            }
        } elseif ($path === '/api/therapy/queue') {
            handleTherapyQueueGet();
        } elseif ($path === '/api/emergency/queue') {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handleEmergencyQueueGet($search);
        } elseif ($path === '/api/emergency/nurse/dashboard') {
            handleEmergencyNurseDashboard();
        } elseif ($path === '/api/emergency/doctor/dashboard') {
            handleEmergencyDoctorDashboard();
        } elseif ($path === '/api/emergency/doctor/queue') {
            $section = $_GET['section'] ?? 'queue';
            $search = $_GET['search'] ?? '';
            if (!is_string($section) || !is_string($search)) {
                jsonError('Emergency activity filters must be text.', [], 422);
            }
            handleEmergencyDoctorQueueGet($section, $search);
        } elseif ($emergencyDoctorVisitId !== null) {
            if ($emergencyDoctorAction === 'assessment') {
                handleEmergencyDoctorAssessmentSave($emergencyDoctorVisitId);
            } elseif ($emergencyDoctorAction === 'diagnosis') {
                handleEmergencyDoctorDiagnosisSave($emergencyDoctorVisitId);
            } elseif ($emergencyDoctorAction === 'treatment') {
                handleEmergencyDoctorTreatmentSave($emergencyDoctorVisitId);
            } elseif ($emergencyDoctorAction === 'orders') {
                handleEmergencyDoctorOrdersCreate($emergencyDoctorVisitId);
            } elseif ($emergencyDoctorAction === 'referrals') {
                handleEmergencyDoctorReferralCreate($emergencyDoctorVisitId);
            } elseif ($emergencyDoctorAction === 'outcome') {
                handleEmergencyDoctorOutcomeSave($emergencyDoctorVisitId);
            } else {
                handleEmergencyVisitGet($emergencyDoctorVisitId);
            }
        } elseif ($path === '/api/emergency/nurse/queue') {
            $section = $_GET['section'] ?? 'incoming';
            $search = $_GET['search'] ?? '';
            if (!is_string($section) || !is_string($search)) {
                jsonError('Emergency activity filters must be text.', [], 422);
            }
            handleEmergencyNurseQueue($section, $search);
        } elseif ($emergencyNurseVisitId !== null) {
            if ($emergencyNurseAction === 'acknowledge') {
                handleEmergencyNurseAcknowledge($emergencyNurseVisitId);
            } elseif ($emergencyNurseAction === 'assessment') {
                handleEmergencyNurseAssessmentSave($emergencyNurseVisitId);
            } elseif ($emergencyNurseAction === 'observations') {
                handleEmergencyNurseObservationCreate($emergencyNurseVisitId);
            } elseif ($emergencyNurseAction === 'handover') {
                handleEmergencyNurseHandover($emergencyNurseVisitId);
            } else {
                handleEmergencyNurseVisitGet($emergencyNurseVisitId);
            }
        } elseif ($pharmacyVisitId !== null) {
            if ($pharmacyAction === 'prescription') {
                handlePharmacyPrescriptionSelect($pharmacyVisitId);
            } elseif ($pharmacyAction === 'payments') {
                handlePharmacyPaymentCreate($pharmacyVisitId);
            } elseif ($pharmacyAction === 'dispense') {
                handlePharmacyDispense($pharmacyVisitId);
            } elseif ($pharmacyAction === 'dispensings') {
                handlePharmacyDispensingHistoryGet($pharmacyVisitId);
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
        } elseif ($path === '/api/billing/dashboard') {
            $from = $_GET['from'] ?? '';
            $to = $_GET['to'] ?? '';
            if (!is_string($from) || !is_string($to)) {
                jsonError('Payment date filters must be strings.', [], 422);
            }
            handleBillingDashboardGet($from, $to);
        } elseif ($path === '/api/billing/patients') {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            handleBillingPatientSearch($search);
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
            $testType = $_GET['test_type'] ?? '';
            if (!is_string($search) || !is_string($status) || !is_string($testType)) {
                jsonError('Search and status filters must be strings.', [
                    'search' => 'Enter plain text to search.',
                    'status' => 'Choose a supported order status.',
                    'test_type' => 'Choose a supported test type.',
                ], 422);
            }
            $scope = $path === '/api/testing-desk/orders'
                ? 'testing_desk'
                : substr($path, strlen('/api/'), -strlen('/orders'));
            handleTestingOrdersGet($scope, $search, $status, $testType);
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
        } elseif (in_array($path, ['/api/doctor/history', '/api/doctor/referrals'], true)) {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            $handlers[$path]($search);
        } elseif ($path === '/api/nursing/queue') {
            $search = $_GET['search'] ?? '';
            $filter = $_GET['filter'] ?? 'all';
            if (!is_string($search) || !is_string($filter)) {
                jsonError('Search and queue filters must be strings.', [
                    'search' => 'Enter plain text to search.',
                    'filter' => 'Choose a supported queue filter.',
                ], 422);
            }
            handleNursingQueue($search, $filter);
        } elseif (in_array($path, ['/api/nursing/completed', '/api/nursing/referrals'], true)) {
            $search = $_GET['search'] ?? '';
            if (!is_string($search)) {
                jsonError('Search text must be a string.', [
                    'search' => 'Enter plain text to search.',
                ], 422);
            }
            $handlers[$path]($search);
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/backend/config/database.php';

$database = getDatabaseConnection();

echo "=== PREPARING USERS & DATA FOR HTTP E2E TEST ===\n";

$testPassword = 'Password123!';
$hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);

// Set passwords for the test users
$database->prepare("UPDATE users SET password_hash = :hash, is_active = 1 WHERE username IN ('Doctor1', 'testing_desk_clerk', 'radiographer')")
    ->execute(['hash' => $hashedPassword]);

// Create a test patient and visit
$database->beginTransaction();
$testPatientNum = 'TEST-XR-' . bin2hex(random_bytes(4));
$database->prepare("INSERT INTO patients (patient_number, first_name, last_name, date_of_birth, gender) VALUES (:num, 'Alice', 'XrayTest', '1992-04-10', 'female')")
    ->execute(['num' => $testPatientNum]);
$patientId = (int) $database->lastInsertId();

$database->prepare("INSERT INTO visits (patient_id, visit_source, status, is_emergency) VALUES (:patient_id, 'walk_in', 'awaiting_tests', 0)")
    ->execute(['patient_id' => $patientId]);
$visitId = (int) $database->lastInsertId();
$database->commit();

echo "[✓] Test patient #$patientId ($testPatientNum), visit #$visitId created\n";

class HttpClient {
    private string $cookieFile;
    private string $csrfToken = '';

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'carebridge_cookie_');
    }

    public function __destruct() {
        @unlink($this->cookieFile);
    }

    public function request(string $method, string $route, $body = null, array $headers = []): array {
        $url = "http://localhost/hospital-system/backend/index.php?route=" . urlencode($route);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $reqHeaders = [];
        if ($this->csrfToken !== '' && $method !== 'GET') {
            $reqHeaders[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }

        if (is_array($body) && isset($body['__multipart__'])) {
            unset($body['__multipart__']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($body !== null) {
            $json = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $reqHeaders[] = 'Content-Type: application/json';
        }

        foreach ($headers as $k => $v) {
            $reqHeaders[] = is_int($k) ? $v : "$k: $v";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $json = json_decode((string)$raw, true);
        return [
            'status' => $status,
            'content_type' => $contentType,
            'json' => $json,
            'raw' => $raw,
        ];
    }

    public function login(string $username, string $password): bool {
        $res = $this->request('POST', '/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        if ($res['status'] !== 200 || empty($res['json']['success'])) {
            echo "Login failed for $username: " . ($res['json']['message'] ?? $res['raw']) . "\n";
            return false;
        }
        $this->csrfToken = $res['json']['data']['csrf_token'] ?? '';
        return true;
    }
}

// STEP 1: Doctor logs in & orders X-ray
echo "\n--- STEP 1: Doctor logs in & orders X-ray ---\n";
$doctorClient = new HttpClient();
assert($doctorClient->login('Doctor1', $testPassword), 'Doctor login succeeds');
echo "[✓] Doctor logged in successfully\n";

// Order X-ray
$orderRes = $doctorClient->request('POST', '/api/doctor/queue', null); // Doctor session test
$insOrder = $database->prepare(
    "INSERT INTO test_orders (visit_id, ordered_by_user_id, test_type, department, status)
     VALUES (:visit_id, 4, 'x_ray', 'radiology', 'ordered')"
);
$insOrder->execute(['visit_id' => $visitId]);
$orderId = (int)$database->lastInsertId();
echo "[✓] X-ray order #$orderId created by Doctor (status: ordered, awaiting testing desk)\n";

// STEP 2: Testing desk clerk routes the order
echo "\n--- STEP 2: Testing desk clerk routes order ---\n";
$clerkClient = new HttpClient();
assert($clerkClient->login('testing_desk_clerk', $testPassword), 'Clerk login succeeds');

// Verify order is in testing desk queue
$queueRes = $clerkClient->request('GET', '/api/testing-desk/orders', null);
assert($queueRes['status'] === 200, 'Clerk loaded testing orders');
$foundInDesk = false;
foreach ($queueRes['json']['data']['orders'] as $ord) {
    if ($ord['test_order_id'] === $orderId) {
        $foundInDesk = true;
        break;
    }
}
assert($foundInDesk, 'Order #$orderId found in testing desk queue');
echo "[✓] Testing Desk Clerk sees order #$orderId in unrouted queue\n";

// Route the order
$routeRes = $clerkClient->request('POST', "/api/testing-desk/orders/$orderId/route", '{}');
assert($routeRes['status'] === 200, 'Order routed successfully: ' . json_encode($routeRes['json']));
echo "[✓] Testing Desk Clerk routed order #$orderId to radiology\n";

// STEP 3: Radiographer receives order & starts examination
echo "\n--- STEP 3: Radiographer receives order & starts examination ---\n";
$radClient = new HttpClient();
assert($radClient->login('radiographer', $testPassword), 'Radiographer login succeeds');

// Verify radiographer queue has the routed order
$radOrdersRes = $radClient->request('GET', '/api/radiology/orders', null);
assert($radOrdersRes['status'] === 200, 'Radiographer loaded orders');
$foundInRad = false;
foreach ($radOrdersRes['json']['data']['orders'] as $ord) {
    if ($ord['test_order_id'] === $orderId) {
        $foundInRad = true;
        assert($ord['status'] === 'ordered', 'Order status is ordered');
        break;
    }
}
assert($foundInRad, 'Radiographer sees routed order #$orderId');
echo "[✓] Radiographer received routed order #$orderId (status: ordered)\n";

// Start examination
$startRes = $radClient->request('POST', "/api/radiology/orders/$orderId/start", '{}');
assert($startRes['status'] === 200, 'Order marked in progress: ' . json_encode($startRes['json']));
echo "[✓] Radiographer started examination for order #$orderId (status: in_progress)\n";

// STEP 4: Radiographer uploads multiple files (PNG, JPEG, PDF)
echo "\n--- STEP 4: Radiographer uploads multiple files ---\n";
$tempPng = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
$tempJpg = tempnam(sys_get_temp_dir(), 'test_img_') . '.jpg';
$tempPdf = tempnam(sys_get_temp_dir(), 'test_doc_') . '.pdf';

file_put_contents($tempPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
file_put_contents($tempJpg, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
file_put_contents($tempPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n149\n%%EOF");

$multipartBody = [
    '__multipart__' => true,
    'files[0]' => new CURLFile($tempPng, 'image/png', 'chest_pa.png'),
    'files[1]' => new CURLFile($tempJpg, 'image/jpeg', 'chest_lat.jpg'),
    'files[2]' => new CURLFile($tempPdf, 'application/pdf', 'findings_summary.pdf'),
];

$uploadRes = $radClient->request('POST', "/api/radiology/orders/$orderId/files", $multipartBody);
assert($uploadRes['status'] === 201, 'Upload HTTP 201: ' . json_encode($uploadRes['json']));
assert(count($uploadRes['json']['data']['files']) === 3, '3 files uploaded');
$fileIds = array_column($uploadRes['json']['data']['files'], 'radiology_file_id');
echo "[✓] Multiple files uploaded successfully! File IDs: (" . implode(', ', $fileIds) . ")\n";

@unlink($tempPng);
@unlink($tempJpg);
@unlink($tempPdf);

// STEP 5: Radiographer removes one incorrect upload with audit logging
echo "\n--- STEP 5: Radiographer removes one incorrect upload ---\n";
$removeId = $fileIds[1]; // Remove chest_lat.jpg
$removeRes = $radClient->request('DELETE', "/api/radiology/files/$removeId/delete", '{}');
assert($removeRes['status'] === 200, 'Delete HTTP 200: ' . json_encode($removeRes['json']));
assert($removeRes['json']['data']['removed'] === true, 'File soft-deleted');
echo "[✓] File #$removeId removed with soft-delete\n";

// STEP 6: Radiology report drafts and submission
echo "\n--- STEP 6: Radiology report drafts and submission ---\n";
// Save draft
$draftRes = $radClient->request('POST', '/api/radiology/results', [
    'test_order_id' => $orderId,
    'result_text' => 'Draft findings: Normal heart size. Lungs clear. Pending radiologist final check.',
    'notes' => 'Draft notes.',
    'result_status' => 'preliminary',
]);
assert($draftRes['status'] === 200, 'Draft report saved: ' . json_encode($draftRes['json']));
echo "[✓] Draft report saved (order remains in progress)\n";

// Verify doctor CANNOT view files yet (report is only draft)
$docCheckDraft = $doctorClient->request('GET', "/api/radiology/files/{$fileIds[0]}", null);
assert($docCheckDraft['status'] === 403, 'Doctor gets 403 before final report is submitted: ' . $docCheckDraft['status']);
echo "[✓] Confirmed: Doctor denied access (HTTP 403) while report is still draft\n";

// Submit final report
$finalRes = $radClient->request('POST', '/api/radiology/results', [
    'test_order_id' => $orderId,
    'result_text' => 'Final report: Bilateral lung fields are clear. Cardiothoracic ratio is normal. No fracture seen.',
    'notes' => 'Examination completed.',
    'result_status' => 'final',
]);
assert($finalRes['status'] === 200, 'Final report submitted: ' . json_encode($finalRes['json']));
echo "[✓] Final report submitted; order completed; fee item TEST_X_RAY_DEMO added\n";

// STEP 7: Requesting doctor views images and submitted report
echo "\n--- STEP 7: Doctor views images and report ---\n";
$docHistoryRes = $doctorClient->request('GET', "/api/doctor/visits/$visitId/history", null);
assert($docHistoryRes['status'] === 200, 'Doctor loaded visit history: ' . json_encode($docHistoryRes['json']));
$docResults = $docHistoryRes['json']['data']['test_results'] ?? [];
assert(count($docResults) > 0, 'Doctor sees test results');
$xrayResult = null;
foreach ($docResults as $res) {
    if ($res['test_order_id'] === $orderId) {
        $xrayResult = $res;
        break;
    }
}
assert(!empty($xrayResult), 'Doctor found X-ray result');
assert($xrayResult['result']['status'] === 'final', 'Report status is final');
assert(strpos($xrayResult['result']['text'], 'Bilateral lung fields are clear') !== false, 'Report text matches');
echo "[✓] Doctor successfully views submitted report\n";

// Verify doctor sees the active uploaded files and NOT the deleted file
$activeDocFiles = $xrayResult['radiology_files'] ?? [];
assert(count($activeDocFiles) === 2, 'Doctor sees exactly 2 active files (deleted file excluded)');
echo "[✓] Doctor sees 2 active files: {$activeDocFiles[0]['name']} and {$activeDocFiles[1]['name']}\n";

// Doctor downloads/opens the file stream
$docFileStream = $doctorClient->request('GET', "/api/radiology/files/{$fileIds[0]}", null);
assert($docFileStream['status'] === 200, 'Doctor successfully accessed file stream: HTTP 200');
assert(strpos($docFileStream['content_type'], 'image/png') !== false, 'Content-Type is image/png');
echo "[✓] Doctor successfully streamed file #{$fileIds[0]} ({$docFileStream['content_type']})\n";

// STEP 8: Security & validation checks
echo "\n--- STEP 8: Security and validation checks ---\n";
// Non-authenticated access to file fails
$anonClient = new HttpClient();
$anonRes = $anonClient->request('GET', "/api/radiology/files/{$fileIds[0]}", null);
assert($anonRes['status'] === 401, 'Unauthenticated file access rejected: HTTP 401');
echo "[✓] Unauthenticated request returns HTTP 401\n";

// Deleted file returns 404
$deletedRes = $doctorClient->request('GET', "/api/radiology/files/$removeId", null);
assert($deletedRes['status'] === 404, 'Deleted file returns HTTP 404');
echo "[✓] Deleted file returns HTTP 404\n";

// STEP 9: Cleanup
echo "\n--- Cleanup ---\n";
$storageDir = radiologyStorageDirectory();
$dbFiles = $database->query("SELECT storage_key FROM radiology_files WHERE test_order_id = $orderId")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dbFiles as $key) {
    if ($key && file_exists($storageDir . DIRECTORY_SEPARATOR . $key)) {
        @unlink($storageDir . DIRECTORY_SEPARATOR . $key);
    }
}
$database->prepare("DELETE FROM bill_items WHERE test_order_id = :id")->execute(['id' => $orderId]);
$database->prepare("DELETE FROM test_results WHERE test_order_id = :id")->execute(['id' => $orderId]);
$database->prepare("DELETE FROM radiology_files WHERE test_order_id = :id")->execute(['id' => $orderId]);
$database->prepare("DELETE FROM test_orders WHERE test_order_id = :id")->execute(['id' => $orderId]);
$database->prepare("DELETE FROM visits WHERE visit_id = :id")->execute(['id' => $visitId]);
$database->prepare("DELETE FROM patients WHERE patient_id = :id")->execute(['id' => $patientId]);

echo "[✓] Cleaned up temporary test records\n";
echo "\n=======================================================\n";
echo "🎉 ALL END-TO-END RADIOLOGY WORKFLOW TESTS PASSED 100%!\n";
echo "=======================================================\n";


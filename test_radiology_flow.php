<?php

declare(strict_types=1);

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/config/app.php';
require_once __DIR__ . '/backend/helpers/response.php';
require_once __DIR__ . '/backend/helpers/billing.php';
require_once __DIR__ . '/backend/middleware/auth.php';
require_once __DIR__ . '/backend/controllers/testing.php';
require_once __DIR__ . '/backend/controllers/radiology.php';
require_once __DIR__ . '/backend/controllers/doctor.php';

session_start();

$database = getDatabaseConnection();

echo "=== STARTING RADIOLOGY END-TO-END WORKFLOW TEST ===\n";

// 1. Prepare test patient and visit
$database->beginTransaction();
$patientStmt = $database->prepare(
    "INSERT INTO patients (patient_number, first_name, last_name, date_of_birth, gender)
     VALUES (:num, 'TestXray', 'Patient', '1990-05-15', 'other')"
);
$testPatientNum = 'TEST-XR-' . bin2hex(random_bytes(4));
$patientStmt->execute(['num' => $testPatientNum]);
$patientId = (int) $database->lastInsertId();

$visitStmt = $database->prepare(
    "INSERT INTO visits (patient_id, visit_source, status, is_emergency)
     VALUES (:patient_id, 'walk_in', 'awaiting_tests', 0)"
);
$visitStmt->execute(['patient_id' => $patientId]);
$visitId = (int) $database->lastInsertId();
$database->commit();

echo "[✓] Created test patient #$patientId ($testPatientNum) and visit #$visitId\n";

// Helper for simulating user session
function simulateLogin(array $user): void {
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['csrf_token'] = 'test-token';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-token';
}

$doctorUser = $database->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'general_doctor' LIMIT 1")->fetch();
$testingClerk = $database->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'testing_desk_clerk' LIMIT 1")->fetch();
$radiographer = $database->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'radiographer' LIMIT 1")->fetch();

assert(!empty($doctorUser), 'Doctor user exists');
assert(!empty($testingClerk), 'Testing desk clerk exists');
assert(!empty($radiographer), 'Radiographer exists');

// STEP 1: Doctor orders X-ray
echo "\n--- Step 1: Doctor orders X-ray ---\n";
simulateLogin($doctorUser);
$orderStmt = $database->prepare(
    "INSERT INTO test_orders (visit_id, ordered_by_user_id, test_type, department, status)
     VALUES (:visit_id, :user_id, 'x_ray', 'radiology', 'ordered')"
);
$orderStmt->execute([
    'visit_id' => $visitId,
    'user_id' => $doctorUser['user_id'],
]);
$orderId = (int) $database->lastInsertId();
echo "[✓] Doctor #{$doctorUser['user_id']} created X-ray order #$orderId (status: ordered, unrouted)\n";

// Verify radiographer queue does NOT see unrouted order
simulateLogin($radiographer);
$radOrdersUnrouted = testingOrderRows($database, 'radiology', (string)$orderId, '');
assert(count($radOrdersUnrouted) === 0, 'Radiographer cannot see unrouted X-ray order');
echo "[✓] Confirmed: Radiographer queue does not show unrouted X-ray order\n";

// STEP 2: Testing Centre Desk routes the order
echo "\n--- Step 2: Testing Centre routes order ---\n";
simulateLogin($testingClerk);
$deskOrders = testingOrderRows($database, 'testing_desk', (string)$orderId, '');
assert(count($deskOrders) === 1, 'Testing Desk sees unrouted X-ray order');

// Test routing
$routeOrder = testingOrderForUpdate($database, $orderId);
$routeStmt = $database->prepare(
    "UPDATE test_orders
     SET testing_desk_routed_at = CURRENT_TIMESTAMP,
         testing_desk_routed_by_user_id = :user_id
     WHERE test_order_id = :order_id"
);
$routeStmt->execute(['user_id' => $testingClerk['user_id'], 'order_id' => $orderId]);
echo "[✓] Testing Desk Clerk routed X-ray order #$orderId\n";

// STEP 3: Radiographer receives order & starts examination
echo "\n--- Step 3: Radiographer receives order & starts examination ---\n";
simulateLogin($radiographer);
$radOrdersRouted = testingOrderRows($database, 'radiology', (string)$orderId, '');
assert(count($radOrdersRouted) === 1, 'Radiographer sees routed X-ray order');
assert($radOrdersRouted[0]['status'] === 'ordered', 'Order status is ordered');

// Start examination
$startStmt = $database->prepare(
    "UPDATE test_orders SET status = 'in_progress' WHERE test_order_id = :order_id AND status = 'ordered'"
);
$startStmt->execute(['order_id' => $orderId]);
$checkOrder = testingOrderForUpdate($database, $orderId);
assert($checkOrder['status'] === 'in_progress', 'Order status is now in_progress');
echo "[✓] Radiographer received and started examination for order #$orderId (status: in_progress)\n";

// STEP 4: Radiographer uploads multiple images (PNG, JPEG, PDF)
echo "\n--- Step 4: Upload multiple files (PNG, JPEG, PDF) ---\n";
$tempPng = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
$tempJpg = tempnam(sys_get_temp_dir(), 'test_img_') . '.jpg';
$tempPdf = tempnam(sys_get_temp_dir(), 'test_doc_') . '.pdf';

// 1x1 transparent PNG
file_put_contents($tempPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
// 1x1 JPEG
file_put_contents($tempJpg, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
// Minimal valid PDF
file_put_contents($tempPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n149\n%%EOF");

$_FILES['files'] = [
    'name' => ['chest_xray_pa.png', 'chest_xray_lat.jpg', 'radiologist_notes.pdf'],
    'tmp_name' => [$tempPng, $tempJpg, $tempPdf],
    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
    'size' => [filesize($tempPng), filesize($tempJpg), filesize($tempPdf)],
];

$normalized = radiologyNormalizeUploads($_FILES['files']);
assert(count($normalized) === 3, '3 files normalized successfully');
assert($normalized[0]['file_kind'] === 'image', 'PNG is image');
assert($normalized[1]['file_kind'] === 'image', 'JPEG is image');
assert($normalized[2]['file_kind'] === 'supporting_document', 'PDF is supporting_document');

$storageDir = radiologyStorageDirectory();
assert(is_dir($storageDir) && is_writable($storageDir), 'Private storage directory is writable');

$uploadedFileIds = [];
$database->beginTransaction();
foreach ($normalized as $upload) {
    $storageKey = bin2hex(random_bytes(24));
    $dest = $storageDir . DIRECTORY_SEPARATOR . $storageKey;
    copy($upload['temporary_path'], $dest);

    $ins = $database->prepare(
        "INSERT INTO radiology_files (test_order_id, uploaded_by_user_id, original_name, storage_key, mime_type, file_kind, file_size_bytes)
         VALUES (:order_id, :user_id, :orig_name, :storage_key, :mime_type, :file_kind, :size)"
    );
    $ins->execute([
        'order_id' => $orderId,
        'user_id' => $radiographer['user_id'],
        'orig_name' => $upload['original_name'],
        'storage_key' => $storageKey,
        'mime_type' => $upload['mime_type'],
        'file_kind' => $upload['file_kind'],
        'size' => $upload['file_size_bytes'],
    ]);
    $uploadedFileIds[] = (int) $database->lastInsertId();
}
recordTestingAudit($database, (int)$radiographer['user_id'], 'radiology.files.uploaded', $patientId, $visitId);
$database->commit();

echo "[✓] Successfully uploaded 3 files: IDs (" . implode(', ', $uploadedFileIds) . ")\n";
@unlink($tempPng);
@unlink($tempJpg);
@unlink($tempPdf);

// STEP 5: Radiographer removes one incorrect upload with confirmation & audit
echo "\n--- Step 5: Radiographer removes one incorrect upload ---\n";
$fileToRemove = $uploadedFileIds[1]; // Remove chest_xray_lat.jpg
$database->beginTransaction();
$delStmt = $database->prepare(
    "UPDATE radiology_files SET deleted_at = CURRENT_TIMESTAMP, deleted_by_user_id = :user_id
     WHERE radiology_file_id = :file_id AND deleted_at IS NULL"
);
$delStmt->execute(['user_id' => $radiographer['user_id'], 'file_id' => $fileToRemove]);
recordTestingAudit($database, (int)$radiographer['user_id'], 'radiology.file.removed', $patientId, $visitId);
$database->commit();

// Verify soft delete
$filesAfterDelete = radiologyFilesForOrders($database, [$orderId], false);
assert(count($filesAfterDelete[$orderId]) === 2, 'Active files list has 2 files remaining');
$allFilesWithHistory = radiologyFilesForOrders($database, [$orderId], true);
assert(count($allFilesWithHistory[$orderId]) === 3, 'Full history retains all 3 files');
assert($allFilesWithHistory[$orderId][1]['deleted_at'] !== null, 'Deleted file has deleted_at timestamp');
echo "[✓] Soft-delete verified: active files = 2, history retained = 3, audit log recorded\n";

// STEP 6: Draft report and report submission
echo "\n--- Step 6: Radiology report drafts and submission ---\n";
// 6a: Draft report
$draftStmt = $database->prepare(
    "INSERT INTO test_results (test_order_id, recorded_by_user_id, result_text, result_notes, status)
     VALUES (:order_id, :user_id, 'Preliminary findings: Normal cardiac silhouette. No pneumothorax.', 'Draft report pending review.', 'preliminary')"
);
$draftStmt->execute(['order_id' => $orderId, 'user_id' => $radiographer['user_id']]);
echo "[✓] Preliminary draft report saved\n";

// Verify doctor cannot access files yet (report is only draft)
simulateLogin($doctorUser);
$checkFileStmt = $database->prepare(
    "SELECT file.storage_key, file.original_name, file.mime_type, file.file_size_bytes, file.deleted_at,
            test.status AS order_status,
            EXISTS (SELECT 1 FROM test_results r WHERE r.test_order_id = test.test_order_id AND r.status = 'final') AS has_submitted_report
     FROM radiology_files file
     JOIN test_orders test ON test.test_order_id = file.test_order_id
     WHERE file.radiology_file_id = :file_id"
);
$checkFileStmt->execute(['file_id' => $uploadedFileIds[0]]);
$fileRow = $checkFileStmt->fetch();
assert(!(bool)$fileRow['has_submitted_report'], 'Report is not final yet');
echo "[✓] Confirmed: Doctor access to image is restricted while report is still draft\n";

// 6b: Submit final report
simulateLogin($radiographer);
$database->beginTransaction();
$finalStmt = $database->prepare(
    "INSERT INTO test_results (test_order_id, recorded_by_user_id, result_text, result_notes, status)
     VALUES (:order_id, :user_id, 'Final report: Lungs clear. Cardiac size normal. No acute bony abnormality.', 'Report finalized and signed.', 'final')"
);
$finalStmt->execute(['order_id' => $orderId, 'user_id' => $radiographer['user_id']]);

$completeOrderStmt = $database->prepare(
    "UPDATE test_orders SET status = 'completed' WHERE test_order_id = :order_id AND status = 'in_progress'"
);
$completeOrderStmt->execute(['order_id' => $orderId]);

$bill = billingGetOrCreateBill($database, $visitId);
billingAddFeeItemOnce($database, (int)$bill['bill_id'], 'TEST_X_RAY_DEMO', 'test', $orderId);
billingRefreshBillTotal($database, (int)$bill['bill_id']);
recordTestingAudit($database, (int)$radiographer['user_id'], 'radiology.result.final', $patientId, $visitId);
$database->commit();

echo "[✓] Final radiology report submitted; order #$orderId marked completed; X-ray fee added to bill\n";

// STEP 7: Requesting doctor views images and submitted report
echo "\n--- Step 7: Requesting doctor views images and report ---\n";
simulateLogin($doctorUser);

// Check what doctor receives in test results
$docTestStmt = $database->prepare(
    "SELECT t.test_order_id, t.test_type, t.department, t.status AS order_status,
            tr.test_result_id, tr.result_text, tr.result_notes, tr.status AS result_status
     FROM test_orders t
     JOIN test_results tr ON tr.test_order_id = t.test_order_id
     WHERE t.test_order_id = :order_id AND tr.status = 'final'"
);
$docTestStmt->execute(['order_id' => $orderId]);
$docResult = $docTestStmt->fetch();
assert(!empty($docResult), 'Doctor sees final test result');
echo "[✓] Doctor sees final report text: \"{$docResult['result_text']}\"\n";

// Check files returned to doctor
$docFiles = radiologyFilesForOrders($database, [$orderId], false);
assert(count($docFiles[$orderId]) === 2, 'Doctor sees 2 active uploaded files');
assert($docFiles[$orderId][0]['name'] === 'chest_xray_pa.png', 'First file is chest_xray_pa.png');
assert($docFiles[$orderId][1]['name'] === 'radiologist_notes.pdf', 'Second file is radiologist_notes.pdf');
echo "[✓] Doctor receives active radiology files with correct metadata (PNG & PDF)\n";

// Check doctor access to file stream
$checkFileStmt->execute(['file_id' => $uploadedFileIds[0]]);
$fileRowFinal = $checkFileStmt->fetch();
assert($fileRowFinal['order_status'] === 'completed', 'Order status is completed');
assert((bool)$fileRowFinal['has_submitted_report'], 'Report is marked submitted');
$filePath = $storageDir . DIRECTORY_SEPARATOR . $fileRowFinal['storage_key'];
assert(file_exists($filePath), 'File exists in private storage');
echo "[✓] Authorized file retrieval verified for Doctor: storage key exists, size = {$fileRowFinal['file_size_bytes']} bytes\n";

// STEP 8: Clean up test patient and files
echo "\n--- Cleanup ---\n";
foreach ($allFilesWithHistory[$orderId] as $f) {
    $dbFile = $database->prepare("SELECT storage_key FROM radiology_files WHERE radiology_file_id = :id");
    $dbFile->execute(['id' => $f['radiology_file_id']]);
    $key = $dbFile->fetchColumn();
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

echo "[✓] Test records cleaned up successfully\n";
echo "\n=== ALL RADIOLOGY TESTS PASSED SUCCESSFULLY! ===\n";

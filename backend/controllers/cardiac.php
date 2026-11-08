<?php

declare(strict_types=1);

function cardiacStorageDirectory(): string
{
    $configuredPath = getenv('CARDIAC_STORAGE_PATH');
    $directory = is_string($configuredPath) && trim($configuredPath) !== ''
        ? trim($configuredPath)
        : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'hospital-system-private' . DIRECTORY_SEPARATOR . 'cardiac';

    if (!is_dir($directory)
        && !mkdir($directory, 0700, true)
        && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private cardiac storage directory.');
    }

    $realDirectory = realpath($directory);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($realDirectory === false || !is_writable($realDirectory)) {
        throw new RuntimeException('The private cardiac storage directory is unavailable.');
    }
    if ($documentRoot !== false) {
        $normalizedDirectory = rtrim(
            strtolower(str_replace('\\', '/', $realDirectory)),
            '/'
        );
        $normalizedDocumentRoot = rtrim(
            strtolower(str_replace('\\', '/', $documentRoot)),
            '/'
        );
        if ($normalizedDirectory === $normalizedDocumentRoot
            || strpos($normalizedDirectory, $normalizedDocumentRoot . '/') === 0) {
            throw new RuntimeException('ECG files must be stored outside the public document root.');
        }
    }

    return $realDirectory;
}

function cardiacFilesForOrders(
    PDO $database,
    array $orderIds,
    bool $includeDeleted = false
): array {
    if ($orderIds === []) {
        return [];
    }

    $placeholders = [];
    $parameters = [];
    foreach (array_values($orderIds) as $index => $orderId) {
        $placeholder = 'cardiac_file_order_' . $index;
        $placeholders[] = ':' . $placeholder;
        $parameters[$placeholder] = (int) $orderId;
    }
    $deletedFilter = $includeDeleted ? '' : ' AND file.deleted_at IS NULL';
    $statement = $database->prepare(
        'SELECT file.cardiac_file_id, file.test_order_id,
                file.original_name, file.mime_type, file.file_kind,
                file.file_size_bytes, file.uploaded_at, file.deleted_at,
                uploader.display_name AS uploaded_by,
                deleter.display_name AS deleted_by
         FROM cardiac_files file
         INNER JOIN users uploader
            ON uploader.user_id = file.uploaded_by_user_id
         LEFT JOIN users deleter
            ON deleter.user_id = file.deleted_by_user_id
         WHERE file.test_order_id IN (' . implode(', ', $placeholders) . ')'
            . $deletedFilter .
        ' ORDER BY file.uploaded_at, file.cardiac_file_id'
    );
    $statement->execute($parameters);

    $files = [];
    foreach ($statement->fetchAll() as $row) {
        $orderId = (int) $row['test_order_id'];
        $files[$orderId][] = [
            'cardiac_file_id' => (int) $row['cardiac_file_id'],
            'name' => $row['original_name'],
            'mime_type' => $row['mime_type'],
            'kind' => $row['file_kind'],
            'size_bytes' => (int) $row['file_size_bytes'],
            'uploaded_at' => $row['uploaded_at'],
            'uploaded_by' => $row['uploaded_by'],
            'deleted_at' => $row['deleted_at'],
            'deleted_by' => $row['deleted_by'],
        ];
    }

    return $files;
}

function cardiacExaminationAttemptsForOrders(PDO $database, array $orderIds): array
{
    if ($orderIds === []) {
        return [];
    }

    $placeholders = [];
    $parameters = [];
    foreach (array_values($orderIds) as $index => $orderId) {
        $placeholder = 'cardiac_attempt_order_' . $index;
        $placeholders[] = ':' . $placeholder;
        $parameters[$placeholder] = (int) $orderId;
    }
    $statement = $database->prepare(
        'SELECT attempt.cardiac_examination_attempt_id, attempt.test_order_id,
                attempt.status, attempt.started_at, attempt.completed_at,
                attempt.failure_reason, technician.display_name AS technician
         FROM cardiac_examination_attempts attempt
         INNER JOIN users technician
            ON technician.user_id = attempt.technician_user_id
         WHERE attempt.test_order_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY attempt.test_order_id, attempt.cardiac_examination_attempt_id'
    );
    $statement->execute($parameters);

    $attempts = [];
    foreach ($statement->fetchAll() as $row) {
        $orderId = (int) $row['test_order_id'];
        $attempts[$orderId][] = [
            'attempt_id' => (int) $row['cardiac_examination_attempt_id'],
            'status' => $row['status'],
            'started_at' => $row['started_at'],
            'completed_at' => $row['completed_at'],
            'failure_reason' => $row['failure_reason'],
            'technician' => $row['technician'],
        ];
    }

    return $attempts;
}

function cardiacLatestAttemptForUpdate(PDO $database, int $orderId): array|false
{
    $statement = $database->prepare(
        'SELECT cardiac_examination_attempt_id, status
         FROM cardiac_examination_attempts
         WHERE test_order_id = :order_id
         ORDER BY cardiac_examination_attempt_id DESC
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['order_id' => $orderId]);

    return $statement->fetch();
}

function cardiacCompleteLatestAttempt(PDO $database, int $orderId, int $userId): void
{
    $attempt = cardiacLatestAttemptForUpdate($database, $orderId);
    if ($attempt === false) {
        return;
    }
    if ($attempt['status'] !== 'in_progress') {
        jsonError('Start a new ECG examination attempt before submitting its report.', [], 409);
    }

    $statement = $database->prepare(
        'UPDATE cardiac_examination_attempts
         SET status = \'completed\', completed_at = CURRENT_TIMESTAMP
         WHERE cardiac_examination_attempt_id = :attempt_id
           AND status = \'in_progress\' AND technician_user_id = :user_id'
    );
    $statement->execute([
        'attempt_id' => (int) $attempt['cardiac_examination_attempt_id'],
        'user_id' => $userId,
    ]);
    if ($statement->rowCount() !== 1) {
        jsonError('The ECG examination attempt changed before report submission.', [], 409);
    }
}

function cardiacOrderForFileUpdate(PDO $database, int $orderId): array
{
    $order = testingOrderForUpdate($database, $orderId);
    if ($order['department'] !== 'cardiac' || $order['test_type'] !== 'ecg') {
        jsonError('Files can only be attached to a cardiac ECG order.', [], 403);
    }
    if ($order['testing_desk_routed_at'] === null) {
        jsonError('The Testing Centre must route this order before ECG files are managed.', [], 409);
    }
    if (!in_array($order['status'], ['in_progress', 'completed'], true)) {
        jsonError('Start the ECG examination before managing its files.', [], 409);
    }

    return $order;
}

function cardiacNormalizeUploads(array $uploadedFiles): array
{
    $names = $uploadedFiles['name'] ?? null;
    $temporaryPaths = $uploadedFiles['tmp_name'] ?? null;
    $errors = $uploadedFiles['error'] ?? null;
    $sizes = $uploadedFiles['size'] ?? null;
    if (!is_array($names) || !is_array($temporaryPaths)
        || !is_array($errors) || !is_array($sizes)) {
        jsonError('Choose at least one ECG tracing or supporting document.', [
            'files' => 'Select one or more JPG, JPEG, PNG, or PDF files.',
        ], 422);
    }
    if (count($names) < 1 || count($names) > 10
        || count($temporaryPaths) !== count($names)
        || count($errors) !== count($names)
        || count($sizes) !== count($names)) {
        jsonError('Upload between 1 and 10 files at a time.', [
            'files' => 'Choose up to 10 files per upload.',
        ], 422);
    }

    $allowedTypes = [
        'jpg' => ['image/jpeg', 'image'],
        'jpeg' => ['image/jpeg', 'image'],
        'png' => ['image/png', 'image'],
        'pdf' => ['application/pdf', 'supporting_document'],
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $uploads = [];
    $totalBytes = 0;
    foreach ($names as $index => $originalName) {
        if (!is_string($originalName)
            || !is_string($temporaryPaths[$index] ?? null)
            || !is_int($errors[$index] ?? null)
            || !is_int($sizes[$index] ?? null)) {
            jsonError('The uploaded file information is invalid.', [], 422);
        }
        if ($errors[$index] !== UPLOAD_ERR_OK) {
            jsonError('One or more files could not be uploaded.', [
                'files' => $errors[$index] === UPLOAD_ERR_INI_SIZE
                    || $errors[$index] === UPLOAD_ERR_FORM_SIZE
                    ? 'A file exceeds the server upload limit.'
                    : 'Choose the files again and retry the upload.',
            ], 422);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset($allowedTypes[$extension])) {
            jsonError('Unsupported ECG file type.', [
                'files' => 'Only JPG, JPEG, PNG, and PDF files are supported. DICOM is not configured.',
            ], 422);
        }
        $size = $sizes[$index];
        if ($size < 1 || $size > 15 * 1024 * 1024) {
            jsonError('An ECG file must be no larger than 15 MB.', [], 413);
        }
        $totalBytes += $size;
        if ($totalBytes > 40 * 1024 * 1024) {
            jsonError('The combined upload must be no larger than 40 MB.', [], 413);
        }
        $temporaryPath = $temporaryPaths[$index];
        if (!is_uploaded_file($temporaryPath)) {
            jsonError('The uploaded file could not be verified.', [], 422);
        }
        $mimeType = $finfo->file($temporaryPath);
        if (!is_string($mimeType) || $mimeType !== $allowedTypes[$extension][0]) {
            jsonError('The file contents do not match the supported file extension.', [], 422);
        }
        if ($allowedTypes[$extension][1] === 'image') {
            $imageDetails = getimagesize($temporaryPath);
            if (!is_array($imageDetails)
                || ($imageDetails['mime'] ?? null) !== $mimeType) {
                jsonError('The uploaded image is invalid or damaged.', [], 422);
            }
        } else {
            $pdfHeader = file_get_contents($temporaryPath, false, null, 0, 1024);
            if (!is_string($pdfHeader) || strpos($pdfHeader, '%PDF-') === false) {
                jsonError('The uploaded PDF is invalid or damaged.', [], 422);
            }
        }

        $uploads[] = [
            'temporary_path' => $temporaryPath,
            'original_name' => substr(basename($originalName), 0, 255),
            'mime_type' => $mimeType,
            'file_kind' => $allowedTypes[$extension][1],
            'file_size_bytes' => $size,
        ];
    }

    return $uploads;
}

function handleCardiacExaminationFailure(string $orderId): void
{
    if (!ctype_digit($orderId) || (int) $orderId < 1) {
        jsonError('A valid ECG order ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['cardiac_technician']);
    requireCsrfRequest();
    $input = readJsonRequest();
    $reason = $input['reason'] ?? null;
    if (!is_string($reason) || trim($reason) === '' || strlen(trim($reason)) > 10000) {
        jsonError('Enter why the ECG examination could not be completed.', [
            'reason' => 'A reason of up to 10000 characters is required.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $order = testingOrderForUpdate($database, (int) $orderId);
        if ($order['department'] !== 'cardiac' || $order['test_type'] !== 'ecg') {
            $database->rollBack();
            jsonError('This is not a cardiac ECG order.', [], 403);
        }
        if ($order['status'] !== 'in_progress') {
            $database->rollBack();
            jsonError('Only an in-progress ECG examination can be flagged unsuccessful.', [], 409);
        }
        $attempt = cardiacLatestAttemptForUpdate($database, (int) $orderId);
        if ($attempt === false || $attempt['status'] !== 'in_progress') {
            $database->rollBack();
            jsonError('Start an ECG examination attempt before recording an unsuccessful examination.', [], 409);
        }
        if ((int) $order['patient_id'] < 1) {
            $database->rollBack();
            jsonError('The patient record for this ECG order is unavailable.', [], 409);
        }

        $update = $database->prepare(
            'UPDATE cardiac_examination_attempts
             SET status = \'unsuccessful\', completed_at = CURRENT_TIMESTAMP,
                 failure_reason = :reason
             WHERE cardiac_examination_attempt_id = :attempt_id
               AND status = \'in_progress\' AND technician_user_id = :user_id'
        );
        $update->execute([
            'reason' => trim($reason),
            'attempt_id' => (int) $attempt['cardiac_examination_attempt_id'],
            'user_id' => (int) $user['user_id'],
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This ECG examination attempt changed. Refresh and try again.', [], 409);
        }
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            'cardiac.examination.unsuccessful',
            (int) $order['patient_id'],
            (int) $order['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Cardiac examination update failed: ' . $exception->getMessage());
        jsonError('Unable to update this ECG examination right now.', [], 500);
    }

    jsonSuccess('Unsuccessful ECG examination recorded. The order remains open for another attempt.', [
        'test_order_id' => (int) $orderId,
        'status' => 'unsuccessful',
    ]);
}

function handleCardiacFilesUpload(string $orderId): void
{
    if (!ctype_digit($orderId) || (int) $orderId < 1) {
        jsonError('A valid ECG order ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['cardiac_technician']);
    requireCsrfRequest();
    $uploads = cardiacNormalizeUploads($_FILES['files'] ?? []);

    $storedPaths = [];
    $createdFiles = [];
    try {
        $storageDirectory = cardiacStorageDirectory();
        $database->beginTransaction();
        $order = cardiacOrderForFileUpdate($database, (int) $orderId);
        foreach ($uploads as $upload) {
            $reservation = false;
            $storageKey = '';
            $destination = '';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $storageKey = bin2hex(random_bytes(24));
                $destination = $storageDirectory . DIRECTORY_SEPARATOR . $storageKey;
                $reservation = @fopen($destination, 'x+b');
                if (is_resource($reservation)) {
                    break;
                }
            }
            if (!is_resource($reservation)) {
                throw new RuntimeException('Unable to reserve a unique ECG storage key.');
            }
            fclose($reservation);
            $storedPaths[] = $destination;
            if (!move_uploaded_file($upload['temporary_path'], $destination)) {
                throw new RuntimeException('Unable to move an ECG file to private storage.');
            }

            $insert = $database->prepare(
                'INSERT INTO cardiac_files
                    (test_order_id, uploaded_by_user_id, original_name,
                     storage_key, mime_type, file_kind, file_size_bytes)
                 VALUES
                    (:order_id, :user_id, :original_name, :storage_key,
                     :mime_type, :file_kind, :file_size_bytes)'
            );
            $insert->execute([
                'order_id' => (int) $orderId,
                'user_id' => (int) $user['user_id'],
                'original_name' => $upload['original_name'],
                'storage_key' => $storageKey,
                'mime_type' => $upload['mime_type'],
                'file_kind' => $upload['file_kind'],
                'file_size_bytes' => $upload['file_size_bytes'],
            ]);
            $createdFiles[] = [
                'cardiac_file_id' => (int) $database->lastInsertId(),
                'name' => $upload['original_name'],
                'mime_type' => $upload['mime_type'],
                'kind' => $upload['file_kind'],
            ];
        }

        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            'cardiac.files.uploaded',
            (int) $order['patient_id'],
            (int) $order['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        foreach ($storedPaths as $storedPath) {
            if (is_file($storedPath) && !unlink($storedPath)) {
                error_log('Unable to remove an unrecorded ECG upload.');
            }
        }
        error_log('Cardiac file upload failed: ' . $exception->getMessage());
        jsonError('Unable to save ECG files right now.', [], 500);
    }

    jsonSuccess('ECG files uploaded.', [
        'test_order_id' => (int) $orderId,
        'files' => $createdFiles,
    ], 201);
}

function handleCardiacFileDelete(string $fileId): void
{
    if (!ctype_digit($fileId) || (int) $fileId < 1) {
        jsonError('A valid ECG file ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['cardiac_technician']);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $statement = $database->prepare(
            'SELECT file.test_order_id, file.deleted_at, visit.patient_id,
                    visit.visit_id, test.department, test.test_type
             FROM cardiac_files file
             INNER JOIN test_orders test
                ON test.test_order_id = file.test_order_id
             INNER JOIN visits visit ON visit.visit_id = test.visit_id
             WHERE file.cardiac_file_id = :file_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['file_id' => (int) $fileId]);
        $file = $statement->fetch();
        if ($file === false) {
            $database->rollBack();
            jsonError('ECG file not found.', [], 404);
        }
        if ($file['department'] !== 'cardiac' || $file['test_type'] !== 'ecg') {
            $database->rollBack();
            jsonError('This file is not attached to an ECG order.', [], 403);
        }
        if ($file['deleted_at'] !== null) {
            $database->rollBack();
            jsonError('This ECG file was already removed from the active view.', [], 409);
        }

        $update = $database->prepare(
            'UPDATE cardiac_files
             SET deleted_at = CURRENT_TIMESTAMP, deleted_by_user_id = :user_id
             WHERE cardiac_file_id = :file_id AND deleted_at IS NULL'
        );
        $update->execute([
            'user_id' => (int) $user['user_id'],
            'file_id' => (int) $fileId,
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This ECG file was changed by another user.', [], 409);
        }
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            'cardiac.file.removed',
            (int) $file['patient_id'],
            (int) $file['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Cardiac file removal failed: ' . $exception->getMessage());
        jsonError('Unable to remove the ECG file right now.', [], 500);
    }

    jsonSuccess('ECG file removed from the active view. Its history is retained.', [
        'cardiac_file_id' => (int) $fileId,
        'removed' => true,
    ]);
}

function handleCardiacFileGet(string $fileId): void
{
    if (!ctype_digit($fileId) || (int) $fileId < 1) {
        jsonError('A valid ECG file ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }
    $role = $user['role_name'];
    if (!in_array($role, [
        'cardiac_technician',
        'general_doctor',
        'system_administrator',
    ], true)) {
        jsonError('You are not authorized to view ECG files.', [], 403);
    }

    $statement = $database->prepare(
        'SELECT file.storage_key, file.original_name, file.mime_type,
                file.file_size_bytes, file.deleted_at,
                test.test_order_id, test.status AS order_status,
                visit.visit_id, visit.patient_id,
                EXISTS (
                    SELECT 1 FROM test_results result
                    WHERE result.test_order_id = test.test_order_id
                      AND result.status = \'final\'
                ) AS has_submitted_report
         FROM cardiac_files file
         INNER JOIN test_orders test ON test.test_order_id = file.test_order_id
         INNER JOIN visits visit ON visit.visit_id = test.visit_id
         WHERE file.cardiac_file_id = :file_id
           AND test.department = \'cardiac\'
           AND test.test_type = \'ecg\'
         LIMIT 1'
    );
    $statement->execute(['file_id' => (int) $fileId]);
    $file = $statement->fetch();
    if ($file === false || $file['deleted_at'] !== null) {
        jsonError('ECG file not found.', [], 404);
    }
    if ($role === 'general_doctor'
        && ($file['order_status'] !== 'completed'
            || !(bool) $file['has_submitted_report'])) {
        jsonError('This ECG file is available to doctors after the report is submitted.', [], 403);
    }

    try {
        $storageDirectory = cardiacStorageDirectory();
    } catch (Throwable $exception) {
        error_log('Cardiac private storage is unavailable: ' . $exception->getMessage());
        jsonError('The ECG file is unavailable.', [], 500);
    }
    $storageKey = $file['storage_key'];
    if (!is_string($storageKey) || preg_match('/^[a-f0-9]{48}$/', $storageKey) !== 1) {
        error_log('Invalid cardiac storage key encountered.');
        jsonError('The ECG file is unavailable.', [], 500);
    }
    $path = $storageDirectory . DIRECTORY_SEPARATOR . $storageKey;
    if (!is_file($path) || !is_readable($path)) {
        error_log('ECG file is missing from private storage.');
        jsonError('The ECG file is unavailable.', [], 404);
    }

    recordTestingAudit(
        $database,
        (int) $user['user_id'],
        'cardiac.file.viewed',
        (int) $file['patient_id'],
        (int) $file['visit_id']
    );
    $download = ($_GET['download'] ?? '') === '1';
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']);
    $safeName = is_string($safeName) && $safeName !== '' ? $safeName : 'ecg-file';
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . (int) $file['file_size_bytes']);
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
        . '; filename="' . $safeName . '"; filename*=UTF-8\'\''
        . rawurlencode($file['original_name']));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($path);
    exit;
}

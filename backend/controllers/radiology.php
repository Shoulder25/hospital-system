<?php

declare(strict_types=1);

function radiologyStorageDirectory(): string
{
    $configuredPath = getenv('RADIOLOGY_STORAGE_PATH');
    $directory = is_string($configuredPath) && trim($configuredPath) !== ''
        ? trim($configuredPath)
        : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'hospital-system-private' . DIRECTORY_SEPARATOR . 'radiology';

    if (!is_dir($directory)
        && !mkdir($directory, 0700, true)
        && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private radiology storage directory.');
    }

    $realDirectory = realpath($directory);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($realDirectory === false || !is_writable($realDirectory)) {
        throw new RuntimeException('The private radiology storage directory is unavailable.');
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
            throw new RuntimeException('Radiology files must be stored outside the public document root.');
        }
    }

    return $realDirectory;
}

function radiologyFilesForOrders(
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
        $placeholder = 'radiology_file_order_' . $index;
        $placeholders[] = ':' . $placeholder;
        $parameters[$placeholder] = (int) $orderId;
    }
    $deletedFilter = $includeDeleted ? '' : ' AND file.deleted_at IS NULL';
    $statement = $database->prepare(
        'SELECT file.radiology_file_id, file.test_order_id,
                file.original_name, file.mime_type, file.file_kind,
                file.file_size_bytes, file.uploaded_at, file.deleted_at,
                uploader.display_name AS uploaded_by,
                deleter.display_name AS deleted_by
         FROM radiology_files file
         INNER JOIN users uploader
            ON uploader.user_id = file.uploaded_by_user_id
         LEFT JOIN users deleter
            ON deleter.user_id = file.deleted_by_user_id
         WHERE file.test_order_id IN (' . implode(', ', $placeholders) . ')'
            . $deletedFilter .
        ' ORDER BY file.uploaded_at, file.radiology_file_id'
    );
    $statement->execute($parameters);

    $files = [];
    foreach ($statement->fetchAll() as $row) {
        $orderId = (int) $row['test_order_id'];
        $files[$orderId][] = [
            'radiology_file_id' => (int) $row['radiology_file_id'],
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

function radiologyOrderForUpdate(PDO $database, int $orderId): array
{
    $statement = $database->prepare(
        'SELECT test.test_order_id, test.visit_id, test.status,
                test.testing_desk_routed_at, visit.patient_id
         FROM test_orders test
         INNER JOIN visits visit ON visit.visit_id = test.visit_id
         WHERE test.test_order_id = :order_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['order_id' => $orderId]);
    $order = $statement->fetch();
    if ($order === false) {
        jsonError('Radiology order not found.', [], 404);
    }
    if ($order['testing_desk_routed_at'] === null) {
        jsonError('The Testing Centre must route this order before radiology files are managed.', [], 409);
    }
    if (!in_array($order['status'], ['in_progress', 'completed'], true)) {
        jsonError('Receive the radiology order before managing its files.', [], 409);
    }

    return $order;
}

function radiologyNormalizeUploads(array $uploadedFiles): array
{
    $names = $uploadedFiles['name'] ?? null;
    $temporaryPaths = $uploadedFiles['tmp_name'] ?? null;
    $errors = $uploadedFiles['error'] ?? null;
    $sizes = $uploadedFiles['size'] ?? null;
    if (!is_array($names) || !is_array($temporaryPaths)
        || !is_array($errors) || !is_array($sizes)) {
        jsonError('Choose at least one radiology image or supporting document.', [
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
            jsonError('Unsupported radiology file type.', [
                'files' => 'Only JPG, JPEG, PNG, and PDF files are supported. DICOM is not configured.',
            ], 422);
        }
        $size = $sizes[$index];
        if ($size < 1 || $size > 15 * 1024 * 1024) {
            jsonError('A radiology file must be no larger than 15 MB.', [], 413);
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

        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $name = 'radiology-file.' . $extension;
        }
        $uploads[] = [
            'temporary_path' => $temporaryPath,
            'original_name' => substr($name, 0, 255),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'file_kind' => $allowedTypes[$extension][1],
            'file_size_bytes' => $size,
        ];
    }

    return $uploads;
}

function handleRadiologyFilesUpload(string $orderId): void
{
    if (!ctype_digit($orderId) || (int) $orderId < 1) {
        jsonError('A valid radiology order ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['radiographer']);
    requireCsrfRequest();
    $uploads = radiologyNormalizeUploads($_FILES['files'] ?? []);

    $storageDirectory = '';
    $storedPaths = [];
    $createdFiles = [];
    try {
        $storageDirectory = radiologyStorageDirectory();
        $database->beginTransaction();
        $order = radiologyOrderForUpdate($database, (int) $orderId);
        $departmentStatement = $database->prepare(
            'SELECT department, test_type FROM test_orders
             WHERE test_order_id = :order_id LIMIT 1'
        );
        $departmentStatement->execute(['order_id' => (int) $orderId]);
        $department = $departmentStatement->fetch();
        if ($department === false
            || $department['department'] !== 'radiology'
            || $department['test_type'] !== 'x_ray') {
            $database->rollBack();
            jsonError('Files can only be attached to a routed X-ray order.', [], 403);
        }

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
                throw new RuntimeException('Unable to reserve a unique radiology storage key.');
            }
            fclose($reservation);
            $storedPaths[] = $destination;
            if (!move_uploaded_file($upload['temporary_path'], $destination)) {
                throw new RuntimeException('Unable to move an uploaded radiology file to private storage.');
            }

            $insert = $database->prepare(
                'INSERT INTO radiology_files
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
                'radiology_file_id' => (int) $database->lastInsertId(),
                'name' => $upload['original_name'],
                'mime_type' => $upload['mime_type'],
                'kind' => $upload['file_kind'],
            ];
        }

        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            'radiology.files.uploaded',
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
                error_log('Unable to remove an unrecorded radiology upload.');
            }
        }
        error_log('Radiology file upload failed: ' . $exception->getMessage());
        jsonError('Unable to save radiology files right now.', [], 500);
    }

    jsonSuccess('Radiology files uploaded.', [
        'test_order_id' => (int) $orderId,
        'files' => $createdFiles,
    ], 201);
}

function handleRadiologyFileDelete(string $fileId): void
{
    if (!ctype_digit($fileId) || (int) $fileId < 1) {
        jsonError('A valid radiology file ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['radiographer']);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $statement = $database->prepare(
            'SELECT file.test_order_id, file.deleted_at, visit.patient_id,
                    visit.visit_id, test.department, test.test_type
             FROM radiology_files file
             INNER JOIN test_orders test
                ON test.test_order_id = file.test_order_id
             INNER JOIN visits visit ON visit.visit_id = test.visit_id
             WHERE file.radiology_file_id = :file_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['file_id' => (int) $fileId]);
        $file = $statement->fetch();
        if ($file === false) {
            $database->rollBack();
            jsonError('Radiology file not found.', [], 404);
        }
        if ($file['department'] !== 'radiology' || $file['test_type'] !== 'x_ray') {
            $database->rollBack();
            jsonError('This file is not attached to an X-ray order.', [], 403);
        }
        if ($file['deleted_at'] !== null) {
            $database->rollBack();
            jsonError('This radiology file was already removed from the active view.', [], 409);
        }

        $update = $database->prepare(
            'UPDATE radiology_files
             SET deleted_at = CURRENT_TIMESTAMP, deleted_by_user_id = :user_id
             WHERE radiology_file_id = :file_id AND deleted_at IS NULL'
        );
        $update->execute([
            'user_id' => (int) $user['user_id'],
            'file_id' => (int) $fileId,
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This radiology file was changed by another user.', [], 409);
        }
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            'radiology.file.removed',
            (int) $file['patient_id'],
            (int) $file['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Radiology file removal failed: ' . $exception->getMessage());
        jsonError('Unable to remove the radiology file right now.', [], 500);
    }

    jsonSuccess('Radiology file removed from the active view. Its history is retained.', [
        'radiology_file_id' => (int) $fileId,
        'removed' => true,
    ]);
}

function handleRadiologyFileGet(string $fileId): void
{
    if (!ctype_digit($fileId) || (int) $fileId < 1) {
        jsonError('A valid radiology file ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }
    $role = $user['role_name'];
    if (!in_array($role, [
        'radiographer',
        'general_doctor',
        'emergency_doctor',
        'resident_specialist',
        'locum_specialist',
        'system_administrator',
    ], true)) {
        jsonError('You are not authorized to view radiology files.', [], 403);
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
         FROM radiology_files file
         INNER JOIN test_orders test ON test.test_order_id = file.test_order_id
         INNER JOIN visits visit ON visit.visit_id = test.visit_id
         WHERE file.radiology_file_id = :file_id
           AND test.department = \'radiology\'
           AND test.test_type = \'x_ray\'
         LIMIT 1'
    );
    $statement->execute(['file_id' => (int) $fileId]);
    $file = $statement->fetch();
    if ($file === false || $file['deleted_at'] !== null) {
        jsonError('Radiology file not found.', [], 404);
    }
    if (!in_array($role, ['radiographer', 'system_administrator'], true)
        && ($file['order_status'] !== 'completed'
            || !(bool) $file['has_submitted_report'])) {
        jsonError('This radiology file is available to doctors after the report is submitted.', [], 403);
    }

    try {
        $storageDirectory = radiologyStorageDirectory();
    } catch (Throwable $exception) {
        error_log('Radiology private storage is unavailable: ' . $exception->getMessage());
        jsonError('The radiology file is unavailable.', [], 500);
    }
    $storageKey = $file['storage_key'];
    if (!is_string($storageKey) || preg_match('/^[a-f0-9]{48}$/', $storageKey) !== 1) {
        error_log('Invalid radiology storage key encountered.');
        jsonError('The radiology file is unavailable.', [], 500);
    }
    $path = $storageDirectory . DIRECTORY_SEPARATOR . $storageKey;
    if (!is_file($path) || !is_readable($path)) {
        error_log('Radiology file is missing from private storage.');
        jsonError('The radiology file is unavailable.', [], 404);
    }

    recordTestingAudit(
        $database,
        (int) $user['user_id'],
        'radiology.file.viewed',
        (int) $file['patient_id'],
        (int) $file['visit_id']
    );
    $download = ($_GET['download'] ?? '') === '1';
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']);
    $safeName = is_string($safeName) && $safeName !== '' ? $safeName : 'radiology-file';
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

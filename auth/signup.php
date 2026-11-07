<?php
require_once __DIR__ . '/../backend/middleware/admin_page.php';
requireAdministratorPage();

header('Location: ../frontend/admin/users.php');
exit;
?>

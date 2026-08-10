<?php
/**
 * keepalive.php — pinged by the client while the mouse/keyboard is actually
 * moving, so auth_check.php's idle-timeout tracks real user activity instead
 * of just page navigations. See pmStartKeepAlive() in layout.php.
 */
session_start();
require __DIR__ . '/auth_check.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode(['ok' => true]);

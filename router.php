<?php
/**
 * Router script for PHP's built-in web server (php -S).
 *
 * The built-in server does not read .htaccess, so without this file the raw
 * database dump, the capstone paperwork and the logs would be downloadable
 * from http://127.0.0.1:8099/sql/ayuda_db_full.sql etc. This router applies
 * exactly the same blocks .htaccess applies under Apache and then lets the
 * server handle everything else normally (PHP files are executed, CSS/JS/images
 * are sent as-is, missing files 404).
 *
 * start-ayuda.ps1 passes this file automatically:
 *     php -S 127.0.0.1:8099 -t <this folder> router.php
 */
declare(strict_types=1);

$uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = (string)parse_url($uri, PHP_URL_PATH);
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}
$decoded = rawurldecode($path);

// Rejected before anything else: encoded nulls and dot-dot traversal.
if ($decoded === '' || strpos($decoded, "\0") !== false || strpos($decoded, '..') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad request';
    exit;
}

// Same rules as .htaccess: data folders, dumps/logs/config-ish files, dotfiles.
$blockedDir  = preg_match('#^/(sql|_keep|logs)(/|$)#i', $decoded) === 1;
$blockedType = preg_match('/\.(sql|dump|bak|log|ini|env)$/i', $decoded) === 1;
$blockedDot  = preg_match('#(^|/)\.#', $decoded) === 1;

if ($blockedDir || $blockedType || $blockedDot) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

// Not blocked: hand the request back to the built-in server untouched.
return false;

<?php
/**
 * PHP built-in server router.
 * Usage: php -S localhost:8081 router.php
 *
 * - Real static files (widget.js, *.css, *.html accessed directly) are served as-is.
 * - Everything else (API routes + page routes) is dispatched to api/index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// Let PHP serve actual files on disk directly (widget.js, css, etc.)
if ($path !== '/' && file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
    return false;
}

// Everything else → API handler
require __DIR__ . '/api/index.php';

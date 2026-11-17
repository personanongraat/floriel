<?php

declare(strict_types=1);

require __DIR__ . '/../backend/bootstrap.php';

$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $id, $matches)) {
    http_response_code(404);
    exit;
}
$file = FLORIEL_DATA . '/uploads/' . $id;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
$type = ['jpg' => 'jpeg', 'png' => 'png', 'webp' => 'webp'][$matches[1]];
header('Content-Type: image/' . $type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
readfile($file);

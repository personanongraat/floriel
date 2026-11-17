<?php

declare(strict_types=1);

ini_set('display_errors', '0');
require __DIR__ . '/../backend/bootstrap.php';
require __DIR__ . '/../backend/notify.php';
require __DIR__ . '/../backend/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function jsonInput(int $limit): array
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    if ($type !== 'application/json') {
        throw new FormError('Ожидается JSON.', 415);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        throw new FormError('Размер данных формы превышает допустимый.', 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        throw new FormError('Размер данных формы превышает допустимый.', 413);
    }
    $input = json_decode($raw, true);
    if (!is_array($input) || !str_starts_with(ltrim($raw), '{')) {
        throw new FormError('Не удалось прочитать данные формы.', 400);
    }
    return $input;
}

function openEditorSession(): void
{
    session_name('floriel_editor');
    session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => PHP_SAPI !== 'cli-server',
        'cookie_lifetime' => 4 * 60 * 60,
        'gc_maxlifetime' => 4 * 60 * 60,
    ]);
}

function handleRequest(): array
{
    $action = $_GET['action'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];
    $header = $_SERVER['HTTP_X_FLORIEL_REQUEST'] ?? '';

    if ($action === 'orders' || $action === 'feedback') {
        if ($method !== 'POST') {
            header('Allow: POST');
            throw new FormError('Метод не поддерживается.', 405);
        }
        if ($header !== 'form') {
            throw new FormError('Запрос отклонён.', 403);
        }
        $input = jsonInput(256 * 1024);
        limitAttempts('forms', 30, 600);
        return notify($action, $input, config());
    }

    if ($action === 'login' && $method === 'POST') {
        if ($header !== 'editor') {
            throw new FormError('Запрос отклонён.', 403);
        }
        $settings = config();
        $password = $settings['password'] ?? (PHP_SAPI === 'cli-server' ? 'floriel-demo' : '');
        if ($password === '' || (PHP_SAPI !== 'cli-server' && $password === 'floriel-demo')) {
            throw new FormError('Вход для флориста ещё не настроен.', 503);
        }
        $input = jsonInput(4096);
        limitAttempts('login', 12, 900);
        if (!hash_equals($password, field($input, 'password', 256))) {
            throw new FormError('Пароль не подошёл.', 401);
        }

        openEditorSession();
        session_regenerate_id(true);
        $_SESSION['editor_until'] = time() + 4 * 60 * 60;
        session_write_close();
        return catalogResponse(true);
    }

    $editor = false;
    if (isset($_COOKIE['floriel_editor'])) {
        openEditorSession();
        $editor = ($_SESSION['editor_until'] ?? 0) > time();
        if ($action === 'logout' && $method === 'POST' && $header === 'editor') {
            $_SESSION = [];
        }
        session_write_close();
    }

    if ($action === 'catalog' && $method === 'GET') {
        return catalogResponse($editor);
    }
    if ($header !== 'editor') {
        throw new FormError('Запрос отклонён.', 403);
    }
    if ($action === 'logout' && $method === 'POST') {
        return catalogResponse(false);
    }
    if (!$editor) {
        throw new FormError('Войди в режим флориста.', 401);
    }

    $id = $_GET['id'] ?? null;
    if ($action === 'bouquets' && $method === 'POST' && $id === null) {
        return editCatalog($method, null, jsonInput(7 * 1024 * 1024));
    }
    if ($action === 'bouquets' && is_string($id) && preg_match('/\A[a-zA-Z0-9-]+\z/', $id)) {
        if ($method === 'PUT' || $method === 'DELETE') {
            return editCatalog($method, $id, $method === 'PUT' ? jsonInput(7 * 1024 * 1024) : []);
        }
    }
    throw new FormError('Команда не найдена.', 404);
}

try {
    $response = handleRequest();
} catch (FormError $error) {
    http_response_code($error->getCode());
    $response = ['error' => $error->getMessage()];
} catch (Throwable $error) {
    error_log('Floriel request failed: ' . $error::class . ' in ' . basename($error->getFile()) . ':' . $error->getLine());
    http_response_code(500);
    $response = ['error' => 'Не удалось выполнить запрос. Попробуйте ещё раз.'];
}
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

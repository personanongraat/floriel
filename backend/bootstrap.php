<?php

declare(strict_types=1);

const FLORIEL_ROOT = __DIR__ . '/..';
const FLORIEL_DATA = FLORIEL_ROOT . '/data';

function config(): array
{
    $file = __DIR__ . '/config.php';
    return is_file($file) ? require $file : [];
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create data directory');
    }
}

function withFileLock(string $file, int $operation, callable $action): mixed
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open lock file');
    }
    try {
        if (!flock($handle, $operation)) {
            throw new RuntimeException('Could not lock file');
        }
        return $action($handle);
    } finally {
        fclose($handle);
    }
}

function limitAttempts(string $group, int $max, int $interval): void
{
    $directory = FLORIEL_DATA . '/limits';
    ensureDirectory($directory);
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = $directory . '/' . $group . '-' . hash('sha256', $address) . '.json';
    withFileLock($file, LOCK_EX, static function ($handle) use ($max, $interval): void {
        $state = json_decode(stream_get_contents($handle), true);
        $now = time();
        if (!$state || $state['until'] <= $now) {
            $state = ['until' => $now + $interval, 'count' => 0];
        }
        if ($state['count'] >= $max) {
            header('Retry-After: ' . ($state['until'] - $now));
            throw new FormError('Слишком много попыток. Попробуйте чуть позже.', 429);
        }
        $state['count']++;
        writeReceipt($handle, $state);
    });
}

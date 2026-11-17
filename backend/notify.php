<?php

declare(strict_types=1);

final class FormError extends RuntimeException
{
}

function field(array $data, string $key, int $max, bool $required = true): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value)) {
        throw new FormError('Проверьте данные формы.', 422);
    }

    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (($required && $value === '') ||
        preg_match('/\A.{0,' . $max . '}\z/us', $value) !== 1 ||
        preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value)) {
        throw new FormError('Проверьте данные формы.', 422);
    }

    return $value;
}

function orderMessages(array $input, string $id): array
{
    $customer = $input['customer'] ?? null;
    $items = $input['items'] ?? null;
    if (!is_array($customer) || !is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 100) {
        throw new FormError('Укажите контактные данные и добавьте букет в корзину.', 422);
    }
    if (field($customer, 'website', 200, false) !== '') {
        throw new FormError('Не удалось принять данные формы.', 422);
    }

    $name = field($customer, 'name', 80);
    $phone = field($customer, 'phone', 30);
    if (!preg_match('/\A\+?[\d ()-]+\z/', $phone) || !preg_match('/\A\d{10,15}\z/', preg_replace('/\D/', '', $phone))) {
        throw new FormError('Укажите телефон с 10–15 цифрами.', 422);
    }

    $method = field($customer, 'method', 8);
    $contact = ['Имя — ' . $name, 'Телефон — ' . $phone];
    if ($method === 'delivery') {
        $address = field($customer, 'address', 250);
        $date = field($customer, 'date', 10);
        $zone = new DateTimeZone('Europe/Moscow');
        $selected = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$selected || $selected->format('Y-m-d') !== $date || $selected < new DateTimeImmutable('today', $zone)) {
            throw new FormError('Укажите дату доставки не раньше сегодняшней.', 422);
        }
        $interval = field($customer, 'interval', 80, false);
        $contact[] = 'Доставка — ' . $address;
        $contact[] = 'Дата — ' . $date . ($interval ? ' · ' . $interval : '');
    } elseif ($method === 'pickup') {
        $contact[] = 'Самовывоз';
    } else {
        throw new FormError('Выберите доставку или самовывоз.', 422);
    }

    $comment = field($customer, 'comment', 1000, false);
    $telegramItems = [];
    $emailItems = [];
    $subtotal = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new FormError('Проверьте состав заказа.', 422);
        }
        $product = field($item, 'name', 80);
        $composition = field($item, 'composition', 500, false);
        $qty = $item['qty'] ?? null;
        $price = $item['price'] ?? null;
        if (!is_int($qty) || $qty < 1 || $qty > 99 || !is_int($price) || $price < 1 || $price > 1000000) {
            throw new FormError('Проверьте количество и стоимость букетов.', 422);
        }

        $priceLine = $product . ' × ' . $qty . ' · ' . number_format($price, 0, ',', ' ') . ' ₽/шт.';
        $telegramItems[] = $priceLine;
        $emailItems[] = $priceLine . ($composition === '' ? '' : "\n  " . $composition);
        $subtotal += $qty * $price;
    }

    $subject = 'Флориэль · Новая заявка';
    $intro = $subject . "\nНомер — " . $id . "\n\n" . implode("\n", $contact) . "\n\nБукеты\n";
    $end = "\n\nСумма из корзины — " . number_format($subtotal, 0, ',', ' ') . ' ₽ без доставки. Уточните при подтверждении.';
    if ($comment !== '') {
        $end .= "\nКомментарий — " . $comment;
    }
    $telegram = implode("\n", array_slice($emailItems, 0, 12));
    $remaining = count($telegramItems) - 12;
    if ($remaining > 0) {
        $telegram .= "\nЕщё позиций — " . $remaining . '. Полный состав в письме.';
    }
    if (preg_match('/\A.{0,3400}\z/us', $intro . $telegram . $end) !== 1) {
        $telegram = implode("\n", array_slice($telegramItems, 0, 12)) . "\nПолный состав в письме.";
    }

    return [$subject, $intro . $telegram . $end, $intro . implode("\n", $emailItems) . $end];
}

function feedbackMessages(array $input, string $id, string $receipts): array
{
    if (field($input, 'website', 200, false) !== '') {
        throw new FormError('Не удалось принять данные формы.', 422);
    }
    $order = @fopen($receipts . '/orders-' . $id . '.json', 'r');
    if (!$order) {
        throw new FormError('Заказ для этого отзыва не найден.', 404);
    }
    try {
        flock($order, LOCK_SH);
        $status = json_decode(stream_get_contents($order), true);
    } finally {
        fclose($order);
    }
    if (empty($status['telegram']) || empty($status['email'])) {
        throw new FormError('Заказ для этого отзыва не найден.', 404);
    }

    $labels = ['Да, всё понятно', 'Были небольшие сложности', 'Нужна помощь'];
    $rating = field($input, 'rating', 1);
    if (!in_array($rating, ['0', '1', '2'], true)) {
        throw new FormError('Выберите оценку.', 422);
    }
    $comment = field($input, 'comment', 1000, false);
    $subject = 'Флориэль · Отзыв о заказе';
    $message = $subject . "\nНомер заказа — " . $id . "\n\nОценка — " . $labels[(int) $rating];
    if ($comment !== '') {
        $message .= "\nКомментарий — " . $comment;
    }
    return [$subject, $message, $message];
}

function sendTelegram(string $token, string $chat, string $message): bool
{
    $request = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'chat_id' => $chat,
            'text' => $message,
            'link_preview_options' => ['is_disabled' => true],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    return $status === 200 && is_string($body) && (json_decode($body, true)['ok'] ?? false) === true;
}

function sendEmail(string $to, string $from, string $subject, string $message): bool
{
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
    ];
    return @mail($to, $encodedSubject, chunk_split(base64_encode($message)), $headers);
}

function writeReceipt($file, array $state): void
{
    $content = json_encode($state, JSON_THROW_ON_ERROR);
    if (!rewind($file) || fwrite($file, $content) !== strlen($content) || !ftruncate($file, strlen($content)) || !fflush($file)) {
        throw new RuntimeException('Could not save notification receipt');
    }
}

function notify(string $kind, array $payload): array
{
    $id = field($payload, $kind === 'orders' ? 'requestId' : 'orderId', 36);
    if (!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/', $id)) {
        throw new FormError('Не удалось определить номер заявки.', 422);
    }

    $receipts = dirname(__DIR__) . '/data/form-receipts';
    [$subject, $telegram, $email] = $kind === 'orders'
        ? orderMessages($payload, $id)
        : feedbackMessages($payload, $id, $receipts);

    $token = getenv('FLORIEL_TELEGRAM_TOKEN') ?: '';
    $chat = getenv('FLORIEL_TELEGRAM_CHAT_ID') ?: '';
    $to = getenv('FLORIEL_MAIL_TO') ?: '';
    $from = getenv('FLORIEL_MAIL_FROM') ?: '';
    if (!function_exists('curl_init') || !function_exists('mail') ||
        !preg_match('/\A\d+:[A-Za-z0-9_-]+\z/', $token) || !preg_match('/\A-?\d+\z/', $chat) ||
        !filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new FormError('Отправка пока недоступна. Позвоните в салон по номеру +7 993 605 0483.', 503);
    }

    if (!is_dir($receipts) && !@mkdir($receipts, 0700, true) && !is_dir($receipts)) {
        throw new RuntimeException('Could not create notification receipts directory');
    }
    $file = fopen($receipts . '/' . $kind . '-' . $id . '.json', 'c+');
    if (!$file) {
        throw new RuntimeException('Could not open notification receipt');
    }

    try {
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            throw new FormError('Форма уже отправляется. Подождите немного и повторите попытку.', 409);
        }
        $fingerprint = hash('sha256', $email);
        $stored = stream_get_contents($file);
        $state = $stored === ''
            ? ['fingerprint' => $fingerprint, 'telegram' => false, 'email' => false]
            : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        if ($state['fingerprint'] !== $fingerprint) {
            throw new FormError('Форма уже отправлялась с другими данными.', 409);
        }
        if ($stored === '') {
            writeReceipt($file, $state);
        }

        if (!$state['telegram'] && sendTelegram($token, $chat, $telegram)) {
            $state['telegram'] = true;
            writeReceipt($file, $state);
        }
        if (!$state['email'] && sendEmail($to, $from, $subject, $email)) {
            $state['email'] = true;
            writeReceipt($file, $state);
        }
        if (!$state['telegram'] || !$state['email']) {
            error_log('Floriel ' . $kind . ' ' . $id . ' notification incomplete'
                . ' (Telegram ' . (int) $state['telegram'] . ', mail ' . (int) $state['email'] . ')');
            throw new FormError('Не удалось завершить отправку. Повторите попытку с теми же данными.', 503);
        }

        return ['id' => $id];
    } finally {
        fclose($file);
    }
}

try {
    $kind = $argv[1] ?? '';
    if (!in_array($kind, ['orders', 'feedback'], true)) {
        throw new FormError('Форма не найдена.', 404);
    }
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($payload)) {
        throw new FormError('Не удалось прочитать данные формы.', 400);
    }
    $response = ['status' => 200, 'body' => notify($kind, $payload)];
} catch (FormError $error) {
    $response = ['status' => $error->getCode(), 'body' => ['error' => $error->getMessage()]];
} catch (Throwable $error) {
    error_log('Floriel form handler failure: ' . $error::class);
    $response = ['status' => 500, 'body' => ['error' => 'Не удалось отправить форму. Попробуйте ещё раз.']];
}
echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

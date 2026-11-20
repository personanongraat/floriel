<?php

declare(strict_types=1);

function seedCatalog(): array
{
    return json_decode(file_get_contents(__DIR__ . '/catalog.seed.json'), true, flags: JSON_THROW_ON_ERROR);
}

function readCatalog(): array
{
    $file = FLORIEL_DATA . '/catalog.json';
    if (!is_file($file)) {
        return seedCatalog()['products'];
    }
    $products = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    foreach ($products as &$product) {
        if (($product['category'] ?? '') === 'Сборные') {
            $product['category'] = 'Композиции';
        }
    }
    unset($product);
    return $products;
}

function catalogResponse(bool $editor, ?array $products = null): array
{
    $products ??= readCatalog();
    if (!$editor) {
        $products = array_values(array_filter($products, static fn (array $product): bool => ($product['inCatalog'] ?? true) !== false));
    }
    return ['editor' => $editor, 'products' => $products];
}

function savePhoto(mixed $photo): string
{
    if (!is_string($photo) || !preg_match('~\Adata:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)\z~', $photo, $matches)) {
        throw new FormError('Подойдут фотографии JPEG, PNG и WebP.', 422);
    }
    $bytes = base64_decode($matches[2], true);
    if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024) {
        throw new FormError('Максимальный размер фотографии — 5 МБ.', 413);
    }

    $info = @getimagesizefromstring($bytes);
    if (($info['mime'] ?? null) !== $matches[1]) {
        throw new FormError('Не удалось прочитать фотографию.', 422);
    }
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$matches[1]];
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    $directory = FLORIEL_DATA . '/uploads';
    ensureDirectory($directory);
    if (file_put_contents($directory . '/' . $name, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not save photo');
    }
    return '/photo.php?id=' . $name;
}

function editCatalog(string $method, ?string $id, array $input): array
{
    ensureDirectory(FLORIEL_DATA);
    return withFileLock(FLORIEL_DATA . '/catalog.lock', LOCK_EX, static function () use ($method, $id, $input): array {
        $products = readCatalog();
        $index = array_search($id, array_column($products, 'id'), true);
        if ($id !== null && $index === false) {
            throw new FormError('Этот букет уже удалён.', 404);
        }

        if ($method === 'DELETE') {
            array_splice($products, $index, 1);
        } else {
            $old = $index === false ? [] : $products[$index];
            $category = field($input, 'category', 80);
            $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
            if ($price === false || !in_array($category, seedCatalog()['categories'], true)) {
                throw new FormError('Проверь цену и категорию букета.', 422);
            }
            $product = array_replace($old, [
                'id' => $id ?? bin2hex(random_bytes(16)),
                'name' => field($input, 'name', 80),
                'price' => $price,
                'category' => $category,
                'composition' => field($input, 'composition', 500),
                'inCatalog' => ($input['inCatalog'] ?? null) === true,
            ]);
            $product['image'] = array_key_exists('photo', $input)
                ? savePhoto($input['photo'])
                : ($old['image'] ?? null);
            if ($product['image'] === null) {
                throw new FormError('Добавь фотографию букета.', 422);
            }
            if (($old['category'] ?? null) !== $category) {
                unset($product['tag']);
            }
            if ($index === false) {
                $products[] = $product;
            } else {
                $products[$index] = $product;
            }
        }

        $file = FLORIEL_DATA . '/catalog.json';
        $content = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = $file . '.tmp';
        if (file_put_contents($temporary, $content) !== strlen($content) || !rename($temporary, $file)) {
            throw new RuntimeException('Could not update catalogue');
        }
        return catalogResponse(true, $products);
    });
}

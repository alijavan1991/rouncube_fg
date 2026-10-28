<?php

// Router for `php -S`: a minimal stand-in for Zemzemeh's /api/mail endpoints.
// File ids encode the scenario; every request's Authorization header is logged.

file_put_contents(getenv('STUB_LOG'), ($_SERVER['HTTP_AUTHORIZATION'] ?? '-') . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);

const V1 = '1111111111111111111111111111111111111111111111111111111111111111';
const V2 = '2222222222222222222222222222222222222222222222222222222222222222';

$meta = static fn (string $id, int $size, string $version = V1) => [
    'id' => $id, 'name' => 'report.pdf', 'content_type' => 'application/pdf', 'size' => $size,
    'created_at' => '2026-10-27T00:00:00Z', 'owner_username' => 'alice', 'my_role' => 'owner', 'version' => $version,
];
$files = [
    str_repeat('a', 32) => [$meta(str_repeat('a', 32), 11), 'hello world', V1],
    str_repeat('e', 32) => [$meta(str_repeat('e', 32), 11), 'hello world', V2],      // replaced between calls
    str_repeat('f', 32) => [$meta(str_repeat('f', 32), 5), 'hello world', V1],       // longer than announced
    str_repeat('9', 32) => [$meta(str_repeat('9', 32), 20), 'hello world', V1],      // truncated stream
    str_repeat('8', 32) => [$meta(str_repeat('8', 32), 4096), str_repeat('x', 4096), V1],
    str_repeat('7', 32) => [$meta(str_repeat('7', 32), 11), 'hello world', V1],       // access revoked after download
    str_repeat('6', 32) => [$meta(str_repeat('6', 32), 11), 'hello world', V1],       // replaced after download
];
$served = static fn (string $id) => getenv('STUB_LOG') . ".served-{$id}";
$json = static function (int $status, $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/mail/files' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save to Zemzemeh: the uploaded filename selects the outcome.
    $upload = $_FILES['upload'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
        $json(400, ['detail' => 'Missing or incomplete upload']);
        return true;
    }
    $data = file_get_contents($upload['tmp_name']);
    file_put_contents(getenv('STUB_LOG'), 'UPLOAD ' . json_encode([$upload['name'], $upload['type'], strlen($data),
        hash('sha256', $data), count($_FILES), array_keys($_POST)]) . "
", FILE_APPEND);
    $status = ['quota.pdf' => 507, 'large.pdf' => 413, 'slow.pdf' => 408, 'auth.pdf' => 401, 'off.pdf' => 404,
        'boom.pdf' => 500, 'odd.pdf' => 201][$upload['name']] ?? 201;
    $saved = ['version' => V1] + $meta(str_repeat('b', 32), strlen($data));
    $saved['name'] = $upload['name'];
    $json($status, $status !== 201 ? ['detail' => 'refused'] : ($upload['name'] === 'odd.pdf' ? ['id' => 'x'] : $saved));
    return true;
}
if ($path === '/api/mail/files') {
    $json(200, [$files[str_repeat('a', 32)][0]]);
    return true;
}
if (!preg_match('#^/api/mail/files/([0-9a-f]{32})(/content)?$#', $path, $m)) {
    $json(404, ['detail' => 'Not Found']);
    return true;
}
[$id, $content] = [$m[1], !empty($m[2])];
if ($id === str_repeat('c', 32)) {
    $json(401, ['detail' => 'Token not issued for the mail integration']);
} elseif ($id === str_repeat('d', 32)) {
    $json(500, ['detail' => 'boom']);
} elseif (!isset($files[$id]) || ($id === str_repeat('7', 32) && is_file($served($id)))) {
    $json(404, ['detail' => 'File not found']);
} elseif (!$content) {
    $json(200, $id === str_repeat('6', 32) && is_file($served($id)) ? ['version' => V2] + $files[$id][0] : $files[$id][0]);
} else {
    touch($served($id));
    [$info, $body, $version] = $files[$id];
    header('Content-Type: application/pdf');
    header('X-Zemzemeh-Version: ' . $version);
    echo $body;
}

return true;

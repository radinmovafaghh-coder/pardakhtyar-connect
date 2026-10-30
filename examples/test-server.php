<?php
/**
 * سرور تست — همان قرارداد API پرداختیار را شبیه‌سازی می‌کند
 * تا SDKها بدون تماس با پروداکشن آزموده شوند.
 */
declare(strict_types=1);

$SECRET = 'test_secret_0123456789abcdef';
$KEY    = 'ak_test000001';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input') ?: '';

// ذخیرهٔ سفارش‌ها در فایل
$DB = sys_get_temp_dir() . '/pay_connect_test.json';
$store = is_file($DB) ? json_decode(file_get_contents($DB), true) : ['orders' => [], 'links' => []];

function out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function save(array $store, string $db): void {
    file_put_contents($db, json_encode($store, JSON_UNESCAPED_UNICODE));
}

// ── عمومی ──
if (preg_match('#^/api/v1/public/status/([a-f0-9]{32})$#', $uri, $m) && $method === 'GET') {
    $link = $store['links'][$m[1]] ?? null;
    if (!$link) out(['ok' => false, 'error' => 'not_found'], 404);
    $order = null;
    if (!empty($link['order_code'])) {
        $order = $store['orders'][$link['order_code']] ?? null;
    }
    $paid = $order && $order['status'] === 'paid';
    out([
        'ok' => true,
        'paid' => $paid,
        'status' => $order['status'] ?? 'pending',
        'amount_rial' => $order['amount_rial'] ?? $link['amount_rial'],
        'order_code' => $link['order_code'] ?? null,
        'paid_at' => $paid ? '1405/08/08' : null,
    ]);
}

// ── کمکی تست: علامت‌گذاری پرداخت‌شده (بدون احراز هویت) ──
if ($uri === '/__mark_paid' && $method === 'POST') {
    $b = $raw !== '' ? json_decode($raw, true) : [];
    $code = $b['code'] ?? '';
    if (isset($store['orders'][$code])) {
        $store['orders'][$code]['status'] = 'paid';
        save($store, $DB);
        out(['ok' => true]);
    }
    out(['ok' => false], 404);
}

// ── احراز هویت ──
$keyId = $_SERVER['HTTP_X_API_KEY'] ?? '';
$sig   = $_SERVER['HTTP_X_API_SIGNATURE'] ?? '';
$ts    = $_SERVER['HTTP_X_API_TIMESTAMP'] ?? '';
$nonce = $_SERVER['HTTP_X_API_NONCE'] ?? '';

if ($keyId === '' || $sig === '' || $ts === '') {
    out(['ok' => false, 'error' => 'missing_auth', 'message' => 'هدرهای احراز هویت ناقص است.'], 401);
}
if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300) {
    out(['ok' => false, 'error' => 'timestamp_expired', 'message' => 'زمان درخواست معتبر نیست.'], 401);
}
if ($keyId !== $KEY) {
    out(['ok' => false, 'error' => 'invalid_key', 'message' => 'کلید API نامعتبر است.'], 401);
}

$expected = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $raw, $SECRET);
if (!hash_equals($expected, $sig)) {
    out(['ok' => false, 'error' => 'bad_signature', 'message' => 'امضای درخواست نامعتبر است.'], 401);
}

$body = $raw !== '' ? json_decode($raw, true) : [];

// ── ساخت سفارش ──
if ($uri === '/api/v1/orders' && $method === 'POST') {
    $amount = (int)($body['amount'] ?? 0);
    $title = trim((string)($body['title'] ?? ''));
    if ($amount < 1000) {
        out(['ok' => false, 'error' => 'invalid_amount', 'message' => 'مبلغ باید حداقل ۱۰۰۰ تومان باشد.'], 422);
    }
    if ($title === '') {
        out(['ok' => false, 'error' => 'missing_title', 'message' => 'عنوان الزامی است.'], 422);
    }

    $ext = trim((string)($body['external_ref'] ?? ''));
    if ($ext !== '') {
        foreach ($store['orders'] as $o) {
            if (($o['external_ref'] ?? '') === $ext) {
                out(['ok' => true, 'duplicate' => true, 'order' => $o]);
            }
        }
    }

    $base = $amount * 10;
    $offset = random_int(1, 999);
    $unique = $base + $offset;
    $code = 'ORD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $token = bin2hex(random_bytes(16));

    $order = [
        'code' => $code,
        'title' => $title,
        'amount_rial' => $unique,
        'base_amount_rial' => $base,
        'unique_offset_rial' => $offset,
        'status' => 'pending',
        'expires_at' => date('Y-m-d H:i:s', time() + 1800),
        'pay_url' => 'https://pay.lenvato.ir/p/' . $token,
        'external_ref' => $ext ?: null,
    ];
    $store['orders'][$code] = $order;
    $store['links'][$token] = [
        'amount_rial' => $unique,
        'order_code' => $code,
    ];
    save($store, $DB);
    out(['ok' => true, 'order' => $order], 201);
}

// ── وضعیت ──
if (preg_match('#^/api/v1/status/([A-Za-z0-9\-_]+)$#', $uri, $m) && $method === 'GET') {
    $ref = $m[1];
    $o = $store['orders'][$ref] ?? null;
    if (!$o) {
        foreach ($store['orders'] as $cand) {
            if (($cand['external_ref'] ?? '') === $ref) { $o = $cand; break; }
        }
    }
    if (!$o) out(['ok' => false, 'error' => 'not_found'], 404);
    out([
        'ok' => true,
        'status' => $o['status'],
        'paid' => $o['status'] === 'paid',
        'amount_rial' => $o['amount_rial'],
        'paid_at' => $o['status'] === 'paid' ? '1405/08/08 12:00' : null,
        'transaction' => null,
    ]);
}

// ── جزئیات سفارش ──
if (preg_match('#^/api/v1/orders/([A-Za-z0-9\-]+)$#', $uri, $m) && $method === 'GET') {
    $o = $store['orders'][$m[1]] ?? null;
    if (!$o) out(['ok' => false, 'error' => 'not_found'], 404);
    out(['ok' => true, 'order' => $o]);
}

// ── لینک ──
if ($uri === '/api/v1/links' && $method === 'POST') {
    $amount = (int)($body['amount'] ?? 0);
    $title = trim((string)($body['title'] ?? ''));
    if ($amount < 1000 || $title === '') {
        out(['ok' => false, 'error' => 'invalid_input', 'message' => 'عنوان و مبلغ الزامی است.'], 422);
    }
    $token = bin2hex(random_bytes(16));
    $link = [
        'token' => $token,
        'url' => 'https://pay.lenvato.ir/p/' . $token,
        'amount_rial' => $amount * 10,
        'max_uses' => (int)($body['max_uses'] ?? 1),
        'expires_at' => null,
    ];
    $store['links'][$token] = ['amount_rial' => $amount * 10, 'order_code' => null];
    save($store, $DB);
    out(['ok' => true, 'link' => $link], 201);
}

out(['ok' => false, 'error' => 'not_found', 'message' => 'مسیر یافت نشد.'], 404);

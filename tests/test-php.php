<?php
/** تست SDK PHP در برابر سرور تست محلی. */
declare(strict_types=1);

require __DIR__ . '/../sdk/php/Connect.php';

use Pardakhtyar\Connect;
use Pardakhtyar\ConnectException;

$BASE = getenv('TEST_BASE') ?: 'http://127.0.0.1:8977';
$pass = 0; $fail = 0;

function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra ? " — $extra" : '') . "\n"; }
}

echo "=== SDK PHP ===\n";

$pay = new Connect($BASE, 'ak_test000001', 'test_secret_0123456789abcdef');

// ۱- ساخت سفارش
$order = $pay->createOrder([
    'amount' => 250000,
    'title' => 'اشتراک ماهانه',
    'external_ref' => 'INV-1001',
    'customer_name' => 'رادین',
    'customer_mobile' => '09334844913',
]);
ok('createOrder کد سفارش دارد', isset($order['code']) && $order['code'] !== '');
ok('createOrder لینک پرداخت دارد', !empty($order['pay_url']));
ok('createOrder مبلغ یکتاست', $order['amount_rial'] > 2500000);
ok('createOrder وضعیت pending', $order['status'] === 'pending');
$code = $order['code'];

// ۲- idempotency
$dup = $pay->createOrder(['amount' => 250000, 'title' => 'اشتراک ماهانه', 'external_ref' => 'INV-1001']);
ok('external_ref تکراری → همان سفارش', ($dup['code'] ?? '') === $code);

// ۳- استعلام
$st = $pay->getStatus($code);
ok('getStatus pending', $st['status'] === 'pending' && $st['paid'] === false);

// ۴- استعلام با external_ref
$st2 = $pay->getStatus('INV-1001');
ok('getStatus با external_ref', ($st2['status'] ?? '') === 'pending');

// ۵- جزئیات
$det = $pay->getOrder($code);
ok('getOrder عنوان درست', ($det['title'] ?? '') === 'اشتراک ماهانه');

// ۶- لینک
$link = $pay->createLink(['amount' => 99000, 'title' => 'حمایت']);
ok('createLink توکن ۳۲ کاراکتری', isset($link['token']) && strlen($link['token']) === 32);
ok('createLink مبلغ ریالی', ($link['amount_rial'] ?? 0) === 990000);

// ۷- وضعیت عمومی بدون کلید
$pub = $pay->publicStatus($link['token']);
ok('publicStatus بدون کلید کار می‌کند', ($pub['ok'] ?? false) === true);
ok('publicStatus pending', ($pub['status'] ?? '') === 'pending');

// ۸- امضای غلط
try {
    $bad = new Connect($BASE, 'ak_test000001', 'wrong_secret');
    $bad->getStatus($code);
    ok('امضای غلط رد می‌شود', false);
} catch (ConnectException $e) {
    ok('امضای غلط رد می‌شود', $e->errorCode() === 'bad_signature');
}

// ۹- کلید نامعتبر
try {
    $bad = new Connect($BASE, 'ak_wrong', 'test_secret_0123456789abcdef');
    $bad->getStatus($code);
    ok('کلید نامعتبر رد می‌شود', false);
} catch (ConnectException $e) {
    ok('کلید نامعتبر رد می‌شود', $e->errorCode() === 'invalid_key');
}

// ۱۰- مبلغ کم
try {
    $pay->createOrder(['amount' => 500, 'title' => 'کم']);
    ok('مبلغ زیر حد رد می‌شود', false);
} catch (ConnectException $e) {
    ok('مبلغ زیر حد رد می‌شود', $e->errorCode() === 'invalid_amount');
}

// ۱۱- عنوان خالی
try {
    $pay->createOrder(['amount' => 50000, 'title' => '']);
    ok('عنوان خالی رد می‌شود', false);
} catch (ConnectException $e) {
    ok('عنوان خالی رد می‌شود', $e->errorCode() === 'missing_title');
}

// ۱۲- سفارش ناموجود
try {
    $pay->getStatus('ORD-000000-XXXXX');
    ok('سفارش ناموجود 404', false);
} catch (ConnectException $e) {
    ok('سفارش ناموجود 404', $e->httpStatus() === 404);
}

// ۱۳- waitForPayment با علامت‌گذاری
$o2 = $pay->createOrder(['amount' => 120000, 'title' => 'تست انتظار', 'external_ref' => 'INV-2002']);
$code2 = $o2['code'];
// علامت‌گذاری از طریق سرور تست
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => json_encode(['code' => $code2]),
]]);
@file_get_contents($BASE . '/__mark_paid', false, $ctx);

$final = $pay->waitForPayment($code2, 30, 1);
ok('waitForPayment پرداخت را می‌بیند', ($final['paid'] ?? false) === true);

// ۱۴- خطای شبکه
try {
    $dead = new Connect('http://127.0.0.1:9', 'ak_x', 'y', 2);
    $dead->getStatus('X');
    ok('خطای شبکه ConnectException', false);
} catch (ConnectException $e) {
    ok('خطای شبکه ConnectException', $e->errorCode() === 'network_error');
}

echo "\nنتیجه: $pass PASS · $fail FAIL\n";
exit($fail === 0 ? 0 : 1);

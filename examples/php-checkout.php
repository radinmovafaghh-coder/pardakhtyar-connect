<?php
/**
 * نمونهٔ کامل: اتصال یک فروشگاه PHP به پرداختیار
 *
 * این فایل یک نمونهٔ حداقلی و کارآمد است — کافی است
 * PAY_KEY_ID و PAY_SECRET را از پنل پرداختیار بگیرید.
 */
declare(strict_types=1);

require __DIR__ . '/../sdk/php/Connect.php';

use Pardakhtyar\Connect;
use Pardakhtyar\ConnectException;

// ── پیکربندی ────────────────────────────────────────────────
$pay = new Connect(
    getenv('PAY_BASE_URL') ?: 'https://pay.lenvato.ir',
    getenv('PAY_KEY_ID') ?: '',      // ak_...
    getenv('PAY_SECRET') ?: ''       // فقط در متغیر محیطی
);

// ── ۱) ساخت سفارش و هدایت مشتری ─────────────────────────────
function startCheckout(Connect $pay, int $amountToman, string $invoiceId): void
{
    try {
        $order = $pay->createOrder([
            'amount'       => $amountToman,
            'title'        => 'سفارش ' . $invoiceId,
            'external_ref' => $invoiceId,        // برای جلوگیری از سفارش تکراری
            'customer_name'   => $_POST['name'] ?? '',
            'customer_mobile' => $_POST['mobile'] ?? '',
        ]);

        // سفارش را در دیتابیس خودتان ذخیره کنید
        // saveOrder($order['code'], $invoiceId, $order['amount_rial']);

        header('Location: ' . $order['pay_url']);
        exit;
    } catch (ConnectException $e) {
        http_response_code(502);
        echo 'خطا در ساخت سفارش: ' . htmlspecialchars($e->getMessage());
    }
}

// ── ۲) بررسی پرداخت (webhook نداریم — polling) ──────────────
function checkPayment(Connect $pay, string $orderCode): bool
{
    try {
        $st = $pay->getStatus($orderCode);
        return !empty($st['paid']);
    } catch (ConnectException $e) {
        error_log('pay status error: ' . $e->getMessage());
        return false;
    }
}

// ── ۳) نمونهٔ استفاده در صفحهٔ بازگشت ────────────────────────
if (isset($_GET['order'])) {
    $paid = checkPayment($pay, $_GET['order']);
    echo $paid
        ? '<h1>پرداخت تأیید شد</h1>'
        : '<h1>در انتظار تأیید واریز</h1><p>صفحه را چند لحظه بعد دوباره باز کنید.</p>';
}

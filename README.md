<div align="center">

# Pardakhtyar Connect

**پرداخت کارت‌به‌کارت، خودکار و بدون درگاه بانکی**

[![License: MIT](https://img.shields.io/badge/License-MIT-0D2340.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Node.js](https://img.shields.io/badge/Node.js-18%2B-339933?logo=node.js&logoColor=white)](https://nodejs.org/)
[![Python](https://img.shields.io/badge/Python-3.8%2B-3776AB?logo=python&logoColor=white)](https://www.python.org/)

کتابخانهٔ رسمی اتصال سایت شما به [پرداختیار](https://pay.lenvato.ir) — سیستم پرداخت کارت‌به‌کارت
با تطبیق خودکار واریز.

</div>

---

## چرا

درگاه بانکی می‌خواهد مجوز، قرارداد، تضمین و کارمزد. برای خیلی از کسب‌وکارهای ایرانی
این مسیر طولانی است. اما کارت‌به‌کارت هم دستی است: باید پیامک بانک را بخوانی،
مبلغ را با سفارش تطبیق بدهی، و دستی تأیید کنی.

این کتابخانه آن کار دستی را برمی‌دارد:

- **مبلغ یکتا** — هر سفارش چند ریال اختلاف دارد، پس واریز به سفارش درست وصل می‌شود
- **بدون webhook** — تأیید با استعلام سمت سرور انجام می‌شود، پس اگر سرور شما لحظه‌ای خاموش باشد پرداختی گم نمی‌شود
- **امضای HMAC-SHA256** — هر درخواست امضا می‌شود، با nonce و پنجرهٔ زمانی ۳۰۰ ثانیه
- **بدون وابستگی** — PHP فقط cURL، Node از `fetch` بومی، Python فقط کتابخانهٔ استاندارد

---

## نصب

فقط یک فایل لازم است — فایل زبان خودت را کنار پروژه بگذار.

### PHP

```bash
cp sdk/php/Connect.php /path/to/your/project/
```

```php
require 'Connect.php';

use Pardakhtyar\Connect;

$pay = new Connect(
    'https://pay.lenvato.ir',
    getenv('PAY_KEY_ID'),
    getenv('PAY_SECRET')
);
```

### Node.js

```bash
cp sdk/node/connect.js /path/to/your/project/
```

```js
const { Connect } = require('./connect');

const pay = new Connect({
  baseUrl: 'https://pay.lenvato.ir',
  keyId: process.env.PAY_KEY_ID,
  secret: process.env.PAY_SECRET,
});
```

### Python

```bash
cp sdk/python/pardakhtyar_connect.py /path/to/your/project/
```

```python
from pardakhtyar_connect import Connect

pay = Connect(
    base_url='https://pay.lenvato.ir',
    key_id=os.environ['PAY_KEY_ID'],
    secret=os.environ['PAY_SECRET'],
)
```

---

## استفاده

### ۱) ساخت سفارش

```php
$order = $pay->createOrder([
    'amount'       => 250000,              // تومان
    'title'        => 'اشتراک ماهانه',
    'external_ref' => 'INV-1001',          // idempotency
    'customer_name'   => 'رادین',
    'customer_mobile' => '09334844913',
]);

header('Location: ' . $order['pay_url']);
```

پاسخ:

```json
{
  "ok": true,
  "order": {
    "code": "ORD-261030-E72F2",
    "amount_rial": 2500137,
    "base_amount_rial": 2500000,
    "unique_offset_rial": 137,
    "status": "pending",
    "expires_at": "2026-10-30 14:30:00",
    "pay_url": "https://pay.lenvato.ir/p/6636b47f..."
  }
}
```

### ۲) بررسی پرداخت

```php
$status = $pay->getStatus($order['code']);

if ($status['paid']) {
    // سفارش را تأیید کن، دسترسی بده، ایمیل بفرست
}
```

### ۳) انتظار برای پرداخت

```php
$final = $pay->waitForPayment($order['code'], timeoutSec: 1800, interval: 5);

if ($final['paid']) {
    echo 'پرداخت شد';
}
```

> **نکته:** `waitForPayment` در یک درخواست HTTP طولانی بلاک می‌شود.
> در وب‌اپ واقعی این کار را در یک job پس‌زمینه یا کرون انجام دهید.

---

## API

مسیر پایه: `https://pay.lenvato.ir`

| متد | مسیر | کار | احراز هویت |
|---|---|---|---|
| `POST` | `/api/v1/orders` | ساخت سفارش با مبلغ یکتا | بله |
| `GET` | `/api/v1/orders/{code}` | جزئیات سفارش | بله |
| `GET` | `/api/v1/status/{ref}` | استعلام — کد سفارش یا `external_ref` | بله |
| `POST` | `/api/v1/links` | ساخت لینک پرداخت بدون سفارش | بله |
| `GET` | `/api/v1/public/status/{token}` | وضعیت عمومی لینک | خیر |

### احراز هویت

| هدر | مقدار |
|---|---|
| `X-Api-Key` | شناسهٔ کلید، مثل `ak_ac7de27e0d74` |
| `X-Api-Signature` | `HMAC-SHA256(timestamp + "\n" + nonce + "\n" + raw_body, secret)` |
| `X-Api-Timestamp` | زمان یونیکس — حداکثر ۳۰۰ ثانیه اختلاف |
| `X-Api-Nonce` | رشتهٔ تصادفی یکتا در هر درخواست |

امضا روی **بدنهٔ خام** درخواست محاسبه می‌شود، نه روی آبجکت بازسازی‌شده.
اگر JSON را دوباره سریالایز کنید، امضا می‌شکند.

### کدهای خطا

| کد | HTTP | معنی |
|---|---|---|
| `missing_auth` | 401 | هدرهای احراز هویت ناقص |
| `invalid_key` | 401 | کلید وجود ندارد یا غیرفعال است |
| `bad_signature` | 401 | امضا مطابقت ندارد |
| `timestamp_expired` | 401 | اختلاف ساعت بیش از ۳۰۰ ثانیه |
| `invalid_amount` | 422 | مبلغ کمتر از ۱۰۰۰ تومان |
| `missing_title` | 422 | عنوان خالی |
| `not_found` | 404 | سفارش یا لینک پیدا نشد |
| `rate_limited` | 429 | بیش از حد مجاز درخواست |

---

## وضعیت‌ها

| وضعیت | معنی |
|---|---|
| `pending` | سفارش ساخته شده، منتظر واریز |
| `paid` | واریز تطبیق خورد و تأیید شد |
| `expired` | مهلت تمام شد |
| `canceled` | لغو شد |
| `review` | واریز رسید ولی تطبیق نشد — بررسی دستی |

---

## نکات مهم

### مبلغ دقیق را واریز کنید

مبلغ سفارش با چند ریال اختلاف یکتا می‌شود. مشتری باید **همان مبلغ** را واریز کند.
اگر گرد کند، تطبیق انجام نمی‌شود و تراکنش در صف `review` می‌ماند.

### کلید مخفی را در کد نگذارید

```bash
# درست
export PAY_SECRET='...'

# غلط — در گیت ثبت می‌شود
$pay = new Connect($url, 'ak_...', 'secret_here');
```

### idempotency

`external_ref` را همیشه بفرستید. اگر دوبار کلیک شود یا شبکه قطع و وصل شود،
همان سفارش برمی‌گردد — سفارش دوم ساخته نمی‌شود.

### ساعت سرور

امضا به زمان وابسته است. اگر ساعت سرور شما بیش از ۵ دقیقه اختلاف داشته باشد،
همهٔ درخواست‌ها `timestamp_expired` می‌گیرند. `ntpdate` یا `chrony` را فعال کنید.

---

## ساختار

```
pardakhtyar-connect/
├── sdk/
│   ├── php/Connect.php               کلاینت PHP
│   ├── node/connect.js               کلاینت Node.js
│   └── python/pardakhtyar_connect.py کلاینت Python
├── docs/
│   └── API.md                        مرجع کامل API
├── assets/
│   └── mark.svg                      نشان
└── index.html                        صفحهٔ معرفی
```

---

## مجوز

MIT — استفادهٔ تجاری آزاد است.

ساخته‌شده برای [پرداختیار](https://pay.lenvato.ir) · [لنواتو](https://lenvato.ir)

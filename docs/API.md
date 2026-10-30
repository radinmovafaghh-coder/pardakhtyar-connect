# مرجع کامل API

مسیر پایه: `https://pay.lenvato.ir`

همهٔ درخواست‌ها و پاسخ‌ها `application/json` هستند.

---

## احراز هویت

هر درخواست محافظت‌شده باید چهار هدر داشته باشد:

```
X-Api-Key:       ak_ac7de27e0d74
X-Api-Signature: <hex>
X-Api-Timestamp: 1793510400
X-Api-Nonce:     <32 hex chars>
```

### محاسبهٔ امضا

```
signature = HMAC-SHA256(
    key   = secret,
    data  = timestamp + "\n" + nonce + "\n" + raw_body
)
```

`raw_body` همان بایت‌هایی است که روی سیم می‌رود. برای `GET` خالی است.

**مثال PHP:**

```php
$ts    = (string)time();
$nonce = bin2hex(random_bytes(16));
$raw   = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sig   = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $raw, $secret);
```

**مثال Node:**

```js
const ts = String(Math.floor(Date.now() / 1000));
const nonce = crypto.randomBytes(16).toString('hex');
const sig = crypto.createHmac('sha256', secret)
  .update(`${ts}\n${nonce}\n${raw}`)
  .digest('hex');
```

### قواعد امنیتی

- پنجرهٔ زمانی: ۳۰۰ ثانیه
- `nonce` باید یکتا باشد
- امضا با `hash_equals` مقایسه می‌شود (مقاوم به timing attack)
- هر کلید محدودیت نرخ دارد (پیش‌فرض ۱۲۰ درخواست در دقیقه)

---

## POST /api/v1/orders

ساخت سفارش با مبلغ یکتا و لینک پرداخت.

### بدنه

| فیلد | نوع | الزامی | توضیح |
|---|---|---|---|
| `amount` | int | ✓ | مبلغ به **تومان**، حداقل ۱۰۰۰ |
| `title` | string | ✓ | عنوان سفارش |
| `external_ref` | string | | شناسهٔ شما — برای idempotency |
| `customer_name` | string | | نام مشتری |
| `customer_mobile` | string | | موبایل مشتری |
| `expiry_minutes` | int | | مهلت پرداخت، پیش‌فرض ۳۰ |

### پاسخ ۲۰۱

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
    "pay_url": "https://pay.lenvato.ir/p/6636b47fd71ed56d18067db9e3da612d"
  }
}
```

### idempotency

اگر `external_ref` تکراری باشد، همان سفارش قبلی برمی‌گردد با `duplicate: true`:

```json
{
  "ok": true,
  "duplicate": true,
  "order": { "code": "ORD-261030-E72F2", ... }
}
```

---

## GET /api/v1/orders/{code}

جزئیات یک سفارش.

### پاسخ ۲۰۰

```json
{
  "ok": true,
  "order": {
    "code": "ORD-261030-E72F2",
    "title": "اشتراک ماهانه",
    "amount_rial": 2500137,
    "status": "paid",
    "customer_name": "رادین",
    "created_at": "2026-10-30 13:58:12",
    "expires_at": "2026-10-30 14:28:12",
    "paid_at": "2026-10-30 14:03:41",
    "pay_url": "https://pay.lenvato.ir/p/6636b47f..."
  }
}
```

---

## GET /api/v1/status/{ref}

استعلام سبک — با کد سفارش یا `external_ref`.

### پاسخ ۲۰۰

```json
{
  "ok": true,
  "status": "paid",
  "paid": true,
  "amount_rial": 2500137,
  "paid_at": "2026-10-30 14:03:41",
  "transaction": {
    "amount_rial": 2500137,
    "card_last4": "4601",
    "bank_ref": "849201",
    "verified_at": "2026-10-30 14:03:38"
  }
}
```

`transaction` وقتی `null` است که هنوز واریزی تطبیق نخورده.

---

## POST /api/v1/links

ساخت لینک پرداخت بدون سفارش — برای donate یا پرداخت آزاد.

### بدنه

| فیلد | نوع | الزامی | توضیح |
|---|---|---|---|
| `amount` | int | ✓ | مبلغ به تومان |
| `title` | string | ✓ | عنوان |
| `description` | string | | توضیح |
| `max_uses` | int | | تعداد استفاده، پیش‌فرض ۱ |
| `expires_at` | string | | تاریخ انقضا |

### پاسخ ۲۰۱

```json
{
  "ok": true,
  "link": {
    "token": "6636b47fd71ed56d18067db9e3da612d",
    "url": "https://pay.lenvato.ir/p/6636b47fd71ed56d18067db9e3da612d",
    "amount_rial": 990000,
    "max_uses": 1,
    "expires_at": null
  }
}
```

---

## GET /api/v1/public/status/{token}

وضعیت عمومی لینک — **بدون کلید API**.

این تنها اندپوینت بدون احراز هویت است، چون صفحهٔ پرداخت مشتری باید
بدون کلید بتواند وضعیت خودش را ببیند.

### پاسخ ۲۰۰

```json
{
  "ok": true,
  "paid": false,
  "status": "pending",
  "amount_rial": 2500137,
  "order_code": "ORD-261030-E72F2",
  "paid_at": null
}
```

توکن ۳۲ کاراکتر hex است.

---

## کدهای خطا

| کد | HTTP | معنی | راه‌حل |
|---|---|---|---|
| `missing_auth` | 401 | هدر ناقص | هر چهار هدر را بفرست |
| `invalid_key` | 401 | کلید نامعتبر | کلید را در پنل بررسی کن |
| `bad_signature` | 401 | امضا غلط | `raw_body` را دقیقاً همان بفرست |
| `timestamp_expired` | 401 | ساعت ناهماهنگ | `chrony` را فعال کن |
| `forbidden` | 403 | دسترسی کلید کافی نیست | scope کلید را گسترش بده |
| `invalid_amount` | 422 | مبلغ کم | حداقل ۱۰۰۰ تومان |
| `missing_title` | 422 | عنوان خالی | `title` بفرست |
| `invalid_input` | 422 | ورودی نامعتبر | فیلدها را بررسی کن |
| `not_found` | 404 | پیدا نشد | کد را بررسی کن |
| `rate_limited` | 429 | درخواست زیاد | صبر کن یا کش کن |

---

## محدودیت نرخ

پیش‌فرض: ۱۲۰ درخواست در دقیقه برای هر کلید.

اگر `429` گرفتی، `Retry-After` را بخوان. برای polling صفحهٔ پرداخت،
فاصلهٔ ۵ ثانیه منطقی است.

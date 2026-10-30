<?php
declare(strict_types=1);

/**
 * Pardakhtyar Connect — کلاینت رسمی PHP
 *
 * پرداخت کارت‌به‌کارت هوشمند: سفارش بساز، لینک پرداخت بگیر،
 * واریز را خودکار تطبیق بده.
 *
 * @package  Pardakhtyar\Connect
 * @version  1.0.0
 * @license  MIT
 * @link     https://pay.lenvato.ir
 */

namespace Pardakhtyar;

final class ConnectException extends \RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    private ?array $payload;

    public function __construct(string $message, string $errorCode = 'unknown',
                                int $httpStatus = 0, ?array $payload = null)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->payload    = $payload;
    }

    public function errorCode(): string { return $this->errorCode; }
    public function httpStatus(): int { return $this->httpStatus; }
    public function payload(): ?array { return $this->payload; }
}

final class Connect
{
    private string $baseUrl;
    private string $keyId;
    private string $secret;
    private int $timeout;
    private int $clockSkew;

    /**
     * @param string $baseUrl  آدرس پایهٔ پرداختیار، مثلاً https://pay.lenvato.ir
     * @param string $keyId    شناسهٔ کلید API (ak_...)
     * @param string $secret   کلید مخفی — هرگز در کد یا مخزن نگذارید
     * @param int    $timeout  مهلت درخواست به ثانیه
     */
    public function __construct(string $baseUrl, string $keyId, string $secret, int $timeout = 20)
    {
        if ($keyId === '' || $secret === '') {
            throw new \InvalidArgumentException('کلید API و کلید مخفی الزامی است.');
        }
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->keyId    = $keyId;
        $this->secret   = $secret;
        $this->timeout  = $timeout;
        $this->clockSkew = 0;
    }

    /** ساخت از متغیرهای محیطی PAY_BASE_URL / PAY_KEY_ID / PAY_SECRET */
    public static function fromEnv(): self
    {
        return new self(
            (string)(getenv('PAY_BASE_URL') ?: 'https://pay.lenvato.ir'),
            (string)(getenv('PAY_KEY_ID') ?: ''),
            (string)(getenv('PAY_SECRET') ?: '')
        );
    }

    // ── عملیات اصلی ────────────────────────────────────────────

    /**
     * ساخت سفارش با مبلغ یکتا.
     *
     * @param array $data amount (تومان), title, external_ref, customer_name,
     *                    customer_mobile, expiry_minutes
     * @return array{code:string,amount_rial:int,pay_url:string,status:string,expires_at:string}
     */
    public function createOrder(array $data): array
    {
        if (!isset($data['amount'])) {
            throw new \InvalidArgumentException('فیلد amount الزامی است.');
        }
        $res = $this->request('POST', '/api/v1/orders', $data);
        return $res['order'];
    }

    /** ساخت لینک پرداخت بدون سفارش */
    public function createLink(array $data): array
    {
        $res = $this->request('POST', '/api/v1/links', $data);
        return $res['link'];
    }

    /** استعلام وضعیت با شناسهٔ سفارش یا external_ref */
    public function getStatus(string $ref): array
    {
        return $this->request('GET', '/api/v1/status/' . rawurlencode($ref));
    }

    /** جزئیات سفارش */
    public function getOrder(string $code): array
    {
        $res = $this->request('GET', '/api/v1/orders/' . rawurlencode($code));
        return $res['order'];
    }

    /**
     * وضعیت عمومی لینک — بدون کلید API.
     * برای صفحه‌ای که مشتری نهایی می‌بیند.
     */
    public function publicStatus(string $token): array
    {
        return $this->request('GET', '/api/v1/public/status/' . rawurlencode($token), null, false);
    }

    /** ثبت دستی تراکنش (وقتی پیامک خودکار ندارید) */
    public function manualTransaction(array $data): array
    {
        return $this->request('POST', '/api/v1/tx', $data);
    }

    // ── انتظار برای پرداخت ─────────────────────────────────────

    /**
     * تا تأیید پرداخت صبر می‌کند.
     *
     * @param string   $ref        کد سفارش یا external_ref
     * @param int      $timeoutSec حداکثر زمان انتظار
     * @param int      $interval   فاصلهٔ استعلام
     * @param callable $onTick     هر بار با وضعیت فعلی صدا زده می‌شود
     */
    public function waitForPayment(string $ref, int $timeoutSec = 1800,
                                   int $interval = 5, ?callable $onTick = null): array
    {
        $deadline = time() + $timeoutSec;
        while (time() < $deadline) {
            $st = $this->getStatus($ref);
            if ($onTick) {
                $onTick($st);
            }
            if (!empty($st['paid'])) {
                return $st;
            }
            if (in_array($st['status'] ?? '', ['canceled', 'expired', 'failed'], true)) {
                return $st;
            }
            sleep($interval);
        }
        return ['ok' => false, 'status' => 'timeout', 'paid' => false];
    }

    // ── لایهٔ شبکه و امضا ──────────────────────────────────────

    private function request(string $method, string $path, ?array $body = null, bool $signed = true): array
    {
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url = $this->baseUrl . $path;

        $headers = ['Accept: application/json'];

        if ($signed) {
            $ts    = (string)(time() + $this->clockSkew);
            $nonce = bin2hex(random_bytes(16));
            $sig   = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $raw, $this->secret);

            $headers[] = 'X-Api-Key: ' . $this->keyId;
            $headers[] = 'X-Api-Signature: ' . $sig;
            $headers[] = 'X-Api-Timestamp: ' . $ts;
            $headers[] = 'X-Api-Nonce: ' . $nonce;
        }

        if ($raw !== '') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($raw !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        }

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new ConnectException('اتصال به سرور برقرار نشد: ' . $err, 'network_error');
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new ConnectException('پاسخ سرور JSON معتبر نبود.', 'bad_response', $code);
        }

        if ($code >= 400 || (isset($data['ok']) && $data['ok'] === false)) {
            throw new ConnectException(
                (string)($data['message'] ?? 'خطای نامشخص از سرور'),
                (string)($data['error'] ?? 'http_' . $code),
                $code,
                $data
            );
        }

        return $data;
    }
}

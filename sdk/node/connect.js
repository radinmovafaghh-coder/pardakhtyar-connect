/**
 * Pardakhtyar Connect — کلاینت رسمی Node.js
 *
 * پرداخت کارت‌به‌کارت هوشمند: سفارش بساز، لینک پرداخت بگیر،
 * واریز را خودکار تطبیق بده.
 *
 * @package pardakhtyar-connect
 * @version 1.0.0
 * @license MIT
 */

'use strict';

const crypto = require('crypto');

class ConnectError extends Error {
  constructor(message, code = 'unknown', status = 0, payload = null) {
    super(message);
    this.name = 'ConnectError';
    this.code = code;
    this.status = status;
    this.payload = payload;
  }
}

class Connect {
  /**
   * @param {object} opts
   * @param {string} opts.baseUrl  آدرس پایه، مثلاً https://pay.lenvato.ir
   * @param {string} opts.keyId    شناسهٔ کلید (ak_...)
   * @param {string} opts.secret   کلید مخفی
   * @param {number} [opts.timeout=20000]
   */
  constructor({ baseUrl, keyId, secret, timeout = 20000 }) {
    if (!keyId || !secret) {
      throw new TypeError('کلید API و کلید مخفی الزامی است.');
    }
    this.baseUrl = String(baseUrl || 'https://pay.lenvato.ir').replace(/\/+$/, '');
    this.keyId = keyId;
    this.secret = secret;
    this.timeout = timeout;
  }

  /** ساخت از متغیرهای محیطی PAY_BASE_URL / PAY_KEY_ID / PAY_SECRET */
  static fromEnv(env = process.env) {
    return new Connect({
      baseUrl: env.PAY_BASE_URL,
      keyId: env.PAY_KEY_ID,
      secret: env.PAY_SECRET,
    });
  }

  // ── عملیات اصلی ──────────────────────────────────────────────

  /** ساخت سفارش با مبلغ یکتا */
  async createOrder(data) {
    if (data == null || data.amount == null) {
      throw new TypeError('فیلد amount الزامی است.');
    }
    const res = await this.#request('POST', '/api/v1/orders', data);
    return res.order;
  }

  /** ساخت لینک پرداخت بدون سفارش */
  async createLink(data) {
    const res = await this.#request('POST', '/api/v1/links', data);
    return res.link;
  }

  /** استعلام وضعیت با کد سفارش یا external_ref */
  async getStatus(ref) {
    return this.#request('GET', `/api/v1/status/${encodeURIComponent(ref)}`);
  }

  /** جزئیات سفارش */
  async getOrder(code) {
    const res = await this.#request('GET', `/api/v1/orders/${encodeURIComponent(code)}`);
    return res.order;
  }

  /** وضعیت عمومی لینک — بدون کلید */
  async publicStatus(token) {
    return this.#request(
      'GET',
      `/api/v1/public/status/${encodeURIComponent(token)}`,
      null,
      false
    );
  }

  /** ثبت دستی تراکنش */
  async manualTransaction(data) {
    return this.#request('POST', '/api/v1/tx', data);
  }

  // ── انتظار برای پرداخت ───────────────────────────────────────

  /**
   * تا تأیید پرداخت صبر می‌کند.
   * @param {string} ref
   * @param {{timeoutSec?:number, interval?:number, onTick?:Function}} [opts]
   */
  async waitForPayment(ref, opts = {}) {
    const timeoutSec = opts.timeoutSec ?? 1800;
    const interval = opts.interval ?? 5;
    const deadline = Date.now() + timeoutSec * 1000;

    while (Date.now() < deadline) {
      const st = await this.getStatus(ref);
      if (opts.onTick) opts.onTick(st);
      if (st.paid) return st;
      if (['canceled', 'expired', 'failed'].includes(st.status)) return st;
      await new Promise((r) => setTimeout(r, interval * 1000));
    }
    return { ok: false, status: 'timeout', paid: false };
  }

  // ── شبکه و امضا ──────────────────────────────────────────────

  async #request(method, path, body = null, signed = true) {
    const raw = body == null ? '' : JSON.stringify(body);
    const headers = { Accept: 'application/json' };

    if (signed) {
      const ts = String(Math.floor(Date.now() / 1000));
      const nonce = crypto.randomBytes(16).toString('hex');
      const sig = crypto
        .createHmac('sha256', this.secret)
        .update(`${ts}\n${nonce}\n${raw}`)
        .digest('hex');

      headers['X-Api-Key'] = this.keyId;
      headers['X-Api-Signature'] = sig;
      headers['X-Api-Timestamp'] = ts;
      headers['X-Api-Nonce'] = nonce;
    }

    if (raw) headers['Content-Type'] = 'application/json';

    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), this.timeout);

    let res;
    try {
      res = await fetch(this.baseUrl + path, {
        method,
        headers,
        body: raw || undefined,
        signal: ctrl.signal,
      });
    } catch (e) {
      throw new ConnectError(`اتصال به سرور برقرار نشد: ${e.message}`, 'network_error');
    } finally {
      clearTimeout(timer);
    }

    let data;
    try {
      data = await res.json();
    } catch {
      throw new ConnectError('پاسخ سرور JSON معتبر نبود.', 'bad_response', res.status);
    }

    if (res.status >= 400 || data.ok === false) {
      throw new ConnectError(
        data.message || 'خطای نامشخص از سرور',
        data.error || `http_${res.status}`,
        res.status,
        data
      );
    }

    return data;
  }
}

module.exports = { Connect, ConnectError };

"""
Pardakhtyar Connect — کلاینت رسمی Python

پرداخت کارت‌به‌کارت هوشمند: سفارش بساز، لینک پرداخت بگیر،
واریز را خودکار تطبیق بده.

نصب:  pip install requests
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import secrets
import time
import urllib.error
import urllib.request
from typing import Any, Callable, Optional

__version__ = "1.0.0"
__all__ = ["Connect", "ConnectError"]


class ConnectError(Exception):
    """خطای برگشتی از API پرداختیار."""

    def __init__(
        self,
        message: str,
        code: str = "unknown",
        status: int = 0,
        payload: Optional[dict] = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.status = status
        self.payload = payload


class Connect:
    """کلاینت API پرداختیار.

    >>> pay = Connect("https://pay.lenvato.ir", "ak_...", "secret")
    >>> order = pay.create_order(amount=250_000, title="اشتراک ماهانه")
    >>> print(order["pay_url"])
    """

    def __init__(
        self,
        base_url: str = "https://pay.lenvato.ir",
        key_id: str = "",
        secret: str = "",
        timeout: int = 20,
    ) -> None:
        if not key_id or not secret:
            raise ValueError("کلید API و کلید مخفی الزامی است.")
        self.base_url = base_url.rstrip("/")
        self.key_id = key_id
        self.secret = secret
        self.timeout = timeout

    @classmethod
    def from_env(cls) -> "Connect":
        """ساخت از متغیرهای محیطی PAY_BASE_URL / PAY_KEY_ID / PAY_SECRET."""
        return cls(
            base_url=os.environ.get("PAY_BASE_URL", "https://pay.lenvato.ir"),
            key_id=os.environ.get("PAY_KEY_ID", ""),
            secret=os.environ.get("PAY_SECRET", ""),
        )

    # ── عملیات اصلی ──────────────────────────────────────────────

    def create_order(
        self,
        amount: int,
        title: str,
        external_ref: Optional[str] = None,
        customer_name: Optional[str] = None,
        customer_mobile: Optional[str] = None,
        expiry_minutes: Optional[int] = None,
    ) -> dict:
        """ساخت سفارش با مبلغ یکتا.

        :param amount: مبلغ به **تومان**
        :returns: دیکشنری سفارش شامل ``pay_url``
        """
        body: dict[str, Any] = {"amount": amount, "title": title}
        if external_ref:
            body["external_ref"] = external_ref
        if customer_name:
            body["customer_name"] = customer_name
        if customer_mobile:
            body["customer_mobile"] = customer_mobile
        if expiry_minutes:
            body["expiry_minutes"] = expiry_minutes

        return self._request("POST", "/api/v1/orders", body)["order"]

    def create_link(
        self,
        amount: int,
        title: str,
        description: Optional[str] = None,
        max_uses: int = 1,
        expires_at: Optional[str] = None,
    ) -> dict:
        """ساخت لینک پرداخت بدون سفارش."""
        body: dict[str, Any] = {"amount": amount, "title": title, "max_uses": max_uses}
        if description:
            body["description"] = description
        if expires_at:
            body["expires_at"] = expires_at
        return self._request("POST", "/api/v1/links", body)["link"]

    def get_status(self, ref: str) -> dict:
        """استعلام وضعیت با کد سفارش یا ``external_ref``."""
        return self._request("GET", f"/api/v1/status/{ref}")

    def get_order(self, code: str) -> dict:
        """جزئیات سفارش."""
        return self._request("GET", f"/api/v1/orders/{code}")["order"]

    def public_status(self, token: str) -> dict:
        """وضعیت عمومی لینک — بدون کلید API."""
        return self._request("GET", f"/api/v1/public/status/{token}", signed=False)

    def manual_transaction(
        self, amount_rial: int, text: str = "", external_id: Optional[str] = None
    ) -> dict:
        """ثبت دستی تراکنش."""
        body: dict[str, Any] = {"amount_rial": amount_rial}
        if text:
            body["text"] = text
        if external_id:
            body["external_id"] = external_id
        return self._request("POST", "/api/v1/tx", body)

    # ── انتظار برای پرداخت ───────────────────────────────────────

    def wait_for_payment(
        self,
        ref: str,
        timeout_sec: int = 1800,
        interval: int = 5,
        on_tick: Optional[Callable[[dict], None]] = None,
    ) -> dict:
        """تا تأیید پرداخت صبر می‌کند."""
        deadline = time.time() + timeout_sec
        while time.time() < deadline:
            st = self.get_status(ref)
            if on_tick:
                on_tick(st)
            if st.get("paid"):
                return st
            if st.get("status") in ("canceled", "expired", "failed"):
                return st
            time.sleep(interval)
        return {"ok": False, "status": "timeout", "paid": False}

    # ── شبکه و امضا ──────────────────────────────────────────────

    def _request(
        self, method: str, path: str, body: Optional[dict] = None, signed: bool = True
    ) -> dict:
        raw = "" if body is None else json.dumps(body, ensure_ascii=False)
        headers = {"Accept": "application/json"}

        if signed:
            ts = str(int(time.time()))
            nonce = secrets.token_hex(16)
            msg = f"{ts}\n{nonce}\n{raw}".encode()
            sig = hmac.new(self.secret.encode(), msg, hashlib.sha256).hexdigest()
            headers.update(
                {
                    "X-Api-Key": self.key_id,
                    "X-Api-Signature": sig,
                    "X-Api-Timestamp": ts,
                    "X-Api-Nonce": nonce,
                }
            )

        data = raw.encode() if raw else None
        if data:
            headers["Content-Type"] = "application/json"

        req = urllib.request.Request(
            self.base_url + path, data=data, headers=headers, method=method
        )

        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                payload = json.loads(resp.read().decode())
                status = resp.status
        except urllib.error.HTTPError as e:
            try:
                payload = json.loads(e.read().decode())
            except Exception:
                payload = {"ok": False, "message": str(e)}
            status = e.code
        except urllib.error.URLError as e:
            raise ConnectError(f"اتصال به سرور برقرار نشد: {e.reason}", "network_error") from e

        if status >= 400 or payload.get("ok") is False:
            raise ConnectError(
                payload.get("message", "خطای نامشخص از سرور"),
                payload.get("error", f"http_{status}"),
                status,
                payload,
            )

        return payload

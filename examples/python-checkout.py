"""
نمونهٔ کامل: اتصال یک فروشگاه Python به پرداختیار

اجرا:  PAY_KEY_ID=ak_... PAY_SECRET=... python python-checkout.py
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'sdk', 'python'))

from pardakhtyar_connect import Connect, ConnectError


def start_checkout(pay: Connect, amount_toman: int, invoice_id: str, customer=None) -> str:
    """ساخت سفارش و برگرداندن لینک پرداخت."""
    customer = customer or {}
    try:
        order = pay.create_order(
            amount=amount_toman,
            title=f'سفارش {invoice_id}',
            external_ref=invoice_id,
            customer_name=customer.get('name'),
            customer_mobile=customer.get('mobile'),
        )
        # سفارش را در دیتابیس خودتان ذخیره کنید
        print(f"سفارش {order['code']} — {order['amount_rial']:,} ریال")
        return order['pay_url']
    except ConnectError as e:
        print(f'خطای پرداختیار: {e.code} — {e}', file=sys.stderr)
        raise


def check_payment(pay: Connect, order_code: str) -> bool:
    """بررسی تأیید واریز."""
    try:
        return pay.get_status(order_code).get('paid') is True
    except ConnectError as e:
        print(f'خطای استعلام: {e}', file=sys.stderr)
        return False


def main() -> None:
    pay = Connect(
        base_url=os.environ.get('PAY_BASE_URL', 'https://pay.lenvato.ir'),
        key_id=os.environ.get('PAY_KEY_ID', ''),
        secret=os.environ.get('PAY_SECRET', ''),
    )

    url = start_checkout(pay, 250_000, 'INV-PY-001', {'name': 'مشتری', 'mobile': '09120000000'})
    print(f'لینک پرداخت: {url}')

    # انتظار برای پرداخت (در وب‌اپ واقعی این کار را در پس‌زمینه انجام دهید)
    order_code = url.rsplit('/', 1)[-1]
    print('در انتظار واریز...')


if __name__ == '__main__':
    main()

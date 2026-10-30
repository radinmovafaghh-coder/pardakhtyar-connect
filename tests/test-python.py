#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""تست SDK Python در برابر سرور تست محلی."""
import json
import os
import sys
import urllib.request

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'sdk', 'python'))

from pardakhtyar_connect import Connect, ConnectError

BASE = os.environ.get('TEST_BASE', 'http://127.0.0.1:8977')
SECRET = 'test_secret_0123456789abcdef'

passed = 0
failed = 0


def ok(name, cond, extra=''):
    global passed, failed
    if cond:
        passed += 1
        print(f'  PASS  {name}')
    else:
        failed += 1
        print(f'  FAIL  {name}' + (f' — {extra}' if extra else ''))


def mark_paid(code):
    req = urllib.request.Request(
        f'{BASE}/__mark_paid',
        data=json.dumps({'code': code}).encode(),
        headers={'Content-Type': 'application/json'},
        method='POST',
    )
    urllib.request.urlopen(req, timeout=10).read()


def main():
    print('=== SDK Python ===')

    pay = Connect(BASE, 'ak_test000001', SECRET)

    order = pay.create_order(
        amount=250000,
        title='اشتراک ماهانه',
        external_ref='PY-1001',
        customer_name='رادین',
        customer_mobile='09334844913',
    )
    ok('create_order کد سفارش دارد', bool(order.get('code')))
    ok('create_order لینک پرداخت دارد', bool(order.get('pay_url')))
    ok('create_order مبلغ یکتاست', order['amount_rial'] > 2500000)
    ok('create_order وضعیت pending', order['status'] == 'pending')

    dup = pay.create_order(amount=250000, title='اشتراک ماهانه', external_ref='PY-1001')
    ok('external_ref تکراری → همان سفارش', dup.get('code') == order['code'])

    st = pay.get_status(order['code'])
    ok('get_status pending', st['status'] == 'pending' and st['paid'] is False)

    st2 = pay.get_status('PY-1001')
    ok('get_status با external_ref', st2.get('status') == 'pending')

    det = pay.get_order(order['code'])
    ok('get_order عنوان درست', det.get('title') == 'اشتراک ماهانه')

    link = pay.create_link(amount=99000, title='حمایت')
    ok('create_link توکن ۳۲ کاراکتری', len(link['token']) == 32)
    ok('create_link مبلغ ریالی', link['amount_rial'] == 990000)

    pub = pay.public_status(link['token'])
    ok('public_status بدون کلید', pub.get('ok') is True)
    ok('public_status pending', pub.get('status') == 'pending')

    try:
        Connect(BASE, 'ak_test000001', 'wrong_secret').get_status(order['code'])
        ok('امضای غلط رد می‌شود', False)
    except ConnectError as e:
        ok('امضای غلط رد می‌شود', e.code == 'bad_signature')

    try:
        Connect(BASE, 'ak_wrong', SECRET).get_status(order['code'])
        ok('کلید نامعتبر رد می‌شود', False)
    except ConnectError as e:
        ok('کلید نامعتبر رد می‌شود', e.code == 'invalid_key')

    try:
        pay.create_order(amount=500, title='کم')
        ok('مبلغ زیر حد رد می‌شود', False)
    except ConnectError as e:
        ok('مبلغ زیر حد رد می‌شود', e.code == 'invalid_amount')

    try:
        pay.get_status('ORD-000000-XXXXX')
        ok('سفارش ناموجود 404', False)
    except ConnectError as e:
        ok('سفارش ناموجود 404', e.status == 404)

    o2 = pay.create_order(amount=120000, title='تست انتظار', external_ref='PY-2002')
    mark_paid(o2['code'])
    final = pay.wait_for_payment(o2['code'], timeout_sec=30, interval=1)
    ok('wait_for_payment پرداخت را می‌بیند', final.get('paid') is True)

    try:
        Connect('http://127.0.0.1:9', 'x', 'y', timeout=2).get_status('X')
        ok('خطای شبکه ConnectError', False)
    except ConnectError as e:
        ok('خطای شبکه ConnectError', e.code == 'network_error')

    print(f'\nنتیجه: {passed} PASS · {failed} FAIL')
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    sys.exit(main())

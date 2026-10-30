#!/usr/bin/env node
'use strict';

/** تست SDK Node.js در برابر سرور تست محلی. */

const { Connect, ConnectError } = require('../sdk/node/connect.js');

const BASE = process.env.TEST_BASE || 'http://127.0.0.1:8977';
let pass = 0;
let fail = 0;

function ok(name, cond, extra = '') {
  if (cond) {
    pass++;
    console.log(`  PASS  ${name}`);
  } else {
    fail++;
    console.log(`  FAIL  ${name}${extra ? ` — ${extra}` : ''}`);
  }
}

async function markPaid(code) {
  await fetch(`${BASE}/__mark_paid`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code }),
  });
}

(async () => {
  console.log('=== SDK Node.js ===');

  const pay = new Connect({
    baseUrl: BASE,
    keyId: 'ak_test000001',
    secret: 'test_secret_0123456789abcdef',
  });

  const order = await pay.createOrder({
    amount: 250000,
    title: 'اشتراک ماهانه',
    external_ref: 'NODE-1001',
    customer_name: 'رادین',
  });
  ok('createOrder کد سفارش دارد', !!order.code);
  ok('createOrder لینک پرداخت دارد', !!order.pay_url);
  ok('createOrder مبلغ یکتاست', order.amount_rial > 2500000);
  ok('createOrder وضعیت pending', order.status === 'pending');

  const dup = await pay.createOrder({
    amount: 250000,
    title: 'اشتراک ماهانه',
    external_ref: 'NODE-1001',
  });
  ok('external_ref تکراری → همان سفارش', dup.code === order.code);

  const st = await pay.getStatus(order.code);
  ok('getStatus pending', st.status === 'pending' && st.paid === false);

  const st2 = await pay.getStatus('NODE-1001');
  ok('getStatus با external_ref', st2.status === 'pending');

  const det = await pay.getOrder(order.code);
  ok('getOrder عنوان درست', det.title === 'اشتراک ماهانه');

  const link = await pay.createLink({ amount: 99000, title: 'حمایت' });
  ok('createLink توکن ۳۲ کاراکتری', link.token.length === 32);
  ok('createLink مبلغ ریالی', link.amount_rial === 990000);

  const pub = await pay.publicStatus(link.token);
  ok('publicStatus بدون کلید', pub.ok === true);
  ok('publicStatus pending', pub.status === 'pending');

  try {
    const bad = new Connect({
      baseUrl: BASE,
      keyId: 'ak_test000001',
      secret: 'wrong_secret',
    });
    await bad.getStatus(order.code);
    ok('امضای غلط رد می‌شود', false);
  } catch (e) {
    ok('امضای غلط رد می‌شود', e instanceof ConnectError && e.code === 'bad_signature');
  }

  try {
    const bad = new Connect({
      baseUrl: BASE,
      keyId: 'ak_wrong',
      secret: 'test_secret_0123456789abcdef',
    });
    await bad.getStatus(order.code);
    ok('کلید نامعتبر رد می‌شود', false);
  } catch (e) {
    ok('کلید نامعتبر رد می‌شود', e.code === 'invalid_key');
  }

  try {
    await pay.createOrder({ amount: 500, title: 'کم' });
    ok('مبلغ زیر حد رد می‌شود', false);
  } catch (e) {
    ok('مبلغ زیر حد رد می‌شود', e.code === 'invalid_amount');
  }

  try {
    await pay.getStatus('ORD-000000-XXXXX');
    ok('سفارش ناموجود 404', false);
  } catch (e) {
    ok('سفارش ناموجود 404', e.status === 404);
  }

  const o2 = await pay.createOrder({
    amount: 120000,
    title: 'تست انتظار',
    external_ref: 'NODE-2002',
  });
  await markPaid(o2.code);
  const final = await pay.waitForPayment(o2.code, { timeoutSec: 30, interval: 1 });
  ok('waitForPayment پرداخت را می‌بیند', final.paid === true);

  try {
    const dead = new Connect({ baseUrl: 'http://127.0.0.1:9', keyId: 'x', secret: 'y', timeout: 2000 });
    await dead.getStatus('X');
    ok('خطای شبکه ConnectError', false);
  } catch (e) {
    ok('خطای شبکه ConnectError', e.code === 'network_error');
  }

  console.log(`\nنتیجه: ${pass} PASS · ${fail} FAIL`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
  console.error('CRASH', e);
  process.exit(1);
});

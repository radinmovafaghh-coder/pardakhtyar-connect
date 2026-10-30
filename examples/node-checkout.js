/**
 * نمونهٔ کامل: اتصال یک فروشگاه Node.js به پرداختیار
 *
 * اجرا:  PAY_KEY_ID=ak_... PAY_SECRET=... node node-checkout.js
 */
'use strict';

const http = require('http');
const { Connect, ConnectError } = require('../sdk/node/connect.js');

const pay = new Connect({
  baseUrl: process.env.PAY_BASE_URL || 'https://pay.lenvato.ir',
  keyId: process.env.PAY_KEY_ID || '',
  secret: process.env.PAY_SECRET || '',
});

// ── ۱) ساخت سفارش ───────────────────────────────────────────
async function startCheckout(amountToman, invoiceId, customer = {}) {
  try {
    const order = await pay.createOrder({
      amount: amountToman,
      title: `سفارش ${invoiceId}`,
      external_ref: invoiceId,
      customer_name: customer.name,
      customer_mobile: customer.mobile,
    });

    // سفارش را در دیتابیس خودتان ذخیره کنید
    // await db.orders.insert({ code: order.code, invoiceId, amount: order.amount_rial });

    return order.pay_url;
  } catch (e) {
    if (e instanceof ConnectError) {
      console.error('خطای پرداختیار:', e.code, e.message);
    }
    throw e;
  }
}

// ── ۲) بررسی پرداخت ─────────────────────────────────────────
async function checkPayment(orderCode) {
  try {
    const st = await pay.getStatus(orderCode);
    return st.paid === true;
  } catch (e) {
    console.error('خطای استعلام:', e.message);
    return false;
  }
}

// ── ۳) سرور نمونه ───────────────────────────────────────────
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');

  if (url.pathname === '/buy') {
    const invoiceId = `INV-${Date.now()}`;
    const payUrl = await startCheckout(250000, invoiceId, { name: 'مشتری' });
    res.writeHead(302, { Location: payUrl });
    return res.end();
  }

  if (url.pathname === '/return') {
    const code = url.searchParams.get('order');
    const paid = code ? await checkPayment(code) : false;
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(
      paid
        ? '<h1>پرداخت تأیید شد</h1>'
        : '<h1>در انتظار تأیید واریز</h1>'
    );
  }

  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end('<a href="/buy">خرید آزمایشی</a>');
});

if (require.main === module) {
  const port = process.env.PORT || 3000;
  server.listen(port, () => console.log(`http://localhost:${port}`));
}

module.exports = { startCheckout, checkPayment };

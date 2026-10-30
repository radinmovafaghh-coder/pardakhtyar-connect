const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const WebSocket = require('/root/projects/node_modules/ws');

const URL = process.argv[2] || 'http://127.0.0.1:8978/';
const OUT = process.argv[3] || '/tmp/pc_shot.png';
const WIDTH = parseInt(process.argv[4] || '1440', 10);
const HEIGHT = parseInt(process.argv[5] || '900', 10);
const FULL = process.argv[6] === 'full';

function get(pathname) {
  return new Promise((res, rej) => {
    const req = require('http').get(
      { host: '127.0.0.1', port: 9222, path: pathname },
      (r) => {
        let d = '';
        r.on('data', (c) => (d += c));
        r.on('end', () => res(JSON.parse(d)));
      }
    );
    req.on('error', rej);
  });
}

function put(pathname) {
  return new Promise((res, rej) => {
    const req = require('http').request(
      { host: '127.0.0.1', port: 9222, path: pathname, method: 'PUT' },
      (r) => {
        let d = '';
        r.on('data', (c) => (d += c));
        r.on('end', () => res(JSON.parse(d)));
      }
    );
    req.on('error', rej);
    req.end();
  });
}

(async () => {
  const tab = await put('/json/new?about:blank');
  const ws = new WebSocket(tab.webSocketDebuggerUrl, { perMessageDeflate: false });
  let id = 0;
  const pending = new Map();

  const send = (method, params = {}) =>
    new Promise((resolve, reject) => {
      const mid = ++id;
      pending.set(mid, { resolve, reject });
      ws.send(JSON.stringify({ id: mid, method, params }));
    });

  ws.on('message', (raw) => {
    const msg = JSON.parse(raw);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result);
    }
  });

  await new Promise((r) => ws.on('open', r));

  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: WIDTH,
    height: HEIGHT,
    deviceScaleFactor: 2,
    mobile: WIDTH < 700,
  });

  await send('Page.navigate', { url: URL });
  await new Promise((r) => setTimeout(r, 2500));

  // ارتفاع کامل
  const { result } = await send('Runtime.evaluate', {
    expression: 'document.documentElement.scrollHeight',
    returnByValue: true,
  });
  const fullH = result.value;

  const shot = await send('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: FULL,
    clip: FULL
      ? { x: 0, y: 0, width: WIDTH, height: Math.min(fullH, 12000), scale: 1 }
      : undefined,
  });

  fs.writeFileSync(OUT, Buffer.from(shot.data, 'base64'));
  console.log('saved', OUT, 'height', fullH);

  await send('Page.close').catch(() => {});
  ws.close();
  process.exit(0);
})().catch((e) => {
  console.error('ERR', e.message);
  process.exit(1);
});

import { createServer } from 'node:http';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, normalize, extname } from 'node:path';
import { randomBytes } from 'node:crypto';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const PUBLIC_DIR = join(root, 'public');
const STATE_FILE = join(root, 'tests', '.mock-state.json');
const PORT = Number(process.env.PORT || 8899);
const PASSWORD = process.env.MOCK_PASSWORD || 'mock-pass-2026';
const FRESH = process.argv.includes('--fresh');

let revision = 0;
let data = { vehicles: [], items: [], nurses: [], checks: [], drafts: [], metadata: [], restocks: [] };

if (!FRESH && existsSync(STATE_FILE)) {
  const saved = JSON.parse(readFileSync(STATE_FILE, 'utf8'));
  revision = saved.revision;
  data = saved.data;
}

// Mock-only staff records (clearly fake, for testing the check flow).
if (!data.nurses.length) {
  data.nurses = [
    { id: 'MOCK-01', name: 'ทดสอบ พยาบาลหนึ่ง', dept: 'ER', phone: '', start: '2026-01-05', end: '2026-07-05', note: 'ข้อมูลจำลองเพื่อทดสอบ', active: true },
    { id: 'MOCK-02', name: 'ทดสอบ พยาบาลสอง', dept: 'Refer', phone: '', start: '2026-03-01', end: '2026-09-01', note: '', active: true },
  ];
}

const sessions = new Set();
const save = () => writeFileSync(STATE_FILE, JSON.stringify({ revision, data }, null, 2));
if (FRESH) save();

const MIME = { '.html': 'text/html; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.ico': 'image/x-icon' };

function send(res, status, obj, headers = {}) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers });
  res.end(JSON.stringify(obj));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let b = '';
    req.on('data', (c) => {
      b += c;
      if (b.length > 5e7) { reject(new Error('payload too large')); req.destroy(); }
    });
    req.on('end', () => {
      try { resolve(b ? JSON.parse(b) : {}); } catch (e) { reject(e); }
    });
  });
}

function sidOf(req) {
  const m = (req.headers.cookie || '').match(/(?:^|;\s*)sid=([^;]+)/);
  return m ? m[1] : null;
}

function authed(req) {
  const sid = sidOf(req);
  return !!(sid && sessions.has(sid));
}

function serveStatic(req, res, pathname) {
  const rel = pathname === '/' ? 'index.html' : pathname.replace(/^\/+/, '');
  const file = normalize(join(PUBLIC_DIR, rel));
  if (!file.startsWith(PUBLIC_DIR) || !existsSync(file)) {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('not found');
  }
  const body = readFileSync(file);
  res.writeHead(200, { 'Content-Type': MIME[extname(file).toLowerCase()] || 'application/octet-stream', 'Content-Length': body.length, 'Cache-Control': 'no-store' });
  res.end(req.method === 'HEAD' ? undefined : body);
}

const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const action = url.searchParams.get('action') || '';
  const tag = `${req.method} ${url.pathname}${url.search}`;

  try {
    if (url.pathname === '/api.php') {
      if (action === 'login' && req.method === 'POST') {
        const body = await readBody(req);
        if (body.password !== PASSWORD) { console.log(tag + ' -> 401'); return send(res, 401, { error: 'unauthorized' }); }
        const sid = randomBytes(16).toString('hex');
        sessions.add(sid);
        console.log(tag + ' -> 200 (session ' + sid.slice(0, 8) + ')');
        return send(res, 200, { ok: true }, { 'Set-Cookie': `sid=${sid}; Path=/; HttpOnly; SameSite=Strict` });
      }
      if (action === 'logout' && req.method === 'POST') {
        const sid = sidOf(req);
        if (sid) sessions.delete(sid);
        console.log(tag + ' -> 200');
        return send(res, 200, { ok: true });
      }
      if (action === 'state' && req.method === 'GET') {
        if (!authed(req)) { console.log(tag + ' -> 401'); return send(res, 401, { error: 'unauthorized' }); }
        console.log(tag + ` -> 200 (rev ${revision}, ${data.items.length} items, ${data.drafts.length} drafts, ${data.checks.length} checks, ${(data.restocks || []).length} restocks)`);
        return send(res, 200, { revision, data });
      }
      if (action === 'state' && req.method === 'PUT') {
        if (!authed(req)) { console.log(tag + ' -> 401'); return send(res, 401, { error: 'unauthorized' }); }
        const body = await readBody(req);
        if (body.revision !== revision) { console.log(`${tag} rev ${body.revision} vs ${revision} -> 409`); return send(res, 409, { error: 'conflict', revision }); }
        data = body.data;
        revision += 1;
        save();
        console.log(tag + ` -> 200 (rev ${revision})`);
        return send(res, 200, { revision });
      }
      console.log(tag + ' -> 400');
      return send(res, 400, { error: 'bad request' });
    }

    if (req.method === 'GET' || req.method === 'HEAD') return serveStatic(req, res, url.pathname);
    res.writeHead(405, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('method not allowed');
  } catch (e) {
    console.log(tag + ' -> 500 ' + e.message);
    send(res, 500, { error: e.message });
  }
});

server.listen(PORT, () => {
  console.log(`mock api.php listening on http://localhost:${PORT}/`);
  console.log(`password: ${PASSWORD} (override with MOCK_PASSWORD)`);
  console.log(`state file: ${STATE_FILE}${FRESH ? ' (fresh)' : ''}`);
});

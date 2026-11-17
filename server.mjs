import http from 'node:http';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdirSync, readFileSync, writeFileSync, renameSync, statSync, createReadStream } from 'node:fs';
import { randomUUID, randomBytes, createHash, timingSafeEqual } from 'node:crypto';

const root = path.dirname(fileURLToPath(import.meta.url));
const web = path.join(root, 'public');
const data = path.join(root, 'data');
const catalogFile = path.join(data, 'catalog.json');
const photos = path.join(web, 'uploads');
const seed = JSON.parse(readFileSync(path.join(root, 'catalog.seed.json'), 'utf8'));
const password = process.env.FLORIEL_PASSWORD || 'floriel-demo';
const production = process.env.NODE_ENV === 'production';
if (production && password === 'floriel-demo') throw new Error('Set FLORIEL_PASSWORD before starting production.');
const sessions = new Map();
const port = Number(process.env.PORT || 4173);
const lifetime = 4 * 60 * 60;
const maxImage = 5 * 1024 * 1024;
const maxBody = 7 * 1024 * 1024;
mkdirSync(data, { recursive: true });
mkdirSync(photos, { recursive: true });

const mime = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8', '.jpg': 'image/jpeg', '.png': 'image/png',
  '.webp': 'image/webp', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
const imageTypes = {
  'image/jpeg': { ext: 'jpg', valid: b => b.length >= 3 && b[0] === 255 && b[1] === 216 && b[2] === 255 },
  'image/png': { ext: 'png', valid: b => b.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex')) },
  'image/webp': { ext: 'webp', valid: b => b.toString('ascii', 0, 4) === 'RIFF' && b.toString('ascii', 8, 12) === 'WEBP' }
};
const hash = value => createHash('sha256').update(value).digest();
const fail = (status, message) => Object.assign(new Error(message), { status });
const text = value => typeof value === 'string' ? value.trim() : '';

function readCatalog() {
  try { return JSON.parse(readFileSync(catalogFile, 'utf8')); }
  catch (error) {
    if (error.code === 'ENOENT') return structuredClone(seed.products);
    throw error;
  }
}

function saveCatalog(products) {
  const temporary = catalogFile + '.tmp';
  writeFileSync(temporary, JSON.stringify(products, null, 2) + '\n', 'utf8');
  renameSync(temporary, catalogFile);
}

function json(res, status, value) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(value));
}

async function readJson(req, limit = maxBody, limitMessage = 'Фотография слишком большая.') {
  if (!req.headers['content-type']?.startsWith('application/json')) throw fail(415, 'Ожидается JSON.');
  if (Number(req.headers['content-length']) > limit) throw fail(413, limitMessage);
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > limit) throw fail(413, limitMessage);
    chunks.push(chunk);
  }
  try {
    const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    if (input && typeof input === 'object' && !Array.isArray(input)) return input;
  } catch {}
  throw fail(400, 'Не удалось прочитать данные.');
}

function session(req) {
  const token = req.headers.cookie?.match(/(?:^|;\s*)floriel_session=([0-9a-f]{64})(?:;|$)/)?.[1];
  const expiry = sessions.get(token);
  if (expiry > Date.now()) return token;
  if (token) sessions.delete(token);
  return null;
}

function sessionCookie(res, token = '', maxAge = 0) {
  res.setHeader('Set-Cookie', 'floriel_session=' + token + '; Path=/; HttpOnly; SameSite=Strict; Max-Age=' + maxAge + (production ? '; Secure' : ''));
}

function newImage(photo) {
  if (typeof photo !== 'string') throw fail(400, 'Выбери фотографию.');
  const parts = /^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$/.exec(photo);
  if (!parts) throw fail(400, 'Подойдут фотографии JPEG, PNG и WebP.');
  const type = imageTypes[parts[1]];
  const bytes = Buffer.from(parts[2], 'base64');
  if (!type.valid(bytes)) throw fail(400, 'Не удалось прочитать фотографию.');
  if (bytes.length > maxImage) throw fail(413, 'Максимальный размер фотографии — 5 МБ.');
  const filename = randomUUID() + '.' + type.ext;
  writeFileSync(path.join(photos, filename), bytes, { flag: 'wx' });
  return '/uploads/' + filename;
}

function editCatalog(input, id, method) {
  const products = readCatalog();
  const index = products.findIndex(item => item.id === id);
  const previous = products[index];
  if ((method === 'PUT' || method === 'DELETE') && index < 0) throw fail(404, 'Этот букет уже удалён.');
  if (method === 'DELETE') {
    products.splice(index, 1);
  } else {
    const name = text(input.name), composition = text(input.composition);
    const category = text(input.category), price = Number(input.price);
    if (!name || name.length > 80 || !composition || composition.length > 500 ||
        !seed.categories.includes(category) || !Number.isInteger(price) || price < 1 || price > 1000000) {
      throw fail(400, 'Проверь название, состав, категорию и цену.');
    }
    const image = input.photo ? newImage(input.photo) : previous?.image;
    if (!image) throw fail(400, 'Добавь фотографию букета.');
    const saved = { ...previous, id: previous?.id || randomUUID(), name, composition, category, price, image,
      inCatalog: input.inCatalog === true };
    if (previous?.category !== category) delete saved.tag;
    if (previous) products[index] = saved;
    else products.push(saved);
  }
  saveCatalog(products);
  return products;
}

const maxFormBody = 256 * 1024;
const maxConcurrentForms = 6;
let activeForms = 0;

function sendForm(kind, input) {
  const unavailable = () => fail(503, 'Отправка пока недоступна. Позвоните в салон по номеру +7 993 605 0483.');
  if (activeForms >= maxConcurrentForms) throw unavailable();
  activeForms += 1;

  return new Promise((resolve, reject) => {
    const handler = execFile(process.env.FLORIEL_PHP_BIN || 'php', [
      '-d', 'display_errors=0', path.join(root, 'backend', 'notify.php'), kind
    ], { timeout: 20000, maxBuffer: 32768, windowsHide: true }, (error, stdout, stderr) => {
      activeForms -= 1;
      if (error) {
        console.error('PHP form handler failed:', error.code || error.signal);
        return reject(unavailable());
      }
      if (stderr) console.error(stderr.trim());
      try {
        const result = JSON.parse(stdout);
        if (![200, 400, 404, 409, 422, 500, 503].includes(result.status) || !result.body) throw new Error();
        return resolve(result);
      } catch {
        console.error('PHP form handler returned an invalid response');
        return reject(unavailable());
      }
    });
    handler.stdin.on('error', () => {});
    handler.stdin.end(JSON.stringify(input));
  });
}

const server = http.createServer(async (req, res) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  try {
    const route = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
    const method = req.method;
    if (route === '/api/orders' || route === '/api/feedback') {
      if (method !== 'POST') {
        res.setHeader('Allow', 'POST');
        throw fail(405, 'Метод не поддерживается.');
      }
      if (req.headers['x-floriel-request'] !== 'form') throw fail(403, 'Запрос отклонён.');
      const input = await readJson(req, maxFormBody, 'Форма слишком большая.');
      const result = await sendForm(route.slice('/api/'.length), input);
      return json(res, result.status, result.body);
    }
    const currentSession = session(req);
    if (route.startsWith('/api/')) {
      if (method !== 'GET' && req.headers['x-floriel-request'] !== 'editor') throw fail(403, 'Запрос отклонён.');
      if (route === '/api/catalog' && method === 'GET') {
        const products = readCatalog();
        return json(res, 200, { editor: Boolean(currentSession),
          products: currentSession ? products : products.filter(p => p.inCatalog !== false) });
      }
      if (route === '/api/login' && method === 'POST') {
        const input = await readJson(req);
        if (!timingSafeEqual(hash(text(input.password)), hash(password))) throw fail(401, 'Пароль не подошёл.');
        for (const [key, expiry] of sessions) if (expiry < Date.now() || key === currentSession) sessions.delete(key);
        const token = randomBytes(32).toString('hex');
        sessions.set(token, Date.now() + lifetime * 1000);
        sessionCookie(res, token, lifetime);
        return json(res, 200, { editor: true, products: readCatalog() });
      }
      if (!currentSession) throw fail(401, 'Войди в режим флориста.');
      if (route === '/api/logout' && method === 'POST') {
        sessions.delete(currentSession);
        sessionCookie(res);
        return json(res, 200, { editor: false, products: readCatalog().filter(p => p.inCatalog !== false) });
      }
      const bouquet = /^\/api\/bouquets(?:\/([a-zA-Z0-9-]+))?$/.exec(route);
      const id = bouquet?.[1];
      if ((bouquet && !id && method === 'POST') || (id && ['PUT', 'DELETE'].includes(method))) {
        const input = method === 'DELETE' ? null : await readJson(req);
        if (!session(req)) throw fail(401, 'Войди в режим флориста.');
        return json(res, 200, { editor: true, products: editCatalog(input, id, method) });
      }
      throw fail(404, 'Команда не найдена.');
    }
    if (!['GET', 'HEAD'].includes(method)) throw fail(405, 'Метод не поддерживается.');
    const asset = path.resolve(web, '.' + (['/', '/admin', '/admin/'].includes(route) ? '/index.html' : route));
    if (!asset.startsWith(web + path.sep)) throw fail(404, 'Страница не найдена.');
    const format = mime[path.extname(asset)];
    if (!format) throw fail(404, 'Страница не найдена.');
    const size = statSync(asset).size;
    res.writeHead(200, { 'Content-Type': format, 'Content-Length': size,
      'Cache-Control': path.extname(asset) === '.html' ? 'no-store' : 'public, max-age=300' });
    if (method === 'HEAD') return res.end();
    createReadStream(asset).on('error', () => res.destroy()).pipe(res);
  } catch (error) {
    const status = error.status || (error.code === 'ENOENT' ? 404 : 500);
    if (status === 500) console.error(error);
    json(res, status, { error: error.status ? error.message : status === 404 ? 'Страница не найдена.' : 'Не удалось выполнить запрос.' });
  }
});

server.listen(port, '127.0.0.1', () => console.log('Флориэль: http://127.0.0.1:' + server.address().port + '/admin'));

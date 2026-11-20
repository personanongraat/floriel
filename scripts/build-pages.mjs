import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const source = path.join(root, 'public_html');
const output = path.join(root, '_site');
const base = process.env.PAGES_BASE_PATH || '/floriel/';
if (!/^\/(?:[A-Za-z0-9_-]+\/)*$/.test(base)) throw new Error('Invalid Pages base path');

await rm(output, { recursive: true, force: true });
await mkdir(output, { recursive: true });
// Publish only public assets. PHP, configuration, orders and uploaded files stay out.
for (const name of ['assets', 'fonts', 'photos', 'hero-fonts.css', 'florist.css']) {
  await cp(path.join(source, name), path.join(output, name), { recursive: true });
}
let html = await readFile(path.join(source, 'index.html'), 'utf8');
function replaceRequired(pattern, replacement) {
  const next = html.replace(pattern, replacement);
  if (next === html) throw new Error('Storefront changed; review Pages transformation: ' + pattern);
  html = next;
}
replaceRequired(/<script src="\/florist\.js"><\/script>/,
  '<script>function installFlorist(){return {editing:()=>false,controls:()=>\'\'};}</script>');
replaceRequired(/<script>\s*async function sendFlorielForm[\s\S]*?<\/script>/,
  '<script>window.orderAdapter={submit:async()=>{throw Error("Это демонстрация. Заказы принимаются по телефону.");},feedback:async()=>{throw Error("Отправка доступна на основном сайте.");}};</script>');
replaceRequired(/ const image = id=>[^\r\n]+;/,
  ' const image = id => ' + JSON.stringify(base) + ' + (String(id).includes("photos/") ? String(id).replace(/^.*?photos\\//, "photos/") : "assets/images/" + id + ".jpg");');
html = html.replace(/(["'(])\/(?=(?:photos|assets|fonts)\/|(?:hero-fonts|florist)\.(?:css|js))/g, '$1' + base);
replaceRequired('Отправляя заказ, вы передаёте данные мастерской для его обработки.',
  'Демонстрационная версия. Отправка заявок появится после размещения на PHP-хостинге. Заказать сейчас: <a href="tel:+79936050483">+7 (993) 605-04-83</a>.');
replaceRequired('<button class="button full" type="submit">Отправить заказ</button>',
  '<button class="button full" type="submit" disabled>Отправка пока недоступна</button>');
replaceRequired('</head>', '<style>#florist-bar,#florist-dialog{display:none}</style></head>');
await writeFile(path.join(output, 'index.html'), html);
await writeFile(path.join(output, '.nojekyll'), '');
console.log('Pages storefront ready:', output, 'base:', base);

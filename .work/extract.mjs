import { readFileSync, writeFileSync } from 'node:fs';

const src = readFileSync('C:/Users/PC63-0092/Desktop/sea/public/index.html', 'utf8');
const lines = src.split('\n');

// 1) Extract checklist config (lines 388-453, 1-based)
const configLines = lines.slice(387, 453);
writeFileSync('C:/Users/PC63-0092/Desktop/sea/emer/.work/config-lines.txt', configLines.join('\n'), 'utf8');
console.log('config lines:', configLines.length);
console.log('first:', configLines[0].trim().slice(0, 80));
console.log('last:', configLines[configLines.length - 1].trim().slice(0, 80));

// count isBattery and names
const joined = configLines.join('\n');
const batteryCount = (joined.match(/isBattery/g) || []).length;
const nameCount = (joined.match(/name:/g) || []).length;
console.log('items:', nameCount, 'battery:', batteryCount);

// 2) Extract all data URLs with context
const dataUrlRe = /data:image\/[a-z+]+;base64,[A-Za-z0-9+/=]+/g;
let m, i = 0;
const found = [];
while ((m = dataUrlRe.exec(src)) !== null) {
  const url = m[0];
  // context: 120 chars before
  const ctx = src.slice(Math.max(0, m.index - 150), m.index).replace(/\n/g, ' ');
  found.push({ i: i++, len: url.length, ctx: ctx.slice(-120), head: url.slice(0, 40) });
}
console.log('dataURLs found:', found.length);
for (const f of found) console.log(f.i, 'len=' + f.len, '|', f.head, '| ctx:', f.ctx.slice(-80));

// 3) Write each distinct data url to file for later injection
const uniq = [];
for (const f of found) {
  const m2 = new RegExp('data:image\\/[a-z+]+;base64,[A-Za-z0-9+/=]+', 'g');
  void m2;
}
// simpler: re-scan and store each occurrences' url
const urls = [...src.matchAll(dataUrlRe)].map(x => x[0]);
writeFileSync('C:/Users/PC63-0092/Desktop/sea/emer/.work/dataurls.json', JSON.stringify(urls.map(u => ({ len: u.length, prefix: u.slice(0, 30), sha: null })), null, 1));

// Save all occurrences to individual files
urls.forEach((u, idx) => writeFileSync(`C:/Users/PC63-0092/Desktop/sea/emer/.work/img-${idx}.txt`, u, 'utf8'));

// 4) Extract key source sections to inspect: lines 281-296 (auth/header/cars), 463-472 (boot)
const save = (a, b, name) => writeFileSync(name, lines.slice(a - 1, b).map((l, k) => `${a + k}: ${l.length > 400 ? l.slice(0, 400) + '…[CUT ' + (l.length - 800) + ']…' + l.slice(-400) : l}`).join('\n'), 'utf8');
save(281, 296, 'C:/Users/PC63-0092/Desktop/sea/emer/.work/sec-head.txt');
save(463, 473, 'C:/Users/PC63-0092/Desktop/sea/emer/.work/sec-boot.txt');
save(374, 387, 'C:/Users/PC63-0092/Desktop/sea/emer/.work/sec-tabs.txt');
console.log('done');

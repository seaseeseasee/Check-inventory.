// Build step (dev only): inject the base64 logo into the HTML template
// and emit the self-contained public/index.html used by the hospital server.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const template = readFileSync(join(here, 'index.template.html'), 'utf8');
const logo = readFileSync(join(here, 'assets', 'logo.dataurl.txt'), 'utf8').trim();

if (!logo.startsWith('data:image/png;base64,')) throw new Error('logo.dataurl.txt is not a PNG data URL');
if (!template.includes('__IMG_B64__')) throw new Error('placeholder __IMG_B64__ not found in template');

const output = template.split('__IMG_B64__').join(logo);
if (output.includes('__IMG_B64__')) throw new Error('placeholder remains after replace');

const target = join(root, 'public', 'index.html');
writeFileSync(target, output, 'utf8');

const count = output.split('data:image/png;base64,').length - 1;
console.log(`wrote ${target}`);
console.log(`bytes: ${Buffer.byteLength(output, 'utf8')}`);
console.log(`embedded data URLs: ${count}`);

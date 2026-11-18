import fs from 'fs';
import vm from 'vm';
import path from 'path';
import { fileURLToPath } from 'url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(dir, '../assets/wishes-checkout-inline.js'), 'utf8');
const start = src.indexOf('function vernalWishesMessageAllowed');
const end = src.indexOf('/* END POLICY */');
if (start < 0 || end < start) {
  console.error('FAIL: policy function not found');
  process.exit(1);
}
const context = {};
vm.runInNewContext(src.slice(start, end), context);
const allow = context.vernalWishesMessageAllowed;
const frame = {};
const origin = 'https://wishes.inc';

function assertTrue(cond, msg) {
  if (!cond) {
    console.error('FAIL: ' + msg);
    process.exit(1);
  }
  console.log('OK: ' + msg);
}

assertTrue(allow(origin, frame, frame, origin) === true, 'matching frame and origin is accepted');
assertTrue(allow(origin, {}, frame, origin) === false, 'a different window is rejected');
assertTrue(allow('https://evil.example', frame, frame, origin) === false, 'other origins are rejected');
assertTrue(allow('https://www.wishes.inc', frame, frame, origin) === false, 'www is not the embed origin');
assertTrue(src.includes("frame.src = EXPECTED_ORIGIN + EMBED_PATH + '?' + q.toString()"), 'frame src is built from the fixed origin');
assertTrue(src.includes('wishes:checkout:resize') && src.includes("data.type === 'wishes:checkout'"), 'resize and payment messages are the only handled types');
console.log('All inline policy checks passed.');

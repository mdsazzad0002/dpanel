const sfc = require('@vue/compiler-sfc');
const fs = require('fs');
const glob = require('glob');
const files = glob.sync('resources/js/Pages/AiGateway/**/*.vue', { cwd: process.cwd() });

function propKeys(src) {
  const keys = [];
  const re = /defineProps\s*\(/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    let i = m.index + m[0].length;
    while (i < src.length && /\s/.test(src[i])) i++;
    if (src[i] !== '{') continue;
    let depth = 1; // outer brace already seen
    let j = i + 1;
    for (; j < src.length; j++) {
      const c = src[j];
      if (c === '{') depth++;
      else if (c === '}') { depth--; if (depth === 0) { break; } }
    }
    const objText = src.slice(i + 1, j);
    for (const line of objText.split('\n')) {
      const lk = line.trim();
      if (!lk || lk.startsWith('//') || lk.startsWith('*')) continue;
      const mk = lk.match(/^([A-Za-z_$][\w$]*)\s*[:=]/);
      if (mk) keys.push(mk[1]);
    }
  }
  return [...new Set(keys)];
}

function scriptSetupSrc(src) {
  const m = src.match(/<script[^>]*setup[^>]*>([\s\S]*?)<\/script>/);
  return m ? m[1] : src;
}

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const { errors } = sfc.parse(src);
  const keys = propKeys(src);
  const body = scriptSetupSrc(src);
  if (errors.length) { console.log('## ' + f + ' :: PARSE ERRORS'); continue; }
  const risky = [];
  const lines = body.split('\n');
  for (let idx = 0; idx < lines.length; idx++) {
    const line = lines[idx];
    if (!/^\s*(const|let|var)\s+/.test(line)) continue;
    for (const k of keys) {
      if (/^\s*(const|let|var)\s+/.test(line) && new RegExp('^\\s*(const|let|var)\\s+' + k).test(line)) continue; // declaring it
      const re = new RegExp('(^|[^\\w$.])' + k + '($|[^\\w$.\\)])', 'g');
      let mt;
      while ((mt = re.exec(line)) !== null) {
        risky.push(f + ':' + (idx + 1) + ' [' + k + '] ' + line.trim().slice(0, 90));
      }
    }
  }
  console.log('## ' + f);
  console.log('   props: ' + (keys.length ? keys.join(', ') : '(none parsed)'));
  console.log('   risky top-level bare-prop refs: ' + (risky.length ? risky.join(' | ') : 'NONE'));
}

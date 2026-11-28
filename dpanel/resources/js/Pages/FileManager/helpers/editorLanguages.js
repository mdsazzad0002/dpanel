// Language support for the code editor, resolved from a file name.
//
// Every loader is a dynamic import, so a session that only ever opens PHP
// files never downloads the Python or SQL grammar. The editor itself is
// already a lazy chunk (see FileEditor.vue); this keeps that chunk small
// too. (@codemirror/language is the one static import here: the editor chunk
// already carries it, so deferring it would only split a module that is
// loaded either way.)
import { StreamLanguage } from '@codemirror/language';


/** Legacy (CodeMirror 5) modes, wrapped as a stream language. */
async function legacy(loader, modeName) {
    const mod = await loader();

    return StreamLanguage.define(mod[modeName]);
}

const LOADERS = {
    js: () => import('@codemirror/lang-javascript').then((m) => m.javascript()),
    jsx: () => import('@codemirror/lang-javascript').then((m) => m.javascript({ jsx: true })),
    ts: () => import('@codemirror/lang-javascript').then((m) => m.javascript({ typescript: true })),
    tsx: () => import('@codemirror/lang-javascript').then((m) => m.javascript({ typescript: true, jsx: true })),
    php: () => import('@codemirror/lang-php').then((m) => m.php()),
    html: () => import('@codemirror/lang-html').then((m) => m.html()),
    css: () => import('@codemirror/lang-css').then((m) => m.css()),
    json: () => import('@codemirror/lang-json').then((m) => m.json()),
    markdown: () => import('@codemirror/lang-markdown').then((m) => m.markdown()),
    python: () => import('@codemirror/lang-python').then((m) => m.python()),
    sql: () => import('@codemirror/lang-sql').then((m) => m.sql()),
    xml: () => import('@codemirror/lang-xml').then((m) => m.xml()),
    yaml: () => import('@codemirror/lang-yaml').then((m) => m.yaml()),
    vue: () => import('@codemirror/lang-vue').then((m) => m.vue()),
    shell: () => legacy(() => import('@codemirror/legacy-modes/mode/shell'), 'shell'),
    nginx: () => legacy(() => import('@codemirror/legacy-modes/mode/nginx'), 'nginx'),
    properties: () => legacy(() => import('@codemirror/legacy-modes/mode/properties'), 'properties'),
    dockerfile: () => legacy(() => import('@codemirror/legacy-modes/mode/dockerfile'), 'dockerFile'),
    toml: () => legacy(() => import('@codemirror/legacy-modes/mode/toml'), 'toml'),
    lua: () => legacy(() => import('@codemirror/legacy-modes/mode/lua'), 'lua'),
    ruby: () => legacy(() => import('@codemirror/legacy-modes/mode/ruby'), 'ruby'),
    perl: () => legacy(() => import('@codemirror/legacy-modes/mode/perl'), 'perl'),
    go: () => legacy(() => import('@codemirror/legacy-modes/mode/go'), 'go'),
    rust: () => legacy(() => import('@codemirror/legacy-modes/mode/rust'), 'rust'),
    c: () => legacy(() => import('@codemirror/legacy-modes/mode/clike'), 'c'),
    cpp: () => legacy(() => import('@codemirror/legacy-modes/mode/clike'), 'cpp'),
    java: () => legacy(() => import('@codemirror/legacy-modes/mode/clike'), 'java'),
    csharp: () => legacy(() => import('@codemirror/legacy-modes/mode/clike'), 'csharp'),
    kotlin: () => legacy(() => import('@codemirror/legacy-modes/mode/clike'), 'kotlin'),
    scala: () => legacy(() => import('@codemirror/legacy-modes/mode/clike'), 'scala'),
    swift: () => legacy(() => import('@codemirror/legacy-modes/mode/swift'), 'swift'),
    powershell: () => legacy(() => import('@codemirror/legacy-modes/mode/powershell'), 'powerShell'),
    diff: () => legacy(() => import('@codemirror/legacy-modes/mode/diff'), 'diff'),
};

/** Extension → loader key. */
const BY_EXTENSION = {
    js: 'js', mjs: 'js', cjs: 'js',
    jsx: 'jsx',
    ts: 'ts', mts: 'ts', cts: 'ts',
    tsx: 'tsx',
    php: 'php', phtml: 'php', php5: 'php', php7: 'php', php8: 'php',
    html: 'html', htm: 'html', twig: 'html', hbs: 'html',
    css: 'css', scss: 'css', sass: 'css', less: 'css',
    json: 'json', jsonc: 'json', lock: 'json', webmanifest: 'json',
    md: 'markdown', markdown: 'markdown', mdx: 'markdown',
    py: 'python', pyw: 'python',
    sql: 'sql',
    xml: 'xml', svg: 'xml', xsl: 'xml', rss: 'xml', plist: 'xml',
    yml: 'yaml', yaml: 'yaml',
    vue: 'vue',
    sh: 'shell', bash: 'shell', zsh: 'shell', fish: 'shell', ksh: 'shell',
    conf: 'nginx',
    ini: 'properties', env: 'properties', cfg: 'properties', properties: 'properties',
    toml: 'toml',
    lua: 'lua',
    rb: 'ruby', rake: 'ruby',
    pl: 'perl', pm: 'perl',
    go: 'go',
    rs: 'rust',
    c: 'c', h: 'c',
    cpp: 'cpp', cc: 'cpp', cxx: 'cpp', hpp: 'cpp',
    java: 'java',
    cs: 'csharp',
    kt: 'kotlin', kts: 'kotlin',
    scala: 'scala',
    swift: 'swift',
    ps1: 'powershell', psm1: 'powershell',
    diff: 'diff', patch: 'diff',
};

/** Whole file names that carry no useful extension. */
const BY_NAME = {
    dockerfile: 'dockerfile',
    containerfile: 'dockerfile',
    makefile: 'shell',
    gemfile: 'ruby',
    rakefile: 'ruby',
    procfile: 'properties',
    '.env': 'properties',
    '.gitignore': 'properties',
    '.gitattributes': 'properties',
    '.editorconfig': 'properties',
    '.htaccess': 'nginx',
    '.bashrc': 'shell',
    '.bash_profile': 'shell',
    '.profile': 'shell',
    '.zshrc': 'shell',
};

/** The loader key for a file name, or '' when we have no grammar for it. */
export function languageKeyFor(fileName) {
    const name = String(fileName || '').trim().toLowerCase();
    if (!name) return '';

    if (BY_NAME[name]) return BY_NAME[name];

    // Blade templates are mostly markup, and the PHP grammar chokes on the
    // directives, so they read better as HTML.
    if (name.endsWith('.blade.php')) return 'html';

    // Rotated and backed-up files keep the syntax of what they came from:
    // app.log.1, config.php.bak, nginx.conf.save.
    const parts = name.split('.');
    const skippable = new Set(['bak', 'old', 'save', 'orig', 'default', 'sample', 'example', 'dist', 'tmp']);
    for (let index = parts.length - 1; index > 0; index -= 1) {
        const part = parts[index];
        if (/^\d+$/.test(part) || skippable.has(part)) continue;
        if (BY_EXTENSION[part]) return BY_EXTENSION[part];

        // An extension we have no grammar for: stop here rather than
        // reading a stem further left as the file's type.
        break;
    }

    // A dotfile with a suffix keeps the base dotfile's syntax:
    // .env.production, .bashrc.local.
    if (name.startsWith('.') && parts.length > 2) {
        const base = `.${parts[1]}`;
        if (BY_NAME[base]) return BY_NAME[base];
    }

    return '';
}

/**
 * The CodeMirror language extension for a file name. Resolves to null when
 * the type is unknown, which leaves the editor in plain-text mode.
 */
export async function loadLanguage(fileName) {
    const key = languageKeyFor(fileName);
    if (!key || !LOADERS[key]) return null;

    try {
        return await LOADERS[key]();
    } catch {
        // A grammar chunk that fails to load must not take the editor with
        // it; plain text is a usable fallback.
        return null;
    }
}

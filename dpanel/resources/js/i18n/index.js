// Panel translations (backlog U2). Pages take their text from keys in
// locales/<code>/<namespace>.json; English is the fallback, so a missing key in any
// language shows English rather than the key.
import { createI18n } from 'vue-i18n';
import { idbDelete, idbGet, idbKeys, idbSet } from '@/lib/idb';

// Each language is a folder: locales/<code>/<namespace>.json holds the keys
// under <namespace>, and locales/<code>/index.js gathers that folder into one
// module. They are imported lazily, one chunk per language: loading all five
// eagerly put 2.3 MB of JSON in the entry bundle, which every full page load
// had to download before the panel could paint. Only the language in use and
// English (the fallback) are fetched.
const loaders = {
    en: () => import('./locales/en/index.js'),
    bn: () => import('./locales/bn/index.js'),
    hi: () => import('./locales/hi/index.js'),
    zh: () => import('./locales/zh/index.js'),
    ar: () => import('./locales/ar/index.js'),
};

export const LOCALE_KEY = 'dpanel-locale';

// Where a language's messages are kept in IndexedDB. The build id is part of
// the key (vite.config.js defines it), so a release with changed translations
// is never served from an old cache.
const LOCALE_CACHE_NAMESPACE = 'i18n:';
const LOCALE_CACHE_PREFIX = `${LOCALE_CACHE_NAMESPACE}${typeof __DPANEL_BUILD_ID__ === 'string' ? __DPANEL_BUILD_ID__ : 'dev'}:`;

export const LOCALES = [
    // keywords: what someone may type in the language search to find it.
    { code: 'en', label: 'English', keywords: 'en english ইংরেজি' },
    { code: 'bn', label: 'বাংলা', keywords: 'bn bangla bengali বাংলা' },
    { code: 'hi', label: 'हिन्दी', keywords: 'hi hindi हिन्दी हिंदी' },
    { code: 'zh', label: '中文', keywords: 'zh chinese mandarin 中文 汉语' },
    // dir: Arabic is written right to left.
    { code: 'ar', label: 'العربية', keywords: 'ar arabic عربي العربية', dir: 'rtl' },
];

const known = (code) => LOCALES.some((locale) => locale.code === code);

// Sets <html lang dir> so the browser picks fonts and text direction.
const applyDocumentLocale = (code) => {
    if (typeof document === 'undefined') return;
    const dir = LOCALES.find((locale) => locale.code === code)?.dir || 'ltr';
    document.documentElement.setAttribute('lang', code);
    document.documentElement.setAttribute('dir', dir);
};

export const savedLocale = () => {
    try {
        const saved = window.localStorage.getItem(LOCALE_KEY);
        if (known(saved)) return saved;
    } catch {
        // Storage can be blocked; English then.
    }
    return 'en';
};

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: typeof window === 'undefined' ? 'en' : savedLocale(),
    fallbackLocale: 'en',
    // Filled by loadLocaleMessages() before the app mounts; a language is
    // fetched (or read from IndexedDB) the first time it is used.
    messages: {},
});

const loaded = new Set();

/**
 * Put one language's messages in place, from the IndexedDB copy when there is
 * one — a cache hit skips the chunk request entirely, so a reload shows text
 * in the chosen language without waiting on the network — otherwise from its
 * chunk, which is then cached for next time.
 */
export const loadLocaleMessages = async (code) => {
    if (! known(code) || loaded.has(code)) return;

    const cacheKey = `${LOCALE_CACHE_PREFIX}${code}`;
    let messages = await idbGet(cacheKey);

    if (! messages || typeof messages !== 'object') {
        const module = await loaders[code]();
        messages = module.default ?? module;
        // Fire and forget: a failed write only means a fetch next time.
        idbSet(cacheKey, messages);
    }

    i18n.global.setLocaleMessage(code, messages);
    loaded.add(code);
};

/** Translation bundles cached by an earlier build are of no use now. */
const pruneStaleLocaleCache = async () => {
    const keys = await idbKeys();

    await Promise.all(
        keys
            .filter((key) => key.startsWith(LOCALE_CACHE_NAMESPACE) && ! key.startsWith(LOCALE_CACHE_PREFIX))
            .map((key) => idbDelete(key)),
    );
};

/** The language in use plus English, the fallback. Awaited before mounting. */
export const initI18n = async () => {
    const code = i18n.global.locale.value;

    await Promise.all([
        loadLocaleMessages('en'),
        code === 'en' ? Promise.resolve() : loadLocaleMessages(code),
    ]);

    applyDocumentLocale(code);

    // After the first paint's worth of work, not before it.
    pruneStaleLocaleCache();
};

export const setLocale = async (code) => {
    if (! known(code)) return;

    await loadLocaleMessages(code);
    i18n.global.locale.value = code;
    applyDocumentLocale(code);
    try {
        window.localStorage.setItem(LOCALE_KEY, code);
    } catch {
        // The choice then lasts until reload.
    }
};

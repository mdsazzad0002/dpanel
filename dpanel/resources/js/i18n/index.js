// Panel translations (backlog U2). New pages take their text from keys in
// locales/<code>.json; English is the fallback, so a missing key in any language
// shows English rather than the key.
import { createI18n } from 'vue-i18n';
import en from './locales/en.json';
import bn from './locales/bn.json';
import hi from './locales/hi.json';
import zh from './locales/zh.json';
import ar from './locales/ar.json';

export const LOCALE_KEY = 'dpanel-locale';

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
    messages: { en, bn, hi, zh, ar },
});

applyDocumentLocale(i18n.global.locale.value);

export const setLocale = (code) => {
    if (!known(code)) return;
    i18n.global.locale.value = code;
    applyDocumentLocale(code);
    try {
        window.localStorage.setItem(LOCALE_KEY, code);
    } catch {
        // The choice then lasts until reload.
    }
};

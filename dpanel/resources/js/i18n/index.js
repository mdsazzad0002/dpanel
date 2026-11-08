// Panel translations (backlog U2). New pages take their text from keys in
// locales/<code>.json; English is the fallback, so a missing Bangla key
// shows English rather than the key.
import { createI18n } from 'vue-i18n';
import en from './locales/en.json';
import bn from './locales/bn.json';

export const LOCALE_KEY = 'dpanel-locale';

export const LOCALES = [
    { code: 'en', label: 'English' },
    { code: 'bn', label: 'বাংলা' },
];

const known = (code) => LOCALES.some((locale) => locale.code === code);

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
    messages: { en, bn },
});

export const setLocale = (code) => {
    if (!known(code)) return;
    i18n.global.locale.value = code;
    document.documentElement.setAttribute('lang', code);
    try {
        window.localStorage.setItem(LOCALE_KEY, code);
    } catch {
        // The choice then lasts until reload.
    }
};

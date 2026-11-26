import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router, usePage } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { describePage, trackLinkClicks } from './composables/usePageUsage';
import { i18n, initI18n } from './i18n';
import { usePanelStore } from './stores/panel';

// The tab title follows Settings → Branding (shared as `branding.name`).
const currentPage = usePage();
const appName = () => currentPage.props?.branding?.name || 'dPanel';
const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const THEME_KEY = 'dpanel-theme';

const applyInitialTheme = () => {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const saved = window.localStorage.getItem(THEME_KEY);
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const mode = saved === 'dark' || saved === 'light' ? saved : (prefersDark ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', mode === 'dark');
};

applyInitialTheme();

const registerServiceWorker = () => {
    // Skipped under the Vite dev server so the worker never sits in front of HMR.
    if (import.meta.env.DEV || typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((registration) => {
                // Pull the rest of the compiled bundles into the cache once
                // this page has what it needs, so opening any other section
                // (or reloading with no connection) needs no download. The
                // worker skips this on a metered or very slow connection.
                const warm = () => (registration.active ?? navigator.serviceWorker.controller)
                    ?.postMessage({ type: 'warm-build-assets' });

                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(warm, { timeout: 5000 });
                } else {
                    window.setTimeout(warm, 2000);
                }
            })
            .catch(() => {});
    });
};

registerServiceWorker();

// The <meta> CSRF token and Ziggy's dpt default are rendered once with the
// HTML, but login/logout happen as Inertia visits that rotate both. Refresh
// them from every page's props so fetch() callers never send a stale token.
const syncSessionTokens = (pageProps) => {
    const csrfToken = pageProps?.csrf_token;
    if (csrfToken) {
        document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', csrfToken);
    }

    const panelToken = pageProps?.panel?.token;
    if (window.Ziggy && panelToken) {
        window.Ziggy.defaults = { ...(window.Ziggy.defaults || {}), token: panelToken };
    }
};

// Settings → Branding colour as --brand-color on <html>; app.css paints the
// scrollbars with it. Blade sets it for the first paint, this keeps it current.
const applyBrandColor = (pageProps) => {
    if (typeof document === 'undefined') return;
    const color = pageProps?.branding?.color;
    if (color) {
        document.documentElement.style.setProperty('--brand-color', color);
    } else {
        document.documentElement.style.removeProperty('--brand-color');
    }
};

// Quick Access ranks tools by link clicks, counted per user in this browser only.
let currentUserId = null;

router.on('navigate', (event) => {
    const page = event.detail.page;
    syncSessionTokens(page.props);
    applyBrandColor(page.props);
    currentUserId = page.props?.auth?.user?.id ?? null;

    // <Head> sets document.title after the page renders, so read it a moment later.
    const userId = currentUserId;
    const subject = page.props?.website?.domain ?? page.props?.databaseRequest?.database_name ?? null;
    window.setTimeout(() => {
        const title = document.title.replace(new RegExp(` - ${escapeRegExp(appName())}$`), '').trim();
        describePage(userId, page.url, title, subject);
    }, 150);
});

if (typeof document !== 'undefined') {
    trackLinkClicks(router, () => currentUserId);
}

const pinia = createPinia();

const startApp = () => createInertiaApp({
    title: (title) => (title ? `${title} - ${appName()}` : appName()),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const panelToken = props?.initialPage?.props?.panel?.token;
        if (typeof window !== 'undefined' && window.Ziggy && panelToken) {
            window.Ziggy.defaults = {
                ...(window.Ziggy.defaults || {}),
                token: panelToken,
            };
        }

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);

        // What this browser remembers (theme, language, collapsed menus),
        // read after mounting so the first paint waits for nothing.
        usePanelStore(pinia).hydrate();

        return app;
    },
    progress: {
        color: '#4B5563',
    },
});

// The language in use is loaded before mounting — the panel would otherwise
// paint its keys instead of its text. It is one small chunk, or an IndexedDB
// read on a repeat visit; English (the fallback) comes with it.
initI18n().finally(startApp);

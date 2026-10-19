import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = 'dPanel';
const THEME_KEY = 'serverpanel-theme';

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
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
};

registerServiceWorker();

// The <meta> CSRF token and Ziggy's cpsess default are rendered once with the
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

router.on('navigate', (event) => syncSessionTokens(event.detail.page.props));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
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

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

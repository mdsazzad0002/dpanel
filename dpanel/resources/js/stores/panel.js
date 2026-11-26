import { defineStore } from 'pinia';
import { idbGet, idbSet } from '@/lib/idb';
import { LOCALES, i18n, setLocale } from '@/i18n';

const STORAGE_KEY = 'panel-state:v1';
const THEME_KEY = 'dpanel-theme';

/**
 * The panel's own client state — what the browser remembers about this user
 * between visits, rather than anything the server owns.
 *
 * It lives in IndexedDB (see lib/idb.js) and is hydrated once at boot; the
 * theme is also mirrored to localStorage, because app.js and the Blade shell
 * read it synchronously before any of this runs, to paint the first frame in
 * the right colours.
 */
export const usePanelStore = defineStore('panel', {
    state: () => ({
        theme: 'light',
        locale: i18n.global.locale.value,
        /** Sidebar groups the user collapsed, by group id. */
        collapsedGroups: [],
        hydrated: false,
    }),

    getters: {
        isDark: (state) => state.theme === 'dark',
        localeLabel: (state) => LOCALES.find((locale) => locale.code === state.locale)?.label ?? state.locale,
    },

    actions: {
        /** Reads the saved state; falls back to what the DOM already shows. */
        async hydrate() {
            if (this.hydrated) return;

            const saved = await idbGet(STORAGE_KEY);

            // The theme is whatever the page was painted with: app.js and the
            // Blade shell read localStorage before any of this runs, and the
            // user must not see the colours change a moment later. The
            // IndexedDB copy is the backup for when localStorage was cleared.
            const painted = typeof document !== 'undefined' && document.documentElement.classList.contains('dark')
                ? 'dark'
                : 'light';
            let stored = null;
            try {
                stored = window.localStorage.getItem(THEME_KEY);
            } catch {
                // Blocked storage: the painted value stands.
            }
            this.theme = stored === 'dark' || stored === 'light' ? stored : (saved?.theme ?? painted);
            this.locale = i18n.global.locale.value;
            this.collapsedGroups = Array.isArray(saved?.collapsedGroups) ? saved.collapsedGroups : [];
            this.hydrated = true;
        },

        setTheme(theme) {
            this.theme = theme === 'dark' ? 'dark' : 'light';

            if (typeof document !== 'undefined') {
                document.documentElement.classList.toggle('dark', this.isDark);
            }
            try {
                window.localStorage.setItem(THEME_KEY, this.theme);
            } catch {
                // Blocked storage: the IndexedDB copy still carries it.
            }

            this.persist();
        },

        toggleTheme() {
            this.setTheme(this.isDark ? 'light' : 'dark');
        },

        /** Switches language, loading that bundle first (i18n/index.js). */
        async useLocale(code) {
            await setLocale(code);
            this.locale = i18n.global.locale.value;
            this.persist();
        },

        setGroupCollapsed(groupId, collapsed) {
            const id = String(groupId);
            const kept = this.collapsedGroups.filter((item) => item !== id);
            this.collapsedGroups = collapsed ? [...kept, id] : kept;
            this.persist();
        },

        /** Writes the whole state back; failures are not worth reporting. */
        persist() {
            idbSet(STORAGE_KEY, {
                theme: this.theme,
                locale: this.locale,
                collapsedGroups: [...this.collapsedGroups],
            });
        },
    },
});

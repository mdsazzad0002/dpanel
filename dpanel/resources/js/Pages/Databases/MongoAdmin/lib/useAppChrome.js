import { computed, onMounted, ref } from 'vue';

// Theme and sidebar state for the standalone Mongo Admin app. The theme is
// the panel's own setting (serverpanel-theme), as in dPanel Mail.
const read = (key) => {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
};
const write = (key, value) => {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Preference only; ignore unavailable storage.
    }
};

export function useAppChrome() {
    const theme = ref('light');
    const isDark = computed(() => theme.value === 'dark');
    const sidebarCollapsed = ref(read('mongo-admin-sidebar-collapsed') === '1');
    const sidebarOpen = ref(false);

    const applyTheme = () => document.documentElement.classList.toggle('dark', isDark.value);
    const toggleTheme = () => {
        theme.value = isDark.value ? 'light' : 'dark';
        write('serverpanel-theme', theme.value);
        applyTheme();
    };
    const toggleSidebar = () => {
        sidebarCollapsed.value = !sidebarCollapsed.value;
        write('mongo-admin-sidebar-collapsed', sidebarCollapsed.value ? '1' : '0');
    };

    onMounted(() => {
        const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
        theme.value = read('serverpanel-theme') ?? (prefersDark ? 'dark' : 'light');
        applyTheme();
    });

    return { isDark, toggleTheme, sidebarCollapsed, sidebarOpen, toggleSidebar };
}

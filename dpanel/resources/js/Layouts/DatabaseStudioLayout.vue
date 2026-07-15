<script setup>
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    server: {
        type: Object,
        default: () => ({}),
    },
});

const theme = ref('light');
const serverInfo = ref({
    host: props.server?.host || '127.0.0.1',
    port: props.server?.port || '3306',
    version: props.server?.version || 'MySQL',
    currentUser: props.server?.current_user || 'root',
    currentDatabase: props.server?.current_database || '',
});

onMounted(() => {
    const savedTheme = localStorage.getItem('phpmyadmin-theme');
    if (savedTheme) {
        theme.value = savedTheme;
        applyTheme(savedTheme);
    }
});

const applyTheme = (mode) => {
    document.documentElement.classList.toggle('dark', mode === 'dark');
};

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    localStorage.setItem('phpmyadmin-theme', theme.value);
    applyTheme(theme.value);
};

const handleLogout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div class="relative flex h-screen overflow-hidden bg-[#070b16] text-slate-100">
        <aside class="fixed inset-y-0 left-0 z-40 w-[280px] xl:w-[300px]">
            <slot name="sidebar" />
        </aside>

        <div class="ml-[280px] flex min-w-0 flex-1 flex-col overflow-hidden xl:ml-[300px]">
            <header class="border-b border-slate-800/80 bg-[#0f172a] text-slate-100 shadow-[0_1px_0_rgba(255,255,255,0.02)]">
                <div class="flex flex-wrap items-center justify-between gap-2 py-1 ">
                    <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-300">
                        <i class="bi bi-hdd-rack text-slate-500"></i>
                        <span>Server: {{ serverInfo.host }}:{{ serverInfo.port }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-300">
                        <i class="bi bi-person text-slate-500"></i>
                        <span>User: {{ serverInfo.currentUser }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-300">
                        <i class="bi bi-database text-slate-500"></i>
                        <span>DB: {{ serverInfo.currentDatabase || 'none' }}</span>
                    </div>
                    </div>
                    <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs font-medium text-slate-200 transition hover:border-slate-600 hover:bg-slate-800"
                        @click="toggleTheme"
                    >
                        <i :class="['bi', theme === 'dark' ? 'bi-sun' : 'bi-moon']"></i>
                        {{ theme === 'dark' ? 'Light' : 'Dark' }}
                    </button>
                    <Link
                        :href="route('dashboard')"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs font-medium text-slate-200 transition hover:border-slate-600 hover:bg-slate-800"
                    >
                        <i class="bi bi-arrow-left"></i>
                        dPanel
                    </Link>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-red-500/50 bg-red-500/10 px-3 py-1.5 text-xs font-medium text-red-200 transition hover:bg-red-500/20"
                        @click="handleLogout"
                    >
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </button>
                </div>
                </div>

                <div class="">
                    <div class="overflow-x-auto">
                        <slot name="navigation" />
                    </div>
                </div>
            </header>

            <main class="min-h-0 flex-1 overflow-hidden">
                <slot />
            </main>
        </div>
    </div>
</template>

<script setup>
// TODO: replace with the real GitHub repo / company / docs URLs.
const links = [
    { label: 'GitHub', hint: 'Source repo', href: 'https://github.com/mdsazzad0002/dpanel', icon: 'bi-github' },
    { label: 'Docs', hint: 'Documentation', href: 'https://dpanel.dengrweb.com', icon: 'bi-file-earmark-text' },
    { label: 'Company', hint: 'Our website', href: 'https://dengrweb.com', icon: 'bi-building' },
];
</script>

<template>
    <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900">
        <h2 class="px-2 pb-2 pt-1 font-mono text-xs uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">Resources</h2>
        <div class="grid grid-cols-3 gap-2">
            <a
                v-for="link in links"
                :key="link.label"
                :href="link.href"
                target="_blank"
                rel="noopener"
                class="group flex flex-col items-center gap-1.5 rounded-lg px-2 py-3 text-center transition hover:bg-emerald-400/[0.06]"
            >
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-700 transition group-hover:bg-gradient-to-br group-hover:from-emerald-400 group-hover:to-cyan-400 group-hover:text-slate-950 dark:bg-white/[0.06] dark:text-slate-200">
                    <i :class="['bi', link.icon]"></i>
                </span>
                <span class="text-xs font-semibold text-slate-800 dark:text-slate-100">{{ link.label }}</span>
                <span class="hidden truncate text-[11px] text-slate-500 dark:text-slate-400 min-[400px]:block">{{ link.hint }}</span>
            </a>
        </div>
    </section>
</template>

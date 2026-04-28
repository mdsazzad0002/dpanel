<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    job: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
});

const chatItems = computed(() => {
    const items = [];

    items.push({
        id: `user-${props.job.id}`,
        role: 'user',
        title: 'You',
        body: props.job.command || '(empty command)',
        meta: props.job.created_at,
    });

    if (props.job.output || props.job.error_output) {
        const output = [];
        if (props.job.output) output.push(`STDOUT\n${props.job.output}`);
        if (props.job.error_output) output.push(`STDERR\n${props.job.error_output}`);
        output.push(`Exit: ${props.job.exit_code ?? '-'}`);
        items.push({
            id: `system-${props.job.id}`,
            role: 'system',
            title: 'Server',
            body: output.join('\n\n'),
            meta: props.job.finished_at || props.job.updated_at,
        });
    }

    if (props.job.ai_summary || props.job.ai_fix_suggestion) {
        const aiBody = [
            props.job.ai_summary ? `Summary:\n${props.job.ai_summary}` : null,
            props.job.ai_fix_suggestion ? `Suggested Fix:\n${props.job.ai_fix_suggestion}` : null,
        ].filter(Boolean).join('\n\n');

        items.push({
            id: `assistant-${props.job.id}`,
            role: 'assistant',
            title: 'AI Assistant',
            body: aiBody || 'No AI analysis yet.',
            meta: props.job.updated_at,
        });
    }

    (props.job.events || []).forEach((event) => {
        items.push({
            id: `event-${event.id}`,
            role: 'event',
            title: `Event: ${event.type}`,
            body: event.message,
            meta: event.created_at,
        });
    });

    return items;
});
</script>

<template>
    <Head :title="`Command ${job.uuid}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">Command Chat History</h1>
                <div class="flex gap-2">
                    <button class="rounded border border-slate-300 px-3 py-1 text-xs" @click="router.post(route('commands.retry', job.id))">Retry</button>
                    <a :href="route('commands.report', job.id)" class="rounded border border-slate-300 px-3 py-1 text-xs">Download TXT report</a>
                </div>
            </div>
        </template>

        <div class="grid gap-4 xl:grid-cols-[280px_1fr_320px]">
            <aside class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold">Chat Panel</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Conversation structure</p>
                <div class="mt-4 space-y-2">
                    <article class="rounded-lg border border-cyan-200 bg-cyan-50 p-2 text-xs dark:border-cyan-900/50 dark:bg-cyan-900/20">
                        <p class="font-semibold">You</p>
                        <p class="mt-1 font-mono break-words">{{ job.command || '(empty command)' }}</p>
                    </article>
                    <article class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs dark:border-slate-700 dark:bg-slate-800/60">
                        <p class="font-semibold">Server</p>
                        <p class="mt-1">Status: {{ job.status }}</p>
                        <p>Risk: {{ job.risk_level }}</p>
                    </article>
                    <article class="rounded-lg border border-emerald-200 bg-emerald-50 p-2 text-xs dark:border-emerald-900/40 dark:bg-emerald-900/20">
                        <p class="font-semibold">AI</p>
                        <p class="mt-1">{{ job.ai_summary ? 'Analysis ready' : 'Waiting analysis' }}</p>
                    </article>
                </div>
            </aside>

            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
                    <article
                        v-for="item in chatItems"
                        :key="item.id"
                        class="flex"
                        :class="item.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[90%] rounded-2xl px-4 py-3 text-sm shadow-sm"
                            :class="item.role === 'user'
                                ? 'bg-cyan-600 text-white'
                                : (item.role === 'assistant'
                                    ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100'
                                    : (item.role === 'system'
                                        ? 'bg-slate-900 text-emerald-300'
                                        : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'))"
                        >
                            <p class="text-xs font-semibold opacity-80">{{ item.title }}</p>
                            <pre class="mt-1 whitespace-pre-wrap break-words font-mono text-xs">{{ item.body }}</pre>
                            <p class="mt-2 text-[11px] opacity-70">{{ item.meta || '-' }}</p>
                        </div>
                    </article>
                </div>
            </section>

            <section class="space-y-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 text-xs dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Command Meta</h2>
                    <p class="mt-2">Status: <span class="font-semibold">{{ job.status }}</span></p>
                    <p>Risk: <span class="font-semibold">{{ job.risk_level }}</span></p>
                    <p>UUID: <span class="font-mono">{{ job.uuid }}</span></p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold">Suggested Fix Commands</h2>
                    <div class="mt-3 space-y-2">
                        <article v-for="child in job.children" :key="child.id" class="rounded border border-slate-200 p-2 text-xs dark:border-slate-700">
                            <p class="font-mono">{{ child.command }}</p>
                            <p class="text-slate-500 dark:text-slate-400">{{ child.risk_level }} - {{ child.status }}</p>
                            <div class="mt-1 flex gap-2">
                                <button class="rounded border border-slate-300 px-2 py-1 dark:border-slate-700" @click="router.post(route('commands.run-suggested-fix', child.id))">Run Fix</button>
                                <Link :href="route('commands.show', child.id)" class="rounded border border-slate-300 px-2 py-1 dark:border-slate-700">Open</Link>
                            </div>
                        </article>
                        <p v-if="!job.children || job.children.length === 0" class="text-xs text-slate-500 dark:text-slate-400">No suggested fix commands.</p>
                    </div>
                </div>
            </section>
        </div>

        <div class="mt-4 flex gap-2 text-xs" v-if="job.status === 'pending_approval' && canApprove">
            <button class="rounded bg-emerald-600 px-3 py-2 font-semibold text-white" @click="router.post(route('commands.approve', job.id))">Approve Command</button>
            <button class="rounded bg-red-600 px-3 py-2 font-semibold text-white" @click="router.post(route('commands.cancel', job.id))">Cancel</button>
        </div>
    </AuthenticatedLayout>
</template>

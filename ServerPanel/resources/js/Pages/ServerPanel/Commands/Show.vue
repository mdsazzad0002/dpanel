<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    job: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
});
</script>

<template>
    <Head :title="`Command ${job.uuid}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">Command Details</h1>
                <div class="flex gap-2">
                    <button class="rounded border border-slate-300 px-3 py-1 text-xs" @click="router.post(route('commands.retry', job.id))">Retry</button>
                    <a :href="route('commands.report', job.id)" class="rounded border border-slate-300 px-3 py-1 text-xs">Download TXT report</a>
                </div>
            </div>
        </template>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Terminal Output</h2>
                <pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-emerald-300">{{ job.output || '(empty)' }}</pre>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Error Output</h2>
                <pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-red-300">{{ job.error_output || '(empty)' }}</pre>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">AI Explanation</h2>
                <p class="mt-2 text-sm">{{ job.ai_summary || 'Not analyzed yet.' }}</p>
                <h3 class="mt-4 text-xs font-semibold uppercase text-slate-500">Suggested Fixes</h3>
                <p class="mt-1 text-sm">{{ job.ai_fix_suggestion || '-' }}</p>
                <div class="mt-3 space-y-2">
                    <article v-for="child in job.children" :key="child.id" class="rounded border border-slate-200 p-2 text-xs">
                        <p class="font-mono">{{ child.command }}</p>
                        <p class="text-slate-500">{{ child.risk_level }} - {{ child.status }}</p>
                        <div class="mt-1 flex gap-2">
                            <button class="rounded border border-slate-300 px-2 py-1" @click="router.post(route('commands.run-suggested-fix', child.id))">Approve & Run Fix</button>
                            <Link :href="route('commands.show', child.id)" class="rounded border border-slate-300 px-2 py-1">Open</Link>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Events Timeline</h2>
                <div class="mt-2 space-y-2 text-xs">
                    <article v-for="event in job.events" :key="event.id" class="rounded border border-slate-200 p-2">
                        <p class="font-semibold">{{ event.type }}</p>
                        <p>{{ event.message }}</p>
                        <p class="text-slate-500">{{ event.created_at }}</p>
                    </article>
                </div>
            </section>
        </div>

        <div class="mt-4 flex gap-2 text-xs" v-if="job.status === 'pending_approval' && canApprove">
            <button class="rounded bg-emerald-600 px-3 py-2 font-semibold text-white" @click="router.post(route('commands.approve', job.id))">Approve Command</button>
            <button class="rounded bg-red-600 px-3 py-2 font-semibold text-white" @click="router.post(route('commands.cancel', job.id))">Cancel</button>
        </div>
    </AuthenticatedLayout>
</template>

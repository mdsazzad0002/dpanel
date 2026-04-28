<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({ task: { type: Object, required: true } });
</script>

<template>
    <Head :title="`Task ${task.title}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">{{ task.title }}</h1>
                <div class="flex gap-2 text-xs">
                    <button class="rounded border border-slate-300 px-3 py-1" @click="router.post(route('server-tasks.start', task.id))">Start</button>
                    <button class="rounded border border-slate-300 px-3 py-1" @click="router.post(route('server-tasks.cancel', task.id))">Cancel</button>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-sm"><span class="font-semibold">Goal:</span> {{ task.goal }}</p>
                <p class="text-sm"><span class="font-semibold">Status:</span> {{ task.status }}</p>
                <p class="text-sm"><span class="font-semibold">Priority:</span> {{ task.priority }}</p>
                <p class="text-sm"><span class="font-semibold">Server:</span> {{ task.server?.name }}</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">AI Plan Placeholder</h2>
                <ul class="mt-3 space-y-2 text-xs">
                    <li v-for="(step, idx) in task.ai_plan || []" :key="idx" class="rounded border border-slate-200 p-2">{{ step.title }} - {{ step.status }}</li>
                </ul>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Task Steps</h2>
                <div class="mt-3 space-y-2 text-xs">
                    <article v-for="step in task.steps" :key="step.id" class="rounded border border-slate-200 p-2">
                        <p class="font-semibold">{{ step.sort_order }}. {{ step.title }}</p>
                        <p>{{ step.description }}</p>
                        <p class="text-slate-500">{{ step.status }}</p>
                        <Link v-if="step.command_job" :href="route('commands.show', step.command_job.id)" class="text-cyan-700">Open Command</Link>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold">Command Timeline</h2>
                <div class="mt-3 space-y-2 text-xs">
                    <article v-for="job in task.command_jobs" :key="job.id" class="rounded border border-slate-200 p-2">
                        <p class="font-mono">{{ job.command }}</p>
                        <p>{{ job.status }} - {{ job.risk_level }}</p>
                        <Link :href="route('commands.show', job.id)" class="text-cyan-700">Open</Link>
                    </article>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

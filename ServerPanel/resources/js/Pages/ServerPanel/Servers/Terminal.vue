<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    server: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});

const aiReviewMode = ref(true);
const queueMode = ref(true);
const emergencyRaw = ref(false);

const form = useForm({
    server_id: props.server.id,
    command: '',
    tags: [],
});

const classify = computed(() => {
    const text = form.command.toLowerCase();
    if (!text.trim()) return { level: 'safe', reason: 'Waiting for input.' };
    if (/rm -rf \/|mkfs|dd if=|\|\s*bash|shutdown|reboot|truncate -s 0 \/etc\/passwd/.test(text)) {
        return { level: 'blocked', reason: 'Dangerous command pattern detected.' };
    }
    if (/apt install|apt upgrade|systemctl restart|composer update|npm install|php artisan migrate|chmod|chown|\brm\b/.test(text)) {
        return { level: 'approval_required', reason: 'Potentially mutating command requires approval.' };
    }
    return { level: 'safe', reason: 'Read-only/diagnostic command profile.' };
});

const submit = () => {
    form.post(route('commands.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('command'),
    });
};
</script>

<template>
    <Head :title="`AI Terminal - ${server.name}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">AI Terminal: {{ server.name }}</h1>
                <Link :href="route('servers.show', server.id)" class="text-sm text-cyan-700">Back to Server</Link>
            </div>
        </template>

        <div class="grid gap-4 xl:grid-cols-[2fr_1fr]">
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="mb-3 grid gap-3 md:grid-cols-3 text-xs">
                    <label class="flex items-center gap-2"><input v-model="aiReviewMode" type="checkbox"> AI Review Mode</label>
                    <label class="flex items-center gap-2"><input v-model="queueMode" type="checkbox"> Queue Mode</label>
                    <label class="flex items-center gap-2"><input v-model="emergencyRaw" type="checkbox" :disabled="server.mode !== 'emergency'"> Emergency Raw SSH</label>
                </div>

                <form @submit.prevent="submit" class="space-y-3">
                    <input v-model="form.command" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm" placeholder="Ask AI or type command" />
                    <div class="rounded border px-3 py-2 text-xs" :class="classify.level === 'safe' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : (classify.level === 'blocked' ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700')">
                        <span class="font-semibold">{{ classify.level }}</span> - {{ classify.reason }}
                    </div>
                    <button class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing">Submit Command</button>
                </form>

                <div class="mt-5">
                    <h2 class="text-sm font-semibold">Events Timeline</h2>
                    <div class="mt-2 max-h-[460px] space-y-2 overflow-y-auto rounded border border-slate-200 p-3 text-xs">
                        <article v-for="item in history" :key="item.id" class="rounded border border-slate-200 p-2">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold">{{ item.status }}</span>
                                <span>{{ item.created_at }}</span>
                            </div>
                            <p class="mt-1 font-mono">{{ item.command }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <Link :href="route('commands.show', item.id)" class="rounded border border-slate-300 px-2 py-1">Details</Link>
                                <button type="button" class="rounded border border-slate-300 px-2 py-1" @click="router.post(route('commands.retry', item.id))">Retry</button>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 text-xs">
                <h2 class="font-semibold">Search History</h2>
                <p class="mt-1 text-slate-500">Filter by success/failed/blocked from Commands page.</p>
                <div class="mt-3 space-y-2">
                    <Link :href="route('commands.index', { server_id: server.id, status: 'success' })" class="block rounded border border-slate-300 px-3 py-2">Success Commands</Link>
                    <Link :href="route('commands.index', { server_id: server.id, status: 'failed' })" class="block rounded border border-slate-300 px-3 py-2">Failed Commands</Link>
                    <Link :href="route('commands.index', { server_id: server.id, status: 'blocked' })" class="block rounded border border-slate-300 px-3 py-2">Blocked Commands</Link>
                    <Link :href="route('ssh-memories.index')" class="block rounded border border-slate-300 px-3 py-2">Memories</Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

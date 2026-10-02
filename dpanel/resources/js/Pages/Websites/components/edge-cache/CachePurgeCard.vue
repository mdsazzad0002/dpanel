<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    domain: { type: String, required: true },
    purgeUrl: { type: String, required: true },
});
const emit = defineEmits(['purged']);

const type = ref('everything');
const targets = ref('');
const busy = ref(false);
const message = ref('');
const failed = ref(false);

const placeholder = computed(() => (type.value === 'urls'
    ? `https://${props.domain}/\nhttps://${props.domain}/blog/hello-world`
    : '/blog/\n/wp-content/uploads/'));

const purge = async () => {
    if (type.value === 'everything' && !confirm(`Remove every cached copy of ${props.domain}?`)) return;
    busy.value = true;
    message.value = '';
    failed.value = false;
    try {
        const { data } = await window.axios.post(props.purgeUrl, { type: type.value, targets: targets.value }, { headers: { Accept: 'application/json' } });
        message.value = data.message;
        emit('purged');
    } catch (error) {
        failed.value = true;
        message.value = error.response?.data?.errors?.targets?.[0] || error.response?.data?.message || 'Purge failed.';
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="font-semibold">Purge cache</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Remove cached copies after you change content, so the next visit fetches it fresh.</p>

        <div class="mt-4 inline-flex rounded-lg border border-slate-200 p-1 text-sm dark:border-slate-700">
            <button v-for="[value, label] in [['everything', 'Everything'], ['urls', 'Specific URLs'], ['prefixes', 'By path prefix']]" :key="value" type="button" class="rounded-md px-3 py-1.5" :class="type === value ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'" @click="type = value">{{ label }}</button>
        </div>

        <textarea v-if="type !== 'everything'" v-model="targets" rows="4" spellcheck="false" :placeholder="placeholder" class="mt-4 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea>
        <p v-if="type === 'urls'" class="mt-1 text-xs text-slate-500">One URL or path per line. A URL without ?query also clears its query-string variants.</p>
        <p v-if="type === 'prefixes'" class="mt-1 text-xs text-slate-500">Every cached page whose path starts with one of these is removed.</p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" :disabled="busy" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950" @click="purge">{{ busy ? 'Purging…' : 'Purge' }}</button>
            <p v-if="message" class="text-sm" :class="failed ? 'text-red-600' : 'text-slate-600 dark:text-slate-300'">{{ message }}</p>
        </div>
    </section>
</template>

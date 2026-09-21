<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    websiteId: {
        type: [String, Number],
        required: true,
    },
});

const page = usePage();
const panelToken = () => String(page.props.panel?.token || '');
const panelRoute = (name, params = {}) => (
    panelToken() ? route(name, { token: panelToken(), ...params }) : route(name, params)
);

const busy = ref(false);
const feedback = ref('');
const feedbackType = ref('error');

const loginToWordPress = async () => {
    if (busy.value) return;
    busy.value = true;
    feedback.value = '';

    try {
        const response = await window.axios.post(
            panelRoute('websites.wordpress.sso', { id: props.websiteId }),
            {},
            { headers: { Accept: 'application/json' } },
        );

        const payload = response?.data || {};
        if (!payload.success || !payload.url) {
            throw new Error(payload.message || 'Could not create a login link.');
        }

        window.open(payload.url, '_blank', 'noopener');
    } catch (error) {
        feedbackType.value = 'error';
        feedback.value = error?.response?.data?.message || error?.message || 'Could not create a login link.';
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="flex flex-col items-start gap-2">
        <button
            type="button"
            class="rounded-md border px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="busy"
            :class="busy
                ? 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400'
                : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20'"
            @click="loginToWordPress"
        >
            <i class="bi bi-box-arrow-in-right mr-2"></i>
            {{ busy ? 'Creating login link...' : 'Login to WordPress' }}
        </button>
        <p v-if="feedback" class="text-xs" :class="feedbackType === 'error' ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">
            {{ feedback }}
        </p>
    </div>
</template>

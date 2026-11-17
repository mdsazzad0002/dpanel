<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    busy: { type: String, default: '' },
});

const emit = defineEmits(['block']);

const ip = ref('');

const block = () => {
    const value = ip.value.trim();
    if (!value) return;
    emit('block', value);
    ip.value = '';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-base font-semibold">{{ t('security.fail2ban.blockIp.title') }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('security.fail2ban.blockIp.help') }}</p>

        <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto]" @submit.prevent="block">
            <input v-model="ip" type="text" placeholder="203.0.113.10" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <button type="submit" :disabled="!ip.trim() || busy === ip.trim()" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">
                {{ t('security.fail2ban.blockIp.submit') }}
            </button>
        </form>
    </section>
</template>

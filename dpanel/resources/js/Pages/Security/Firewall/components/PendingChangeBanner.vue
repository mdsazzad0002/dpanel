<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ seconds: { type: Number, required: true }, message: { type: String, default: '' } });
const emit = defineEmits(['keep', 'expired']);
const { t } = useI18n();

const left = ref(props.seconds);
let timer = null;

onMounted(() => {
    timer = window.setInterval(() => {
        left.value -= 1;
        if (left.value <= 0) {
            window.clearInterval(timer);
            emit('expired');
        }
    }, 1000);
});
onUnmounted(() => window.clearInterval(timer));
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-amber-800 dark:bg-amber-950" role="alert">
        <div class="text-sm text-amber-800 dark:text-amber-200">
            <p class="font-semibold"><i class="bi bi-hourglass-split mr-2"></i>{{ message }}</p>
            <p class="mt-1">{{ t('firewall.pendingHelp', { seconds: left }) }}</p>
        </div>
        <button type="button" class="shrink-0 rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700" @click="emit('keep')">
            {{ t('firewall.keep') }}
        </button>
    </div>
</template>

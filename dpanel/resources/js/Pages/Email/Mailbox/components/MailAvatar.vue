<script setup>
import { computed, ref, watch } from 'vue';

// Senders without a picture, remembered for this page so a list does not
// ask again for every row on every render.
const missing = new Set();

const props = defineProps({
    email: { type: String, default: '' },
    initials: { type: String, default: '?' },
    colorClass: { type: String, default: 'bg-slate-500' },
    // Only for messages whose sender passed DMARC/aligned DKIM: allows a BIMI logo.
    verified: { type: Boolean, default: false },
    endpoint: { type: String, default: '' },
    // Changes after a photo upload so the browser fetches it again.
    version: { type: [String, Number], default: '' },
});

const address = computed(() => String(props.email || '').trim().toLowerCase());
const key = computed(() => `${address.value}|${props.verified ? 1 : 0}|${props.version}`);
const failed = ref(false);

const src = computed(() => {
    if (!props.endpoint || !address.value.includes('@') || failed.value || missing.has(key.value)) return '';
    const params = new URLSearchParams({ email: address.value });
    if (props.verified) params.set('bimi', '1');
    if (props.version) params.set('v', String(props.version));

    return `${props.endpoint}${props.endpoint.includes('?') ? '&' : '?'}${params}`;
});

watch(key, () => { failed.value = false; });

const onError = () => {
    missing.add(key.value);
    failed.value = true;
};
</script>

<template>
    <span :class="[src ? 'bg-white' : colorClass, 'relative flex shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold text-white']">
        <img
            v-if="src"
            :src="src"
            alt=""
            loading="lazy"
            referrerpolicy="no-referrer"
            class="h-full w-full object-cover"
            @error="onError"
        >
        <template v-else>{{ initials }}</template>
    </span>
</template>

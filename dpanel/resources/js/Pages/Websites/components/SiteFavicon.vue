<script setup>
import { ref, watch } from 'vue';

// A website's own /favicon.ico, loaded straight from the site; the letter
// avatar shows while it loads and whenever the site has none or is down.
// no-referrer: the panel URL carries the cpsess session token, which must
// never reach the website in a Referer header.
const props = defineProps({
    url: { type: String, default: '' },
    domain: { type: String, default: '' },
    boxClass: { type: [String, Array, Object], default: 'h-10 w-10 rounded-xl text-sm' },
    letterClass: { type: [String, Array, Object], default: 'bg-gradient-to-br from-blue-500 to-indigo-500 text-white shadow-sm' },
});

const loaded = ref(false);
const failed = ref(false);

watch(() => props.url, () => {
    loaded.value = false;
    failed.value = false;
});

const letter = () => props.domain?.charAt(0)?.toUpperCase() || 'W';
</script>

<template>
    <div class="relative flex shrink-0 items-center justify-center overflow-hidden font-bold" :class="[boxClass, loaded ? 'bg-white ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700' : letterClass]">
        <span v-if="!loaded">{{ letter() }}</span>
        <img
            v-if="url && !failed"
            :src="`${url}/favicon.ico`"
            alt=""
            loading="lazy"
            referrerpolicy="no-referrer"
            class="h-3/5 w-3/5 object-contain"
            :class="loaded ? '' : 'absolute opacity-0'"
            @load="loaded = true"
            @error="failed = true"
        />
    </div>
</template>

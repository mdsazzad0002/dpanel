<script setup>
defineProps({
    lines: { type: Array, default: () => [] },
    emptyText: { type: String, default: '' },
});

// An access line's status code colours it; PHP lines by their level.
const tone = (line) => {
    const status = line.match(/" (\d{3}) /)?.[1];
    if (status) return status >= '500' ? 'text-red-400' : status >= '400' ? 'text-amber-300' : 'text-slate-200';
    if (/PHP (Fatal|Parse) error/i.test(line)) return 'text-red-400';
    if (/PHP Warning/i.test(line)) return 'text-amber-300';
    return 'text-slate-200';
};
</script>

<template>
    <div class="max-h-[65vh] overflow-auto rounded-2xl border border-slate-800 bg-slate-950 p-4 font-mono text-xs leading-5">
        <p v-if="!lines.length" class="py-8 text-center text-slate-400">{{ emptyText }}</p>
        <pre v-for="(line, index) in lines" v-else :key="index" class="whitespace-pre-wrap break-all" :class="tone(line)">{{ line }}</pre>
    </div>
</template>

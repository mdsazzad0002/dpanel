<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const props = defineProps({ website: { type: Object, required: true }, compact: { type: Boolean, default: false }, fill: { type: Boolean, default: false } });
const page = usePage();
const host = ref(null);
const status = ref('Connecting…');
const connected = ref(false);
const connecting = ref(false);
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
let terminal;
let fitAddon;
let socket;
let resizeObserver;

const fit = () => {
    if (!terminal || !fitAddon) return;
    fitAddon.fit();
    if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ rows: terminal.rows, cols: terminal.cols }));
};

const connect = async () => {
    status.value = 'Connecting…';
    connecting.value = true;
    const response = await fetch(panelRoute('websites.terminal.session', { id: props.website.id }), {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: '{}',
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Unable to open terminal.');
    const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const current = new WebSocket(`${scheme}//${window.location.host}${data.path}?ticket=${encodeURIComponent(data.ticket)}`);
    socket = current;
    current.binaryType = 'arraybuffer';
    current.onopen = () => { connecting.value = false; connected.value = true; status.value = 'Connected'; fit(); terminal.focus(); };
    current.onmessage = async (event) => terminal.write(typeof event.data === 'string' ? event.data : new Uint8Array(event.data));
    current.onerror = () => { if (socket === current) status.value = 'Connection error'; };
    current.onclose = (event) => {
        if (socket !== current) return;
        connecting.value = false;
        connected.value = false;
        status.value = `Session closed (code ${event.code})`;
        terminal?.write(`\r\n[session closed, code ${event.code}${event.reason ? `: ${event.reason}` : ''}]\r\n`);
    };
};

const start = async () => {
    try { await connect(); } catch (error) { connecting.value = false; status.value = error.message; terminal.write(`Error: ${error.message}\r\n`); }
};

const reconnect = () => {
    if (connecting.value) return;
    const previous = socket;
    socket = null;
    previous?.close();
    terminal.reset();
    start();
};

onMounted(async () => {
    terminal = new Terminal({ cursorBlink: true, convertEol: true, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace', fontSize: 13, theme: { background: '#020617', foreground: '#e2e8f0', cursor: '#34d399' }, scrollback: 5000 });
    fitAddon = new FitAddon();
    terminal.loadAddon(fitAddon);
    terminal.open(host.value);
    terminal.onData((value) => { if (socket?.readyState === WebSocket.OPEN) socket.send(new TextEncoder().encode(value)); });
    resizeObserver = new ResizeObserver(fit);
    resizeObserver.observe(host.value);
    await nextTick();
    fit();
    await start();
});

onBeforeUnmount(() => { resizeObserver?.disconnect(); socket?.close(); terminal?.dispose(); });
</script>

<template>
    <div class="overflow-hidden border-slate-800 bg-slate-950" :class="fill ? 'flex h-full flex-col' : 'rounded-2xl border shadow-sm'">
        <div class="flex items-center justify-between border-b border-slate-800 px-4 py-2">
            <div><p class="text-sm font-semibold text-slate-100">Terminal</p><p class="text-[11px] text-emerald-400">{{ website.site_owner }} · {{ status }}</p></div>
            <div class="flex items-center gap-3">
                <button
                    v-if="!connected"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-500 disabled:opacity-60"
                    :disabled="connecting"
                    @click="reconnect"
                >
                    <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': connecting }"></i>Reconnect
                </button>
                <button type="button" class="text-xs text-slate-400 hover:text-white" @click="terminal?.clear(); terminal?.focus()">Clear</button>
                <slot name="actions" />
            </div>
        </div>
        <div ref="host" class="w-full bg-slate-950 p-2" :class="fill ? 'min-h-0 flex-1' : compact ? 'h-48' : 'h-[62vh]'" @click="terminal?.focus()"></div>
    </div>
</template>

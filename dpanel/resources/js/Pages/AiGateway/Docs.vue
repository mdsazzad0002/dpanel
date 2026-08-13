<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    apiBaseUrl: { type: String, default: '' },
});

const curlChat = computed(() => `curl ${props.apiBaseUrl}/chat/completions \\
  -H "Authorization: Bearer sk-ag-..." \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "auto",
    "messages": [{ "role": "user", "content": "Hello!" }]
  }'`);

const curlStream = computed(() => `curl ${props.apiBaseUrl}/chat/completions \\
  -H "Authorization: Bearer sk-ag-..." \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "auto",
    "messages": [{ "role": "user", "content": "Hello!" }],
    "stream": true
  }'`);

const curlModels = computed(() => `curl ${props.apiBaseUrl}/models \\
  -H "Authorization: Bearer sk-ag-..."`);

const curlTools = computed(() => `curl ${props.apiBaseUrl}/chat/completions \\
  -H "Authorization: Bearer sk-ag-..." \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "auto",
    "messages": [{ "role": "user", "content": "What is the weather in Dhaka?" }],
    "tools": [{
      "type": "function",
      "function": {
        "name": "get_weather",
        "description": "Get the current weather for a city",
        "parameters": {
          "type": "object",
          "properties": { "city": { "type": "string" } },
          "required": ["city"]
        }
      }
    }]
  }'

# => choices[0].message.tool_calls[0].function = { name: "get_weather", arguments: "{\\"city\\":\\"Dhaka\\"}" }
# Run the function yourself, then send the result back to continue the loop:`);

const curlToolsFollowup = computed(() => `curl ${props.apiBaseUrl}/chat/completions \\
  -H "Authorization: Bearer sk-ag-..." \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "auto",
    "messages": [
      { "role": "user", "content": "What is the weather in Dhaka?" },
      { "role": "assistant", "content": null, "tool_calls": [
        { "id": "call_1", "type": "function", "function": { "name": "get_weather", "arguments": "{\\"city\\":\\"Dhaka\\"}" } }
      ] },
      { "role": "tool", "tool_call_id": "call_1", "name": "get_weather", "content": "{\\"temp_c\\":31,\\"condition\\":\\"Sunny\\"}" }
    ],
    "tools": [{ "type": "function", "function": { "name": "get_weather", "parameters": { "type": "object", "properties": { "city": { "type": "string" } } } } }]
  }'`);

const pythonSdk = computed(() => `from openai import OpenAI

client = OpenAI(
    base_url="${props.apiBaseUrl}",
    api_key="sk-ag-...",
)

response = client.chat.completions.create(
    model="auto",
    messages=[{"role": "user", "content": "Hello!"}],
)
print(response.choices[0].message.content)`);

const nodeSdk = computed(() => `import OpenAI from "openai";

const client = new OpenAI({
  baseURL: "${props.apiBaseUrl}",
  apiKey: "sk-ag-...",
});

const response = await client.chat.completions.create({
  model: "auto",
  messages: [{ role: "user", content: "Hello!" }],
});
console.log(response.choices[0].message.content);`);
</script>

<template>
    <Head title="AI Gateway API Docs" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">API Docs</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">OpenAI/OpenRouter-compatible API for calling this gateway from outside the panel.</p>
                </div>
                <Link :href="panelRoute('ai-gateway.api-keys.index')" class="text-sm text-blue-600 hover:underline">Manage API keys →</Link>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Base URL &amp; auth</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    All requests use standard OpenAI-style bearer auth. Create a key on the
                    <Link :href="panelRoute('ai-gateway.api-keys.index')" class="text-blue-600 hover:underline">API Keys</Link> page — it's shown once, so copy it right away.
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>Base URL:      {{ apiBaseUrl }}
Authorization: Bearer sk-ag-...</code></pre>
                <p class="mt-2 text-xs text-slate-400">Any request without a valid, active key returns <code>401</code>.</p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">POST /chat/completions</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Same request/response shape as OpenAI's Chat Completions API. Routing is always automatic —
                    <code>model</code> must be <code>"auto"</code> or omitted (any other value is rejected with
                    <code>400</code>). The gateway checks each provider's live rate-limit/cooldown state at request
                    time, rotates round-robin across the eligible ones, and fails over automatically if the one it
                    picked hits a limit mid-request. The response's <code>model</code> field always tells you which
                    one actually served the request.
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlChat }}</code></pre>

                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Streaming (<code>stream: true</code>)</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Returns Server-Sent Events, same chunk format as OpenAI (<code>chat.completion.chunk</code>, terminated by <code>data: [DONE]</code>).</p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlStream }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Tool / function calling (agent mode)</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Same shape as OpenAI's tool calling: pass <code>tools</code> (and optionally <code>tool_choice</code> —
                    <code>"auto"</code>, <code>"required"</code>, <code>"none"</code>, or a specific
                    <code>{"type":"function","function":{"name":...}}</code>) and the model can respond with
                    <code>tool_calls</code> instead of text. Whichever provider actually served the request
                    (Anthropic, Gemini, or an OpenAI-family one) gets this translated to its own native tool format
                    automatically — the request/response shape you see is always OpenAI's.
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlTools }}</code></pre>
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                    To continue the loop, run the function yourself and send its result back as a
                    <code>tool</code> role message (with the matching <code>tool_call_id</code>), alongside the
                    assistant's <code>tool_calls</code> message that triggered it:
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlToolsFollowup }}</code></pre>
                <p class="mt-2 text-xs text-slate-400">
                    In streaming mode, <code>tool_calls</code> arrive complete in a single chunk (not streamed
                    argument-by-argument) followed by a chunk with <code>finish_reason: "tool_calls"</code>.
                </p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">GET /models</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Returns a single <code>"auto"</code> entry, OpenAI-style — the gateway always picks the provider/model itself.</p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlModels }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Using an OpenAI SDK</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Point any OpenAI-compatible SDK at this gateway by overriding its base URL — this also makes it a drop-in OpenRouter replacement.</p>
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Python</h3>
                <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ pythonSdk }}</code></pre>
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Node.js</h3>
                <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ nodeSdk }}</code></pre>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

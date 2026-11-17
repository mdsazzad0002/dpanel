<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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

const curlVision = computed(() => `curl ${props.apiBaseUrl}/chat/completions \\
  -H "Authorization: Bearer sk-ag-..." \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "auto",
    "messages": [{
      "role": "user",
      "content": [
        { "type": "text", "text": "What does this screenshot say?" },
        { "type": "image_url", "image_url": { "url": "data:image/png;base64,iVBORw0KG..." } }
      ]
    }]
  }'`);

const curlImages = computed(() => `curl ${props.apiBaseUrl}/images/generations \\
  -H "Authorization: Bearer sk-ag-..." \\
  -H "Content-Type: application/json" \\
  -d '{ "prompt": "A lighthouse at dusk, watercolor", "size": "1024x1024", "n": 1 }'

# => { "created": 1730000000, "model": "gpt-image-1", "provider": "OpenAI",
#      "data": [{ "b64_json": "iVBORw0KG...", "mime_type": "image/png" }] }`);

const curlTranscribe = computed(() => `curl ${props.apiBaseUrl}/audio/transcriptions \\
  -H "Authorization: Bearer sk-ag-..." \\
  -F file=@voice-note.ogg \\
  -F language=bn

# => { "text": "আসসালামু আলাইকুম, POS এর দাম কত?", "language": "bn",
#      "model": "whisper-large-v3-turbo", "provider": "Groq" }`);

const pythonTranscribe = computed(() => `with open("voice-note.ogg", "rb") as audio:
    transcript = client.audio.transcriptions.create(model="auto", file=audio)
print(transcript.text)`);

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
    <Head :title="t('aiGateway.docs.headTitle')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('aiGateway.docs.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('aiGateway.docs.subtitle') }}</p>
                </div>
                <Link :href="panelRoute('ai-gateway.api-keys.index')" class="text-sm text-blue-600 hover:underline">{{ t('aiGateway.docs.manageKeys') }} →</Link>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">{{ t('aiGateway.docs.authTitle') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.authHelp" scope="global"><template #c1><Link :href="panelRoute('ai-gateway.api-keys.index')" class="text-blue-600 hover:underline">{{ t('aiGateway.apiKeys.title') }}</Link></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>Base URL:      {{ apiBaseUrl }}
Authorization: Bearer sk-ag-...</code></pre>
                <p class="mt-2 text-xs text-slate-400"><i18n-t keypath="aiGateway.docs.auth401" scope="global"><template #c1><code>401</code></template></i18n-t></p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">POST /chat/completions</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.chatHelp" scope="global"><template #c1><code>model</code></template><template #c2><code>"auto"</code></template><template #c3><code>400</code></template><template #c4><code>model</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlChat }}</code></pre>

                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500"><i18n-t keypath="aiGateway.docs.streaming" scope="global"><template #c1><code>stream: true</code></template></i18n-t></h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.streamingHelp" scope="global"><template #c1><code>chat.completion.chunk</code></template><template #c2><code>data: [DONE]</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlStream }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">{{ t('aiGateway.docs.serverTools') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.serverToolsHelp" scope="global"><template #c1><code>"server_tools": "read"</code></template><template #c2><code>tools</code></template></i18n-t></p>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.serverToolsSteps" scope="global"><template #c1><code>delta</code></template><template #c2><code>dpanel_tool_step</code></template><template #c3><code>{id, name, arguments, status: "running"}</code></template><template #c4><code>{id, name, status: "ok"|"error", preview, duration_ms}</code></template><template #c5><code>dpanel_tool_steps</code></template></i18n-t></p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">{{ t('aiGateway.docs.tools') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.toolsHelp" scope="global"><template #c1><code>tools</code></template><template #c2><code>tool_choice</code></template><template #c3><code>"auto"</code></template><template #c4><code>"required"</code></template><template #c5><code>"none"</code></template><template #c6><code>{"type":"function","function":{"name":...}}</code></template><template #c7><code>tool_calls</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlTools }}</code></pre>
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.toolsLoop" scope="global"><template #c1><code>tool</code></template><template #c2><code>tool_call_id</code></template><template #c3><code>tool_calls</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlToolsFollowup }}</code></pre>
                <p class="mt-2 text-xs text-slate-400"><i18n-t keypath="aiGateway.docs.toolsStream" scope="global"><template #c1><code>tool_calls</code></template><template #c2><code>finish_reason: "tool_calls"</code></template></i18n-t></p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">{{ t('aiGateway.docs.vision') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.visionHelp" scope="global"><template #c1><code>text</code></template><template #c2><code>image_url</code></template><template #c3><code>data:</code></template><template #c4><code>vision</code></template><template #c5><code>vision</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlVision }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">POST /images/generations</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.imagesHelp" scope="global"><template #c1><code>image_generation</code></template><template #c2><code>gpt-image-1</code></template><template #c3><code>gemini-2.5-flash-image</code></template><template #c4><code>prompt</code></template><template #c5><code>n</code></template><template #c6><code>size</code></template><template #c7><code>1024x1024</code></template><template #c8><code>b64_json</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlImages }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">POST /audio/transcriptions</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.transcribeHelp" scope="global"><template #c1><code>transcription</code></template><template #c2><code>whisper-large-v3-turbo</code></template><template #c3><code>whisper-1</code></template><template #c4><code>gpt-4o-mini-transcribe</code></template><template #c5><code>gemini-2.5-flash</code></template><template #c6><code>file</code></template><template #c7><code>language</code></template><template #c8><code>bn</code></template><template #c9><code>en</code></template><template #c10><code>prompt</code></template><template #c11><code>response_format</code></template><template #c12><code>json</code></template><template #c13><code>text</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlTranscribe }}</code></pre>
                <p class="mt-2 text-xs text-slate-400"><i18n-t keypath="aiGateway.docs.transcribeSdk" scope="global"><template #c1><code>model</code></template><template #c2><code>"auto"</code></template></i18n-t></p>
                <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ pythonTranscribe }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">GET /models</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><i18n-t keypath="aiGateway.docs.modelsHelp" scope="global"><template #c1><code>"auto"</code></template></i18n-t></p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ curlModels }}</code></pre>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">{{ t('aiGateway.docs.sdk') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ t('aiGateway.docs.sdkHelp') }}</p>
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Python</h3>
                <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ pythonSdk }}</code></pre>
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Node.js</h3>
                <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ nodeSdk }}</code></pre>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

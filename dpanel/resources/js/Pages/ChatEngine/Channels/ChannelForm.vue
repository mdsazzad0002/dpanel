<script setup>
import { useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    channel: { type: Object, default: null }, // null = create mode
    facebookWebhookUrl: { type: String, default: '' },
});

const emit = defineEmits(['saved', 'cancel']);

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const isEdit = !!props.channel;

const form = useForm({
    type: props.channel?.type || 'telegram',
    name: props.channel?.name || '',
    bot_token: '',
    page_id: props.channel?.external_account_id || '',
    page_access_token: '',
    whatsapp_phone_number_id: props.channel?.type === 'whatsapp' ? props.channel.external_account_id : '',
    whatsapp_business_account_id: props.channel?.whatsapp_business_account_id || '',
    whatsapp_access_token: '',
    whatsapp_app_secret: '',
    instagram_account_id: props.channel?.type === 'instagram' ? props.channel.external_account_id : '',
    instagram_access_token: '',
    instagram_app_secret: '',
    slack_workspace_id: props.channel?.type === 'slack' ? props.channel.external_account_id : '',
    slack_bot_token: '',
    slack_signing_secret: '',
    system_prompt: props.channel?.system_prompt || '',
    auto_reply_enabled: props.channel?.auto_reply_enabled ?? true,
    internal_access: props.channel?.internal_access ?? false,
});

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    };

    if (isEdit) {
        form.patch(panelRoute('chat-engine.channels.update', { channel: props.channel.id }), options);
    } else {
        form.post(panelRoute('chat-engine.channels.store'), options);
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4 text-slate-800 dark:text-slate-100">
        <div v-if="!isEdit" class="grid grid-cols-2 gap-2 lg:grid-cols-5">
            <button
                type="button"
                @click="form.type = 'telegram'"
                class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                :class="form.type === 'telegram' ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-950/60 dark:text-blue-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800'"
            >Telegram</button>
            <button
                type="button"
                @click="form.type = 'facebook'"
                class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                :class="form.type === 'facebook' ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-950/60 dark:text-blue-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800'"
            >Facebook Page</button>
            <button
                type="button"
                @click="form.type = 'whatsapp'"
                class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                :class="form.type === 'whatsapp' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950/60 dark:text-emerald-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800'"
            >WhatsApp</button>
            <button
                type="button"
                @click="form.type = 'instagram'"
                class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                :class="form.type === 'instagram' ? 'border-pink-500 bg-pink-50 text-pink-700 dark:border-pink-500 dark:bg-pink-950/60 dark:text-pink-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800'"
            >Instagram</button>
            <button
                type="button"
                @click="form.type = 'slack'"
                class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                :class="form.type === 'slack' ? 'border-violet-500 bg-violet-50 text-violet-700 dark:border-violet-500 dark:bg-violet-950/60 dark:text-violet-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800'"
            >Slack</button>
            <button
                type="button"
                @click="form.type = 'website'"
                class="flex-1 rounded-md border px-4 py-2 text-sm font-medium"
                :class="form.type === 'website' ? 'border-amber-500 bg-amber-50 text-amber-700 dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800'"
            >Website Widget</button>
        </div>
        <p v-else class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ form.type }} channel</p>

        <template v-if="form.type === 'slack'">
            <div v-if="isEdit" class="space-y-2 rounded-md border border-violet-200 bg-violet-50 px-3 py-3 text-xs text-violet-900 dark:border-violet-800 dark:bg-violet-900/20 dark:text-violet-200">
                <p class="font-semibold">Slack Event Subscriptions</p>
                <div><span class="text-violet-700 dark:text-violet-300">Request URL</span><code class="mt-1 block break-all rounded bg-white/70 p-2 dark:bg-slate-900">{{ channel.slack_webhook_url }}</code></div>
                <p>Paste this URL under Slack App → Event Subscriptions, enable events, and subscribe to <code>message.im</code>, <code>message.channels</code>, or the message surfaces your bot should handle.</p>
            </div>
            <div v-else class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                Save first, then edit the channel to copy its unique Slack Events Request URL.
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Workspace ID</label>
                <input v-model="form.slack_workspace_id" type="text" placeholder="T0123456789" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.slack_workspace_id" class="mt-1 text-xs text-red-600">{{ form.errors.slack_workspace_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Bot User OAuth Token</label>
                <input v-model="form.slack_bot_token" type="password" :placeholder="channel?.has_slack_bot_token ? 'Leave blank to keep current token' : 'xoxb-…'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.slack_bot_token" class="mt-1 text-xs text-red-600">{{ form.errors.slack_bot_token }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Signing Secret</label>
                <input v-model="form.slack_signing_secret" type="password" :placeholder="channel?.has_slack_signing_secret ? 'Leave blank to keep current secret' : 'Slack App → Basic Information'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p class="mt-1 text-xs text-slate-400">Used with Slack's timestamp to reject forged or replayed requests.</p>
                <p v-if="form.errors.slack_signing_secret" class="mt-1 text-xs text-red-600">{{ form.errors.slack_signing_secret }}</p>
            </div>
        </template>

        <div>
            <label class="mb-1 block text-sm font-medium">Channel Name</label>
            <input v-model="form.name" type="text" placeholder="Support Bot" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
        </div>

        <template v-if="form.type === 'telegram'">
            <div>
                <label class="mb-1 block text-sm font-medium">Bot Token</label>
                <input v-model="form.bot_token" type="text" :placeholder="channel?.has_bot_token ? 'Leave blank to keep the current token' : '123456:ABC-DEF...'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p class="mt-1 text-xs text-slate-400">
                    <template v-if="isEdit">Only fill this in to replace the token — the webhook is re-registered automatically.</template>
                    <template v-else>Create a bot with @BotFather in Telegram to get this token.</template>
                </p>
                <p v-if="form.errors.bot_token" class="mt-1 text-xs text-red-600">{{ form.errors.bot_token }}</p>
            </div>
        </template>

        <template v-else-if="form.type === 'instagram'">
            <div v-if="isEdit" class="space-y-2 rounded-md border border-pink-200 bg-pink-50 px-3 py-3 text-xs text-pink-900 dark:border-pink-800 dark:bg-pink-900/20 dark:text-pink-200">
                <p class="font-semibold">Instagram Webhooks configuration</p>
                <div><span class="text-pink-700 dark:text-pink-300">Callback URL</span><code class="mt-1 block break-all rounded bg-white/70 p-2 dark:bg-slate-900">{{ channel.instagram_webhook_url }}</code></div>
                <div><span class="text-pink-700 dark:text-pink-300">Verify Token</span><code class="mt-1 block break-all rounded bg-white/70 p-2 dark:bg-slate-900">{{ channel.instagram_verify_token }}</code></div>
                <p>Paste both values in Meta App → Instagram → Webhooks and subscribe the <code>messages</code> field.</p>
            </div>
            <div v-else class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                Instagram API with Instagram Login requires a Professional account and <code>instagram_business_manage_messages</code>. Save first, then edit to copy the generated webhook values.
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Instagram Professional Account ID</label>
                <input v-model="form.instagram_account_id" type="text" placeholder="Instagram user ID" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.instagram_account_id" class="mt-1 text-xs text-red-600">{{ form.errors.instagram_account_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Instagram Access Token</label>
                <input v-model="form.instagram_access_token" type="password" :placeholder="channel?.has_instagram_access_token ? 'Leave blank to keep current token' : 'Instagram user access token'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.instagram_access_token" class="mt-1 text-xs text-red-600">{{ form.errors.instagram_access_token }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Meta App Secret</label>
                <input v-model="form.instagram_app_secret" type="password" :placeholder="channel?.has_instagram_app_secret ? 'Leave blank to keep current secret' : 'Meta App → Settings → Basic'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p class="mt-1 text-xs text-slate-400">Used to validate Meta webhook signatures.</p>
                <p v-if="form.errors.instagram_app_secret" class="mt-1 text-xs text-red-600">{{ form.errors.instagram_app_secret }}</p>
            </div>
        </template>

        <template v-else-if="form.type === 'facebook'">
            <div v-if="!isEdit" class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-200">
                Easier way: register a <a :href="panelRoute('chat-engine.facebook-apps.index')" class="font-medium underline">Facebook App</a>
                once, then use "Connect via Facebook" to auto-add every Page you manage — no need to paste a Page ID
                or token by hand. Use the manual fields below only if you already have a Page Access Token.
                <code class="mt-1 block break-all">{{ facebookWebhookUrl }}</code>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Page ID</label>
                <input v-model="form.page_id" type="text" placeholder="1234567890" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.page_id" class="mt-1 text-xs text-red-600">{{ form.errors.page_id }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Page Access Token</label>
                <input v-model="form.page_access_token" type="text" :placeholder="channel?.has_page_access_token ? 'Leave blank to keep the current token' : 'EAAG...'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p class="mt-1 text-xs text-slate-400">
                    <template v-if="isEdit">Only fill this in to replace the token — the page is re-subscribed automatically.</template>
                    <template v-else>Generate a long-lived Page access token in the Meta developer dashboard for this page.</template>
                </p>
                <p v-if="form.errors.page_access_token" class="mt-1 text-xs text-red-600">{{ form.errors.page_access_token }}</p>
            </div>
        </template>

        <template v-else-if="form.type === 'whatsapp'">
            <div v-if="isEdit" class="space-y-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-3 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">
                <p class="font-semibold">Meta Webhooks configuration</p>
                <div><span class="text-emerald-700 dark:text-emerald-300">Callback URL</span><code class="mt-1 block break-all rounded bg-white/70 p-2 dark:bg-slate-900">{{ channel.whatsapp_webhook_url }}</code></div>
                <div><span class="text-emerald-700 dark:text-emerald-300">Verify Token</span><code class="mt-1 block break-all rounded bg-white/70 p-2 dark:bg-slate-900">{{ channel.whatsapp_verify_token }}</code></div>
                <p>Paste both values under Meta App → WhatsApp → Configuration, then subscribe the <code>messages</code> field.</p>
            </div>
            <div v-else class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                Save the channel first. Then edit it to copy its unique Callback URL and Verify Token into Meta Webhooks.
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Phone Number ID</label>
                <input v-model="form.whatsapp_phone_number_id" type="text" placeholder="From WhatsApp API Setup" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.whatsapp_phone_number_id" class="mt-1 text-xs text-red-600">{{ form.errors.whatsapp_phone_number_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">WhatsApp Business Account ID</label>
                <input v-model="form.whatsapp_business_account_id" type="text" placeholder="WABA ID" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.whatsapp_business_account_id" class="mt-1 text-xs text-red-600">{{ form.errors.whatsapp_business_account_id }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Permanent Access Token</label>
                <input v-model="form.whatsapp_access_token" type="password" :placeholder="channel?.has_whatsapp_access_token ? 'Leave blank to keep current token' : 'System user access token'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p v-if="form.errors.whatsapp_access_token" class="mt-1 text-xs text-red-600">{{ form.errors.whatsapp_access_token }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Meta App Secret</label>
                <input v-model="form.whatsapp_app_secret" type="password" :placeholder="channel?.has_whatsapp_app_secret ? 'Leave blank to keep current secret' : 'Meta App → Settings → Basic'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500" />
                <p class="mt-1 text-xs text-slate-400">Used to verify every webhook signature.</p>
                <p v-if="form.errors.whatsapp_app_secret" class="mt-1 text-xs text-red-600">{{ form.errors.whatsapp_app_secret }}</p>
            </div>
        </template>

        <template v-else-if="form.type === 'website'">
            <div v-if="isEdit" class="space-y-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                <p class="font-semibold">Embed on your website</p>
                <p>Paste this before <code>&lt;/body&gt;</code> on any page of your site — it auto-connects, no other setup needed.</p>
                <code class="mt-1 block break-all rounded bg-white/70 p-2 dark:bg-slate-900">&lt;script src="{{ channel.widget_script_url }}" data-channel="{{ channel.id }}" async&gt;&lt;/script&gt;</code>
            </div>
            <div v-else class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                No credentials needed. Save first, then copy the embed snippet from here to add the chat widget to your site.
            </div>

            <label class="flex items-start gap-2 rounded-md border border-slate-200 p-3 text-sm dark:border-slate-700">
                <input v-model="form.internal_access" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                <span>
                    <span class="font-medium">Internal / staff-only channel</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                        Unlocks tools that expose customer data and take real actions (send SMS, bulk due reminders, full search). Only turn this on for a channel used from the "Assistant" page below — never for one whose embed snippet is pasted onto a public website, since anyone who can reach that script tag reaches these tools too.
                    </span>
                </span>
            </label>

            <div v-if="isEdit && form.internal_access" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">
                <a :href="panelRoute('chat-engine.channels.assistant', { channel: channel.id })" class="font-medium underline">Open Assistant →</a>
                Chat with this business's AI here, from inside the panel, to trigger internal-only actions.
            </div>
        </template>

        <div v-if="isEdit" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
            Business:
            <span v-if="channel.business" class="font-medium">{{ channel.business.name }}</span>
            <span v-else>Not assigned — manage assignment from <a :href="panelRoute('chat-engine.businesses.index')" class="text-blue-600 hover:underline">Businesses</a>.</span>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium">System Prompt (optional)</label>
            <textarea v-model="form.system_prompt" rows="3" placeholder="You are a helpful support assistant for..." class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500"></textarea>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input v-model="form.auto_reply_enabled" type="checkbox" class="rounded border-slate-300" />
            Auto-reply with AI to incoming {{ form.type === 'facebook' ? 'messages and comments' : 'messages' }}
        </label>

        <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
            <button type="button" @click="emit('cancel')" class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">Cancel</button>
            <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                {{ isEdit ? 'Save Changes' : 'Connect Channel' }}
            </button>
        </div>
    </form>
</template>

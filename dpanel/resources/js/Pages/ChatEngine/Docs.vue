<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const activeSection = ref('telegram-bot');
const docGroups = [
    {
        label: 'Telegram Guide',
        sections: [
            { id: 'telegram-bot', label: 'Create Telegram Bot', icon: 'bi-telegram' },
            { id: 'connect-channel', label: 'Connect Telegram', icon: 'bi-link-45deg' },
        ],
    },
    {
        label: 'Facebook Guide',
        sections: [
            { id: 'facebook-pages', label: 'Connect Facebook Pages', icon: 'bi-facebook' },
        ],
    },
    {
        label: 'WhatsApp Guide',
        sections: [
            { id: 'whatsapp-cloud', label: 'Connect WhatsApp Cloud', icon: 'bi-whatsapp' },
        ],
    },
    {
        label: 'Instagram Guide',
        sections: [
            { id: 'instagram-dm', label: 'Connect Instagram DM', icon: 'bi-instagram' },
        ],
    },
    {
        label: 'Slack Guide',
        sections: [
            { id: 'slack-app', label: 'Connect Slack App', icon: 'bi-slack' },
        ],
    },
    {
        label: 'Common Guide',
        sections: [
            { id: 'worker', label: 'Queue & Scheduler', icon: 'bi-cpu' },
            { id: 'reply-flow', label: 'How Replies Work', icon: 'bi-arrow-repeat' },
            { id: 'scheduled', label: 'Scheduled Messages', icon: 'bi-clock' },
            { id: 'troubleshooting', label: 'Troubleshooting', icon: 'bi-tools' },
        ],
    },
];

defineProps({
    webhookBaseUrl: { type: String, default: '' },
    facebookWebhookUrl: { type: String, default: '' },
    facebookVerifyTokenConfigured: { type: Boolean, default: false },
    facebookAppSecretConfigured: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="AI Chat Engine Docs" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">AI Chat Engine — Setup Guide</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">How to connect a channel and how auto-reply works under the hood.</p>
                </div>
                <Link :href="panelRoute('chat-engine.channels.index')" class="text-sm text-blue-600 hover:underline">Go to Channels →</Link>
            </div>
        </template>

        <div class="mx-auto  overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div class="grid lg:grid-cols-[260px_minmax(0,1fr)]">
                <aside class="border-b border-slate-200 bg-slate-50/70 p-3 lg:min-h-[600px] lg:border-b-0 lg:border-r dark:border-slate-700 dark:bg-slate-900/30">
                    <div class="flex gap-5 overflow-x-auto pb-1 lg:flex-col lg:gap-6 lg:overflow-visible" role="tablist" aria-label="Documentation sections">
                        <div v-for="group in docGroups" :key="group.label" class="min-w-max lg:min-w-0">
                            <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ group.label }}</p>
                            <div class="flex gap-2 lg:flex-col">
                                <button
                                    v-for="section in group.sections"
                                    :key="section.id"
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeSection === section.id"
                                    @click="activeSection = section.id"
                                    class="flex min-w-max items-center gap-3 rounded-xl px-3 py-3 text-left text-sm transition lg:w-full lg:min-w-0"
                                    :class="activeSection === section.id
                                        ? 'bg-blue-600 font-semibold text-white shadow-md shadow-blue-600/20'
                                        : 'text-slate-600 hover:bg-white hover:text-blue-600 hover:shadow-sm dark:text-slate-300 dark:hover:bg-slate-800'"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="activeSection === section.id ? 'bg-white/20' : 'bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300'">
                                        <i class="bi" :class="section.icon"></i>
                                    </span>
                                    <span class="truncate">{{ section.label }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </aside>

                <main class="min-w-0 p-4 sm:p-6 lg:p-8">
            <section v-show="activeSection === 'telegram-bot'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">1. Create a Telegram bot</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    In Telegram, message <code>@BotFather</code> → send <code>/newbot</code> → follow the prompts.
                    BotFather gives you a <strong>bot token</strong> that looks like <code>123456:ABC-DEF...</code>.
                    Keep it secret — it's the only credential needed to send/receive messages as that bot.
                </p>
            </section>

            <section v-show="activeSection === 'connect-channel'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">2. Connect the channel</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Go to <Link :href="panelRoute('chat-engine.channels.create')" class="text-blue-600 hover:underline">Channels → Connect Channel</Link>,
                    paste the bot token, optionally set a system prompt, and keep "Auto-reply with AI" checked. On
                    submit the panel automatically registers Telegram's webhook for you — no manual URL copying
                    needed.
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>Webhook URL pattern: {{ webhookBaseUrl }}/telegram/{channel-id}
Verified via:        X-Telegram-Bot-Api-Secret-Token header (unique per channel, generated automatically)</code></pre>
                <p class="mt-2 text-xs text-slate-400">
                    Telegram only accepts a public, valid-HTTPS webhook URL — make sure this server is reachable
                    that way before connecting a channel.
                </p>
            </section>

            <section v-show="activeSection === 'facebook-pages'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">2b. Connect Facebook Pages (messages + comments)</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Each <Link :href="panelRoute('chat-engine.facebook-apps.index')" class="text-blue-600 hover:underline">Facebook App</Link> you
                    register gets its <strong>own webhook URL</strong> — so different people/orgs each running their
                    own Facebook App never share an endpoint, a secret, or each other's pages. Once an app is set up,
                    "Connect via Facebook" auto-adds every page you approve — no manual Page ID or token pasting.
                </p>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>
                        In the <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener" class="text-blue-600 hover:underline">Meta developer dashboard</a>,
                        create an App, add the Messenger and Facebook Login products.
                    </li>
                    <li>
                        In dPanel, go to <Link :href="panelRoute('chat-engine.facebook-apps.index')" class="text-blue-600 hover:underline">AI Chat Engine → Facebook Apps → Register App</Link>
                        and enter that App's <strong>App ID</strong> and <strong>App Secret</strong> (from Settings → Basic).
                    </li>
                    <li>
                        The app's card now shows a <strong>Callback URL</strong> and <strong>Verify Token</strong>, both unique to
                        this app — paste both into that App's Webhooks product settings in the Meta dashboard, and
                        subscribe the <code>messages</code> and <code>feed</code> fields. Facebook calls the Callback URL once to
                        confirm you control it (a GET request with a challenge) — it must succeed first.
                    </li>
                    <li>
                        In the App's Facebook Login settings, add this exact <strong>Valid OAuth Redirect URI</strong> (one fixed
                        URL, shared by every app, since it only handles the login handshake — not messaging events):
                        <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>{{ webhookBaseUrl }}/facebook/oauth/callback</code></pre>
                    </li>
                    <li>
                        Under <strong>App Review → Permissions and Features</strong>, enable Advanced Access for
                        <code>pages_show_list</code>, <code>pages_manage_metadata</code>,
                        <code>pages_read_engagement</code>, and <code>pages_manage_engagement</code>. The last two are
                        required to receive Page comments and publish nested replies. Enable <code>pages_manage_posts</code>
                        to publish Page feed posts from the Facebook Posts module. Enable
                        <code>pages_messaging</code> for the Messenger product/App Review separately; dPanel deliberately does
                        not send it as a Facebook Login scope because Meta rejects it as an invalid login scope for affected apps.
                        App admins/developers/testers can test while the app is in Development mode; other Facebook users require
                        the app to be Live and the permissions approved by Meta. If any permission was declined, reconnect and approve it.
                    </li>
                    <li>
                        Back on the app's card, click <strong>Connect via Facebook</strong>, log in, and approve the pages you
                        want to expose. Every page you approve is created as a channel and auto-subscribed
                        immediately — check <Link :href="panelRoute('chat-engine.channels.index')" class="text-blue-600 hover:underline">Channels</Link>.
                    </li>
                </ol>
                <p class="mt-3 text-xs text-slate-400">
                    Public comment replies use the Graph API's <code>/{comment-id}/comments</code> endpoint —
                    the reply appears as a normal nested reply under the original comment, from the Page.
                    A legacy single shared URL (<code>{{ facebookWebhookUrl }}</code>, tied to the
                    <code>CHATENGINE_FACEBOOK_APP_SECRET</code>/<code>CHATENGINE_FACEBOOK_VERIFY_TOKEN</code> env vars) still works for
                    pages connected by pasting a Page ID/token manually on the Channels page, but Facebook Apps is
                    the recommended path going forward.
                </p>
            </section>

            <section v-show="activeSection === 'whatsapp-cloud'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Connect WhatsApp Cloud API</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    This integration uses Meta's official WhatsApp Cloud API. Each channel has a unique webhook URL and
                    verification token, while incoming webhook signatures are checked with that channel's Meta App Secret.
                </p>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>Create or open a Meta App and add the <strong>WhatsApp</strong> product.</li>
                    <li>Under WhatsApp → API Setup, copy the <strong>Phone Number ID</strong> and <strong>WhatsApp Business Account ID</strong>.</li>
                    <li>Create a permanent System User token with <code>whatsapp_business_messaging</code> and <code>whatsapp_business_management</code> access. Copy the App Secret from Settings → Basic.</li>
                    <li>Open <Link :href="panelRoute('chat-engine.channels.index')" class="text-blue-600 hover:underline">Channels</Link>, connect a WhatsApp channel, and enter those four values.</li>
                    <li>Edit the saved channel and copy its generated <strong>Callback URL</strong> and <strong>Verify Token</strong> into Meta App → WhatsApp → Configuration → Webhooks.</li>
                    <li>Subscribe the <code>messages</code> webhook field, then send a text message to the connected WhatsApp number.</li>
                </ol>
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    Meta's temporary access token expires quickly. Use a permanent System User token in production. The current workflow handles inbound and outbound text messages; unsupported media/status events are safely ignored.
                </div>
            </section>

            <section v-show="activeSection === 'instagram-dm'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Connect Instagram Direct Messages</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    dPanel uses Instagram API with Instagram Login for Professional accounts. A Facebook Page link is not required for this workflow.
                </p>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>Create a Meta App and add the Instagram API product with Instagram Login.</li>
                    <li>Connect an Instagram Business or Creator account and obtain its numeric account ID and access token.</li>
                    <li>Enable <code>instagram_business_manage_messages</code>; production users require Advanced Access and successful App Review.</li>
                    <li>Open <Link :href="panelRoute('chat-engine.channels.index')" class="text-blue-600 hover:underline">Channels</Link>, choose Instagram, and enter the Professional Account ID, access token, and Meta App Secret.</li>
                    <li>Edit the saved channel, copy its Callback URL and Verify Token into Instagram Webhooks, then subscribe the <code>messages</code> field.</li>
                    <li>Send a text DM from a different Instagram account. It will appear in Conversations and follow the same AI/manual reply controls as other channels.</li>
                </ol>
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    Replies are subject to Meta's messaging window and platform policies. The current integration processes text DMs; echoes, read receipts, reactions, and media-only events are ignored.
                </div>
            </section>

            <section v-show="activeSection === 'slack-app'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Connect a Slack App</h2>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>Create a Slack App for the target workspace and add a bot user.</li>
                    <li>Under OAuth &amp; Permissions, grant <code>chat:write</code> plus the history scopes required for the message surfaces you subscribe to, then install the app.</li>
                    <li>Copy the Workspace ID, Bot User OAuth Token (<code>xoxb-…</code>), and Signing Secret into a new Slack channel in dPanel.</li>
                    <li>Edit the saved channel and copy its Request URL into Slack App → Event Subscriptions.</li>
                    <li>Subscribe to bot events such as <code>message.im</code> for direct messages and <code>message.channels</code> for public channels. Invite the bot into any channel it should read.</li>
                    <li>Send a message to the bot. Slack sends a signed event to dPanel, which queues the same AI/manual conversation flow used by the other providers.</li>
                </ol>
                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-200">
                    Every request is verified using Slack's signing secret and timestamp; requests older than five minutes are rejected to prevent replay attacks.
                </div>
            </section>

            <section v-show="activeSection === 'worker'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">3. Keep the queue worker &amp; scheduler running</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Inbound messages and scheduled/broadcast sends are processed on the <code>chat</code> queue —
                    nothing happens without a worker running. Broadcasts are picked up by a scheduled command that
                    needs to run every minute.
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code># Long-running worker (use Supervisor/systemd in production)
php artisan queue:work --queue=chat

# Dispatches chatengine:dispatch-scheduled every minute; wire this into
# your server's crontab (see routes/console.php for the schedule entry)
* * * * * php artisan schedule:run >> /dev/null 2>&1</code></pre>
            </section>

            <section v-show="activeSection === 'reply-flow'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">4. How a reply actually happens</h2>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>A contact messages the bot on Telegram, sends a Messenger message to the Page, or leaves a comment on one of the Page's posts.</li>
                    <li>The platform <code>POST</code>s the event to the channel's webhook — Telegram: a per-channel URL; Facebook: the connected Page's own Facebook App's URL, routed to the right page internally by its Page ID.</li>
                    <li>The signature/secret is verified, then <code>ProcessInboundChatMessageJob</code> is queued.</li>
                    <li>The job saves the inbound message (tagged as a <em>message</em> or a public <em>comment</em>), and — if the conversation's AI toggle and the channel's auto-reply are both on — sends the recent conversation history plus the channel's system prompt to the existing <Link :href="panelRoute('ai-gateway.dashboard')" class="text-blue-600 hover:underline">AI Gateway</Link> (<code>AiGatewayService::chatAuto()</code>), which picks a provider/model automatically.</li>
                    <li>The AI's reply is saved and sent back the same way it came in: a Messenger/Telegram message stays private, a comment gets a public nested reply under it via the Graph API.</li>
                    <li>Everything shows up under <Link :href="panelRoute('chat-engine.conversations.index')" class="text-blue-600 hover:underline">Conversations</Link>, where you can turn AI off for one conversation and reply manually instead — a manual reply is routed the same way (private message vs. comment reply) as the last inbound message.</li>
                </ol>
            </section>

            <section v-show="activeSection === 'scheduled'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">5. Scheduled &amp; broadcast messages</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Under <Link :href="panelRoute('chat-engine.scheduled-messages.index')" class="text-blue-600 hover:underline">Scheduled Messages</Link>, pick a channel, an audience
                    (every contact on that channel, or one specific contact by ID), a message, and a run time. When
                    <code>chatengine:dispatch-scheduled</code> next runs and the time is due, it creates one delivery
                    per recipient and sends them via a queued job — the Sent/Failed counters update live.
                </p>
            </section>

            <section v-show="activeSection === 'troubleshooting'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Troubleshooting</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>No reply arriving? Confirm a queue worker is actually running, then check <code>storage/logs/laravel.log</code> for <code>ChatEngine: AI reply failed</code>.</li>
                    <li>Webhook never hit at all? Open <Link :href="panelRoute('chat-engine.channels.index')" class="text-blue-600 hover:underline">Channels</Link> → <em>Edit</em> → <em>Reconnect</em>, and double-check the bot token / page access token.</li>
                    <li>Facebook webhook verification (step 2b) failing? Check <code>CHATENGINE_FACEBOOK_APP_SECRET</code> and <code>CHATENGINE_FACEBOOK_VERIFY_TOKEN</code> are set and match exactly what's in the Meta dashboard.</li>
                    <li>Facebook events arriving but ignored? Confirm the Page ID entered when connecting the channel matches the Page's actual numeric ID, and that <code>messages</code>/<code>feed</code> are both subscribed in the Meta dashboard.</li>
                    <li>Want a human to take over? Open the conversation and switch "AI Auto-reply" to Off — messages still arrive, but no AI reply is generated until you turn it back on.</li>
                </ul>
            </section>
                </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

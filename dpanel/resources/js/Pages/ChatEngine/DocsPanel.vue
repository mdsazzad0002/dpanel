<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    // Section ids to show, in order — only content relevant to the page this
    // panel was opened from, never the full cross-feature doc set.
    sections: { type: Array, required: true },
});

const webhookBaseUrl = window.location.origin + '/webhooks/chat';
const facebookWebhookUrl = route('webhooks.chat.facebook');

const allGroups = [
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
            { id: 'facebook-posts', label: 'Publish Posts & Comments', icon: 'bi-file-post' },
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
        label: 'Website Widget',
        sections: [
            { id: 'website-widget', label: 'Embed on a website', icon: 'bi-window' },
        ],
    },
    {
        label: 'Business AI Guide',
        sections: [
            { id: 'business-training', label: 'Train the AI (Q&A)', icon: 'bi-mortarboard' },
            { id: 'business-live-data', label: 'Live data & actions', icon: 'bi-plug' },
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

// Only the groups/sections relevant to the page this panel was opened from —
// filtered down from the full catalog above, in the caller's requested order.
const docGroups = allGroups
    .map((group) => ({ ...group, sections: group.sections.filter((s) => props.sections.includes(s.id)) }))
    .filter((group) => group.sections.length);

const activeSection = ref(props.sections[0]);
const totalSectionCount = computed(() => docGroups.reduce((n, g) => n + g.sections.length, 0));
</script>

<template>
    <!--
        Always a single stacked column: this panel renders inside a fixed-width
        offcanvas, and its width is independent of the viewport — a viewport
        breakpoint (e.g. lg:) would force a cramped two-column layout on any
        desktop-width screen even though the panel itself stays narrow. A
        horizontal, wrapping tab row above the content works at every panel
        width instead.
    -->
    <div>
        <div v-if="totalSectionCount > 1" class="mb-4 flex flex-wrap gap-x-4 gap-y-3 border-b border-slate-200 pb-3 dark:border-slate-700" role="tablist" aria-label="Documentation sections">
            <div v-for="group in docGroups" :key="group.label">
                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ group.label }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="section in group.sections"
                        :key="section.id"
                        type="button"
                        role="tab"
                        :aria-selected="activeSection === section.id"
                        @click="activeSection = section.id"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-left text-xs transition"
                        :class="activeSection === section.id
                            ? 'bg-blue-600 font-semibold text-white'
                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                    >
                        <i class="bi shrink-0" :class="section.icon"></i>
                        <span>{{ section.label }}</span>
                    </button>
                </div>
            </div>
        </div>

        <main class="min-w-0">
            <section v-show="activeSection === 'telegram-bot'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">1. Create a Telegram bot</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    In Telegram, message <code>@BotFather</code> → send <code>/newbot</code> → follow the prompts.
                    BotFather gives you a <strong>bot token</strong> that looks like <code>123456:ABC-DEF...</code>.
                    Keep it secret — it's the only credential needed to send/receive messages as that bot.
                </p>
            </section>

            <section v-show="activeSection === 'connect-channel'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
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

            <section v-show="activeSection === 'facebook-pages'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
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

            <section v-show="activeSection === 'facebook-posts'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Publish Page posts &amp; reply to comments</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Any active Facebook channel (connected via Facebook Apps or manually) can publish directly to its
                    Page's feed from here — no separate Facebook login needed, it uses the channel's own Page
                    Access Token.
                </p>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>Click <strong>Create Post</strong>, pick the Page (channel) to publish to, and write the post text.</li>
                    <li>Optionally add a link (shown as a link preview) and a first comment, posted automatically right after the post goes live.</li>
                    <li>Publish — the post appears in the feed below with a link back to it on Facebook.</li>
                </ol>
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                    Comments left on tracked posts show up in the feed too. Replying to one here uses the same
                    Graph API nested-reply endpoint as an AI/manual auto-reply to a comment — it posts as a normal
                    public reply under that comment, from the Page.
                </p>
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    Publishing requires the <code>pages_manage_posts</code> permission on that Page's Facebook App (see
                    the Facebook Pages guide) — a channel without it can still receive messages/comments but can't
                    publish new posts.
                </div>
            </section>

            <section v-show="activeSection === 'whatsapp-cloud'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
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

            <section v-show="activeSection === 'instagram-dm'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
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

            <section v-show="activeSection === 'slack-app'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
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

            <section v-show="activeSection === 'website-widget'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Embed the AI chat on your own website</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Create a channel with type <strong>Website Widget</strong> (no credentials needed), save it, then
                    copy the generated <code>&lt;script&gt;</code> tag from the channel's edit screen into your site's
                    HTML — it auto-connects, no other setup required.
                </p>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Each visitor gets a token stored in their browser so returning to the page continues the same
                    conversation. Assign the channel to a <Link :href="panelRoute('chat-engine.businesses.index')" class="text-blue-600 hover:underline">Business</Link> so
                    it answers from that business's trained knowledge.
                </p>
            </section>

            <section v-show="activeSection === 'business-training'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Train the AI on a business</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Create a <Link :href="panelRoute('chat-engine.businesses.index')" class="text-blue-600 hover:underline">Business</Link>, write (or AI-draft) its
                    description — this is the AI's primary knowledge about who it is, what it does, and how to contact
                    it — then add Products and Q&A pairs under each. Assign one or more channels to the business so
                    their conversations use this training data.
                </p>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    The AI answers <strong>only</strong> from this trained data — it's explicitly instructed to
                    decline rather than guess when a question isn't covered, so it never invents prices, policies, or
                    facts. Use "✨ Suggest Q&A" on a product to have AI draft candidate questions/answers for you to
                    review and add.
                </p>
            </section>

            <section v-show="activeSection === 'business-live-data'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Live data &amp; real actions</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    On a business's edit page, beyond static Q&A, you can connect <strong>one</strong> integration endpoint on your own site that covers search, orders, email, and SMS — no separate URL per capability. Set the base URL and (optionally) an API key once, then tick which capabilities are enabled.
                </p>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Every enabled capability calls the <strong>same URL</strong>. We <code>POST</code> JSON with a <code>tool</code> field telling you which one was invoked — you route internally on your side:
                </p>
                <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 px-4 py-3 text-xs text-slate-100"><code>POST {your integration_base_url}
Authorization: Bearer {your api key, if set}

{"tool": "search",      "query": "..."}
{"tool": "place_order",  "items": [{"name":"...","quantity":1}], "customer_name": "...", "customer_phone": "...", "customer_address": "...", "notes": "..."}
{"tool": "send_email",   "to": "...", "subject": "...", "body": "..."}
{"tool": "send_sms",     "to": "...", "message": "..."}

Response (all tools): {"result": "plain text the AI relays to the customer"}</code></pre>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    <strong>Search</strong> is called on demand when a customer's question isn't answered by trained Q&A. <strong>Order / Email / SMS</strong> are only called after the AI has confirmed the details with the customer.
                </p>
                <p class="mt-2 text-xs text-slate-400">
                    The URL must be a public https address — localhost and private/internal IPs are rejected. A capability only becomes active when its checkbox is on <em>and</em> the URL is set; leave the URL blank to disable everything.
                </p>
            </section>

            <section v-show="activeSection === 'worker'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
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

            <section v-show="activeSection === 'reply-flow'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">4. How a reply actually happens</h2>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>A contact messages the bot on Telegram, sends a Messenger message to the Page, leaves a comment on one of the Page's posts, or sends a message through an embedded website widget.</li>
                    <li>The platform <code>POST</code>s the event to the channel's webhook (or, for the website widget, the visitor's browser calls the widget endpoint directly and waits for the reply in the same response).</li>
                    <li>The signature/secret is verified, then <code>ProcessInboundChatMessageJob</code> is queued (the website widget skips the queue and replies synchronously).</li>
                    <li>The job saves the inbound message (tagged as a <em>message</em> or a public <em>comment</em>), and — if the conversation's AI toggle and the channel's auto-reply are both on — sends the recent conversation history plus the channel's system prompt and, if a business is assigned, its trained knowledge/live data/tools, to the existing <Link :href="panelRoute('ai-gateway.dashboard')" class="text-blue-600 hover:underline">AI Gateway</Link> (<code>AiGatewayService::chatAuto()</code>), which picks a provider/model automatically.</li>
                    <li>If the AI calls an order/email/SMS tool, the panel executes it against the business's configured endpoint and feeds the result back before producing the final reply.</li>
                    <li>The AI's reply is saved and sent back the same way it came in: a Messenger/Telegram message stays private, a comment gets a public nested reply under it via the Graph API.</li>
                    <li>Everything shows up under <Link :href="panelRoute('chat-engine.conversations.index')" class="text-blue-600 hover:underline">Conversations</Link>, where you can turn AI off for one conversation and reply manually instead — a manual reply is routed the same way (private message vs. comment reply) as the last inbound message.</li>
                </ol>
            </section>

            <section v-show="activeSection === 'scheduled'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">5. Scheduled &amp; broadcast messages</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Under <Link :href="panelRoute('chat-engine.scheduled-messages.index')" class="text-blue-600 hover:underline">Scheduled Messages</Link>, pick a channel, an audience
                    (every contact on that channel, or one specific contact by ID), a message, and a run time. When
                    <code>chatengine:dispatch-scheduled</code> next runs and the time is due, it creates one delivery
                    per recipient and sends them via a queued job — the Sent/Failed counters update live.
                </p>
            </section>

            <section v-show="activeSection === 'troubleshooting'" role="tabpanel" class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-sm font-semibold">Troubleshooting</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                    <li>No reply arriving? Confirm a queue worker is actually running, then check <code>storage/logs/laravel.log</code> for <code>ChatEngine: AI reply failed</code>.</li>
                    <li>Webhook never hit at all? Open <Link :href="panelRoute('chat-engine.channels.index')" class="text-blue-600 hover:underline">Channels</Link> → <em>Edit</em> → <em>Reconnect</em>, and double-check the bot token / page access token.</li>
                    <li>Facebook webhook verification (step 2b) failing? Check <code>CHATENGINE_FACEBOOK_APP_SECRET</code> and <code>CHATENGINE_FACEBOOK_VERIFY_TOKEN</code> are set and match exactly what's in the Meta dashboard.</li>
                    <li>Facebook events arriving but ignored? Confirm the Page ID entered when connecting the channel matches the Page's actual numeric ID, and that <code>messages</code>/<code>feed</code> are both subscribed in the Meta dashboard.</li>
                    <li>Order/Email/SMS action not happening? Confirm the corresponding URL is saved on the business, is a public https address, and that your endpoint returns <code>{"result": "..."}</code> with a 2xx status within 8 seconds.</li>
                    <li>Want a human to take over? Open the conversation and switch "AI Auto-reply" to Off — messages still arrive, but no AI reply is generated until you turn it back on.</li>
                </ul>
            </section>
        </main>
    </div>
</template>

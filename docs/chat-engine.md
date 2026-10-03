# AI Chat Engine

The Chat Engine answers customer messages with AI on Telegram, Facebook (Messenger and Page comments), WhatsApp, Instagram, Slack and an embeddable website widget.

Everything starts from a **business**. A business owns its **apps** (the connected chat channels) and the knowledge the AI answers them with.

- [Business and apps](#business-and-apps)
- [Transferring apps](#transferring-apps)
- [What the AI can reply](#what-the-ai-can-reply)
- [When the AI does not reply](#when-the-ai-does-not-reply)
- [Routes and code](#routes-and-code)

## Business and apps

Open **AI Chat Engine → Businesses** and pick a business. The page has two tabs.

### Apps tab

Every app attached to the business, with everything you can do to it:

| Action | What it does |
| --- | --- |
| Connect new app | Creates a Telegram / Facebook / WhatsApp / Instagram / Slack / website app already attached to this business |
| Attach existing app | Pulls in an app that has no business yet |
| Messages | Opens that app's conversations |
| Assistant | Website apps only: chat with the AI inside the panel to test its replies |
| Edit | Credentials, system prompt, AI auto-reply, staff-only mode |
| Live / Paused | Turns the app on or off. A paused app ignores incoming messages |
| Schedule message | Broadcast or one-off send from this app |
| Reconnect webhook | Registers the platform webhook again |
| Transfer | Moves the app to another business |
| Detach | Removes the app from the business, leaving it running on its own system prompt |
| Delete | Deletes the app with its contacts, conversations and scheduled messages |

**Inbox** and **Scheduled** at the top of the tab show only this business's apps. Tick apps, or use **Select all**, to transfer or detach several at once.

**Channels** in the sidebar still lists every app across all businesses. Each app shows which business it belongs to, and you can filter the list by business.

### Manage tab

- Business details: name, industry, description (with an AI writer), and reply language
- Client integration: one URL plus API key, with Search, Order, Email and SMS switches
- Products and Q&A: add, edit and delete them, or let the AI draft Q&A for you to review
- AI reply usage against the owner's package limit
- AI readiness checklist
- Delete the business. Its apps are detached, not deleted

## Transferring apps

Transferring changes which business an app answers for. In detail:

- Contacts, conversations and scheduled messages stay with the app. Nothing is deleted, and the platform webhook keeps working.
- From the next incoming message, the AI uses the new business's profile, products, Q&A, reply language and tools.
- AI replies count against the new business's reply credit.
- The app's owner becomes the new business's owner, so that owner can see and manage it.
- You can only transfer to a business you are allowed to see. Admins see all businesses; resellers see their own and their clients' businesses.

Endpoint: `POST chat-engine/businesses/{business}/apps/transfer` with `channel_ids[]` and `target_business_id` (`null` detaches).

## What the AI can reply

A **public app** is anything customers reach directly, such as a bot, a Page or a website widget. A **staff-only app** has *Internal / staff-only* turned on. Use staff-only apps only from the panel's Assistant page, and never embed them on a public site.

| Reply type | Public app | Staff-only app | Needs |
| --- | :---: | :---: | --- |
| Answers from the business profile, products and Q&A | ✓ | ✓ | Description and/or Q&A |
| A first-message greeting that introduces the business and what it offers | ✓ | ✓ | Description or products |
| Replies in a fixed language, or in the customer's language | ✓ | ✓ | Reply language (optional) |
| Replies to voice notes, transcribed by whisper.cpp | ✓ | ✓ | `media_transcribe_api_url` |
| Replies to images, using only the text read from them by OCR | ✓ | ✓ | `media_ocr_api_url` |
| A public reply under a Facebook Page comment | ✓ | — | Facebook app with the `feed` webhook |
| Saves the customer's name, phone and email (`save_contact_info`) | ✓ | ✓ | Always available |
| Live product, stock and price search | ✓ | ✓ | Integration URL + Search |
| Looks up a customer, their due, or an invoice | — | ✓ | Integration URL + Search |
| Places an order after confirming the details | ✓ | ✓ | Integration URL + Order |
| Sends an email | — | ✓ | Integration URL + Email |
| Sends an SMS | — | ✓ | Integration URL + SMS |
| Bulk due reminders: preview first, send only after a "yes" | — | ✓ | Search + SMS |

### Rules the AI follows

- **Context:** the AI sees the latest messages of the conversation (`CHATENGINE_CONTEXT_MESSAGE_LIMIT`, default 20).
- **No guessing:** it never makes up prices, policies, stock or contact details. If neither the knowledge nor a tool can answer, it replies with exactly this line:
  > I don't have that information yet — let me connect you with a team member who can help.

  Then it asks for the customer's phone number so your team can follow up.
- **Tools before questions:** if the search tool could find something (a phone number, a due amount), the AI searches before asking the customer.
- **Privacy on public apps:** it never shares another person's phone number, due or order history.
- **Tool rounds:** it can make up to 3 rounds of tool calls (for example, a search followed by an order) before writing its reply.
- **Tone:** it writes like a professional support agent. It skips repeated "How can I help you?" lines and doesn't repeat the business phone number in every message.
- **Apps with no business:** the AI uses only the app's own system prompt, plus `save_contact_info`.

## When the AI does not reply

| Cause | Fix |
| --- | --- |
| The app is paused | Turn it to **Live** on the Apps tab |
| AI auto-reply is off for the app | Turn it on with **Edit → Auto-reply** |
| The conversation was switched to manual | Open the conversation and turn **AI Auto-reply** back on |
| The business has used all its reply credit | Upgrade the owner's package |
| The AI provider failed or returned an empty reply | Look for `ChatEngine: AI reply failed` in `storage/logs/laravel.log` |
| No queue worker is running | Start the `chat` queue worker |

In every case the incoming message is still saved in the inbox.

## Routes and code

| Area | File |
| --- | --- |
| Business page (Apps + Manage tabs) | `dpanel/resources/js/Pages/ChatEngine/Businesses/Edit.vue`, `Businesses/Apps/`, `Businesses/Manage/` |
| Business payload | `dpanel/app/Http/Controllers/ChatEngineBusinessController.php` (`edit`) |
| App transfer | `dpanel/app/Http/Controllers/ChatEngineBusinessAppController.php` |
| App card data | `dpanel/app/Services/ChatEngine/ChannelPresenter.php` |
| Reply pipeline | `dpanel/app/Services/ChatEngine/ChatEngineService.php` |
| Knowledge prompt | `dpanel/app/Services/ChatEngine/BusinessKnowledgeService.php` |
| Search, order, email and SMS tools | `dpanel/app/Services/ChatEngine/BusinessToolService.php` |
| In-panel guide | `dpanel/resources/js/Pages/ChatEngine/DocsPanel.vue` (`business-apps`, `reply-capabilities`) |

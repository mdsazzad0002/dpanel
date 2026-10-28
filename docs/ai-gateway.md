# AI Gateway: images

The AI Gateway can take images in chat and generate new ones. This guide
covers how images reach a model, how to set up image generation, and the API.
Both work in the panel's **AI Gateway → Chat** page and through the external
OpenAI-compatible API (`/api/v1`).

## Model capabilities

Each model under **AI Gateway → Models** has capability tags. Tick them when
you add or edit a model. Three of them change how requests are routed:

| Capability | Effect |
| --- | --- |
| `vision` | The model sees images itself. Requests with images prefer these models. |
| `image_generation` | The model makes images. Image requests only go to these models. |
| `chat` | The model answers chat messages. A model tagged `image_generation` **without** `chat` never gets chat requests. |
| `tools` | The model supports function calling (used by server tools in the chat). |
| `code` | Informational only. |

The default catalogs already tag the common models. For models you added
yourself, check the tags: an untagged model is treated as having no vision.

## Images in chat

Attach images in the chat with the paperclip button, by pasting, or by
dragging them onto the message box. You can send up to 8 images per message,
each at most 10 MB, as PNG, JPEG, WebP or GIF.

What the model receives depends on its `vision` tag:

| Model | What it gets |
| --- | --- |
| Tagged `vision` | The image itself, in the provider's own format (OpenAI `image_url`, Anthropic `image` block, Gemini `inline_data`). **No OCR runs.** |
| Not tagged `vision` | The text that OCR reads from the image, added to the message, plus a note that the model cannot see the image. |

In **Auto** mode, a message with images goes to a `vision` model first. Other
models are only a fallback when no vision model is available. If you pin a
model that cannot see images, the chat warns that OCR will be used.

### OCR (internal text extraction)

OCR runs in drust (`POST /api/v1/media/ocr`) with Tesseract and reads
English and Bengali. It only runs for models without `vision`. The result is
cached for the request, so failover to another model does not read the same
image twice.

- drust must be built with the `ocr` feature, which needs Tesseract 5. On a
  system with an older Tesseract, OCR is unavailable. The model is then told
  that the image could not be read, and the request still goes through.
- The endpoint takes a downloadable `url`, or the file itself as base64
  `data`. The panel sends `data`, because chat uploads have no public URL.

## Image generation

### Setup

1. Add a model that can make images to a provider, under **AI Gateway →
   Models**:
   - OpenAI and OpenAI-compatible providers: e.g. `gpt-image-1` or
     `dall-e-3`. These use the provider's `/images/generations` endpoint.
   - Google Gemini: e.g. `gemini-2.5-flash-image`. This uses `generateContent`
     with the image output modality.
   - Anthropic models cannot generate images.
2. Tick **image generation** on that model. Leave **chat** unticked, so the
   model does not get chat requests.

New OpenAI and Gemini providers include `gpt-image-1` and
`gemini-2.5-flash-image` in their default model list.

### In the chat

Switch the chat to **Image** (next to the model picker; the switch only
appears when an image model exists). Then describe the image and press
**Generate**. The model picker lists only image models. In Auto mode, the
gateway picks one and fails over to the next one if a model fails.
Generated images appear in the reply, are saved with the chat history, and
open full size when clicked.

## Storage and access

- Uploaded and generated images are stored under
  `storage/app/private/ai-gateway/media/<user id>/`.
- The panel serves them only to their owner, and to admins, who can also
  open other users' chats.
- Chat transcripts refer to images as `![image](dpanel-media:<id>)` lines.
  Deleting a chat does not delete its images.
- The request log records an image as its type and size, never its data.
- Image requests are logged with the operation `image`. Their cost is
  recorded as 0, because image pricing is per image, not per token.

## API

All endpoints use `Authorization: Bearer sk-ag-...` (an AI Gateway API key)
and are routed automatically, so `model` must be omitted or `"auto"`.

### Images in `POST /api/v1/chat/completions`

Send OpenAI-style content parts. Images must be inline `data:` URLs; the
gateway does not download image links and rejects them with
`400 invalid_content`.

```json
{
  "model": "auto",
  "messages": [{
    "role": "user",
    "content": [
      { "type": "text", "text": "What does this screenshot say?" },
      { "type": "image_url", "image_url": { "url": "data:image/png;base64,iVBORw0KG..." } }
    ]
  }]
}
```

### Server tools in `POST /api/v1/chat/completions`

Add `"server_tools": "read"` and the gateway answers questions about this
server itself: the model calls the panel's read-only MCP tools (overview,
service status, logs, files, diagnostic commands) until it can answer.

- Only `"read"` (or `"off"`) is accepted; the API never gets full access.
- The API key must have been created by an admin, and MCP must be enabled.
  Otherwise the request fails with `400 server_tools_unavailable`.
- It cannot be combined with your own `tools`.
- Calls use the same "AI Gateway Chat (read-only)" MCP token as the panel
  chat, so turning that token off under Settings → MCP Access turns this off
  too.

Each tool step is reported when it starts and when it finishes. With
`"stream": true` it arrives as a normal chunk with an empty `delta` and a
`dpanel_tool_step` field, which OpenAI clients that don't know it ignore:

```
data: {"object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":null}],"dpanel_tool_step":{"id":"call_1","name":"get_service_status","arguments":{"service":"mariadb"},"status":"running"}}

data: {"object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":null}],"dpanel_tool_step":{"id":"call_1","name":"get_service_status","status":"ok","preview":"● mariadb.service ...","duration_ms":84}}
```

Without streaming, the finished steps are listed in `dpanel_tool_steps` on
the response.

### `POST /api/v1/images/generations`

```json
{ "prompt": "A lighthouse at dusk, watercolor", "size": "1024x1024", "n": 1 }
```

- `prompt` (required, up to 4000 characters)
- `n`: 1–4
- `size`: e.g. `1024x1024`; models that pick their own size ignore it.

The response:

```json
{
  "created": 1730000000,
  "model": "gpt-image-1",
  "provider": "OpenAI",
  "data": [{ "b64_json": "iVBORw0KG...", "mime_type": "image/png" }]
}
```

Images always come back as base64 in `b64_json`. When the model rewrote the
prompt or added a caption, that text is in `revised_prompt`.

## Settings

| Setting | Default | Meaning |
| --- | --- | --- |
| `AIGATEWAY_IMAGE_TIMEOUT` | `180` | Seconds to wait for one image generation call |
| `SERVERPANEL_MEDIA_OCR_API_URL` | drust `/api/v1/media/ocr` | Where OCR requests go |

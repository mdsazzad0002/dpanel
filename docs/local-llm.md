# Local LLM runner (drust-llm)

`drust-llm` runs GGUF models on the server itself through llama.cpp and
serves an OpenAI-compatible API. The AI Gateway uses it like any other
OpenAI-compatible provider, so chats can run without a cloud API key.

It is a separate service from drust. A model that runs out of memory takes
down only `drust-llm`; drust and the panel keep running.

## What to expect

It runs on the CPU only. Pick a small, quantized model:

| Server RAM | Model size | Example |
| --- | --- | --- |
| 4 GB | 0.5–1.5B, Q4 | `qwen2.5-1.5b-instruct-q4_k_m.gguf` (~1 GB) |
| 8 GB | up to 3–4B, Q4 | `qwen2.5-3b-instruct-q4_k_m.gguf` (~2 GB) |
| 16 GB | up to 7–8B, Q4 | `llama-3.1-8b-instruct-q4_k_m.gguf` (~5 GB) |

Expect a few tokens per second from a 3B model on a 4-core VPS. A model
handles one request at a time; further requests wait in a queue.

Local models get text only. They cannot read images, generate images or
call tools, so leave the `vision`, `image_generation` and `tools` tags off.

## Install

The runner is opt-in, because building llama.cpp takes several minutes:

```bash
sudo DRUST_INSTALL_LLM=1 bash /var/www/drust/deploy/install-service.sh
```

This builds `drust/llm`, creates the `drust-llm` system user and
`/var/lib/drust/llm`, writes `/etc/drust/llm.env` with a random API key, and
starts `drust-llm.service`. After that, every run of the installer rebuilds
it automatically.

```bash
sudo systemctl status drust-llm
sudo journalctl -u drust-llm -f
sudo grep DRUST_LLM_API_KEY /etc/drust/llm.env
```

## Manage it from the panel

**AI Gateway → Local Models** (admins only) does everything below without a
terminal:

- shows whether drust-llm is running, free memory, and the loaded models
- downloads a recommended model in one click, or any GGUF link, with progress
- loads, unloads and deletes models
- **Connect to AI Gateway** creates the "Local models (this server)"
  provider and adds a model row per installed model. After a download
  finishes, the page syncs the new model automatically.

The installer writes the runner's key to the panel's `.env` as
`AIGATEWAY_LOCAL_LLM_KEY`. Settings such as context size and threads are
shown on the page but are changed in `/etc/drust/llm.env`.

## Add a model

Download a GGUF file, for example from Hugging Face. Use the `resolve` link,
not the `blob` page:

```bash
KEY=$(sudo grep -oP '(?<=DRUST_LLM_API_KEY=).*' /etc/drust/llm.env)

curl -s -X POST http://127.0.0.1:9510/admin/models/pull \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"url":"https://huggingface.co/Qwen/Qwen2.5-3B-Instruct-GGUF/resolve/main/qwen2.5-3b-instruct-q4_k_m.gguf"}'

# Progress
curl -s http://127.0.0.1:9510/admin/models -H "Authorization: Bearer $KEY"
```

You can also copy a `.gguf` file into `/var/lib/drust/llm/` yourself. The
model id is the file name without `.gguf`.

## Connect it to the AI Gateway

The **Connect to AI Gateway** button on the Local Models page does this for
you. To do it by hand: under **AI Gateway → Providers**, add a provider with the **OpenAI** driver
and replace its base URL:

- Base URL: `http://127.0.0.1:9510/v1`
- API key: `DRUST_LLM_API_KEY` from `/etc/drust/llm.env`
- Models: the model ids from `/v1/models`, tagged `chat`, with a price of 0

## How models are loaded

- A model is loaded on its first request. Loading takes from a few seconds
  to a minute, depending on its size.
- `DRUST_LLM_MAX_LOADED` models stay in memory (default 1). Loading another
  one unloads the least recently used model.
- A model with no requests for `DRUST_LLM_IDLE_UNLOAD_SECS` (default 900) is
  unloaded.
- A model larger than the server's available memory is refused with
  `503 model_load_failed`, so it can't push the server into swap.

## API

Every route needs `Authorization: Bearer <DRUST_LLM_API_KEY>`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Liveness and the loaded models (no key needed) |
| `GET` | `/v1/models` | Installed models, OpenAI format |
| `POST` | `/v1/chat/completions` | Chat, with `"stream": true` for SSE |
| `GET` | `/admin/models` | Installed models with size and loaded state, plus running downloads |
| `POST` | `/admin/models/pull` | Download a GGUF: `{"url": "https://...", "id": "optional-name"}` |
| `POST` | `/admin/models/{id}/load` | Load a model now |
| `POST` | `/admin/models/{id}/unload` | Free a model's memory |
| `DELETE` | `/admin/models/{id}` | Unload a model and delete its file |

`/v1/chat/completions` accepts `model` (or `"auto"`: the configured default,
otherwise the only installed model), `messages`, `stream`, `max_tokens` /
`max_completion_tokens`, `temperature`, `top_p`, `frequency_penalty`,
`presence_penalty`, `seed`, `stop` and `stream_options.include_usage`. The
prompt is built with the chat template stored in the model file, or ChatML
for models that don't include one.

```bash
curl -N http://127.0.0.1:9510/v1/chat/completions \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"model":"qwen2.5-3b-instruct-q4_k_m","stream":true,
       "messages":[{"role":"user","content":"Say hello in Bengali"}]}'
```

## Settings

`/etc/drust/llm.env` (restart `drust-llm` after changing it):

| Setting | Default | Meaning |
| --- | --- | --- |
| `DRUST_LLM_BIND` | `127.0.0.1:9510` | Listen address. Keep it on localhost. |
| `DRUST_LLM_API_KEY` | (required) | Bearer key for every route |
| `DRUST_LLM_MODELS_DIR` | `/var/lib/drust/llm` | Where `.gguf` files live |
| `DRUST_LLM_DEFAULT_MODEL` | — | Model used for `"auto"` |
| `DRUST_LLM_CTX_SIZE` | `4096` | Context window per request, capped at the model's own |
| `DRUST_LLM_MAX_TOKENS` | `2048` | Upper limit for `max_tokens` |
| `DRUST_LLM_THREADS` | cores − 1 | CPU threads per generation |
| `DRUST_LLM_MAX_LOADED` | `1` | Models kept in memory at once |
| `DRUST_LLM_IDLE_UNLOAD_SECS` | `900` | Unload after this many idle seconds; `0` = never |

The systemd unit caps the service at 90% of RAM (`MemoryMax`), so a runaway
model is killed before it can starve the panel.

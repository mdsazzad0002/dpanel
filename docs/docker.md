# Docker (optional add-on)

dPanel can run Docker containers next to your websites: Redis, n8n, Uptime
Kuma, a database for one app, or your own images. Docker is **off by default**.
The main installer never installs it, so a server that never runs containers
does not pay for it. You add it yourself when you need it.

## Contents

- [Should I install it?](#should-i-install-it)
- [What it costs](#what-it-costs)
- [Install](#install)
- [Check status](#check-status)
- [Use it in the panel](#use-it-in-the-panel)
- [How it works](#how-it-works)
- [Security](#security)
- [Log limits](#log-limits)
- [Remove](#remove)
- [Troubleshooting](#troubleshooting)

## Should I install it?

| You want to… | Docker? |
| --- | --- |
| Host PHP, WordPress, Laravel, Node.js or Python sites | **No.** dPanel runs these natively. |
| Run a ready-made app that ships as an image (n8n, Uptime Kuma, Gitea…) | Yes |
| Run a service in a version the OS does not package | Yes |
| Keep a small VPS (1 GB RAM) as lean as possible | No, or only for one small container |

## What it costs

Measured on Ubuntu 24.04 with Docker 29, with no containers running:

| | Cost |
| --- | --- |
| RAM while idle | ~90 MB (dockerd ~55 MB + containerd ~33 MB) |
| CPU while idle | ~0.1% |
| Disk for the engine | ~350 MB of packages |
| Disk for images | Extra, per image: `nginx:alpine` ~50 MB, `mysql` ~600 MB |
| Container logs | At most 30 MB per container (3 × 10 MB, see [Log limits](#log-limits)) |

Each container you run uses its own RAM and CPU on top of this.

## Install

On a server that already runs dPanel, as a user with `sudo`:

```bash
sudo dpanel docker
```

The installer is interactive. It:

1. **Checks the server.** It refuses (unless you pass `--force`) when the server
   has less than 1 GB RAM or 3 GB free disk, or the OS is not supported. It
   warns below 2 GB RAM or 10 GB of free disk, when Docker Hub is unreachable,
   or when the drust service is down.
2. **Shows the cost** from the table above.
3. **Asks** `Install Docker now? [y/N]`. Pressing Enter means no, and nothing changes.
4. **Installs and verifies.** It installs `docker.io` and `docker-compose-v2`
   (Debian/Ubuntu) or `docker-ce` from Docker's repository (Rocky/AlmaLinux),
   writes the [log limits](#log-limits) and waits until the daemon answers.

Other ways to run the same installer:

```bash
# From the repository, without a dPanel shell session open
curl -fsSL https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/docker-installer.sh -o docker-installer.sh
sudo bash docker-installer.sh

# From the interactive menu: option 24
sudo dpanel

# Without prompts (automation); --force skips the RAM and disk limits
sudo dpanel docker install --yes
```

`chain update` never installs Docker. It only updates Docker on servers where
Docker is already installed.

## Check status

```bash
sudo dpanel docker status
```

```text
Docker status
  [ OK ] Docker 29.1.3 is running
  [ OK ] Containers: 2 running, 3 in total
  [ OK ] Images: 4
  [ OK ] Docker daemons use 88 MB RAM
  [ OK ] Container logs are size-capped
```

It ends with `docker system df`, which shows how much disk images, containers
and volumes use, and how much of it can be reclaimed.

## Use it in the panel

**Docker** shows in the menu only when Docker is installed, and only for
admins. Until it is installed, `/docker` shows a setup page with the command
above and a **Check again** button.

| Page | What you can do |
| --- | --- |
| **Overview** | Running and stopped counts, containers that need a look (crash-looping, unhealthy, exited with an error), disk use, one-click clean-up, engine facts |
| **Containers** | Run any image, or paste a `docker run …` command to fill the form. Filter, select several for start/stop/restart/remove, watch live CPU and RAM. Each container has Overview, Logs (live, filter, download), Console (one command at a time, `sh -c`, one-minute limit), Variables, Storage and Network tabs, plus Edit & recreate, Duplicate, Update image, Rename, Pause, Kill |
| **Stacks** | Paste or upload a `docker-compose.yml` with a `.env`, then deploy, start, stop, restart, update images, take down or delete. Per-service controls and logs, editing with a check before saving, warnings for public ports, privileged services and the Docker socket |
| **App templates** | One-click Redis, PostgreSQL, MySQL, MariaDB, MongoDB, Elasticsearch, Meilisearch, Typesense, Qdrant, MinIO, RabbitMQ, Uptime Kuma, n8n, Gitea, Metabase and more, plus stacks: Elasticsearch + Kibana, WordPress + MySQL, Ghost, Nextcloud, Gitea + PostgreSQL, n8n + PostgreSQL, Umami, Metabase + PostgreSQL, MongoDB + Mongo Express, Redis + RedisInsight. Passwords are generated in the browser, ports avoid ones already taken and listen on `127.0.0.1` |
| **Images** | Pull (or pick a popular image), run a container from an image, pull again for a newer build, remove (forced when a container still uses it), remove untagged layers or every unused image |
| **Networks** | Create a network (optionally internal-only or with a subnet), add and remove containers with extra names, remove unused networks |
| **Volumes** | Create, see size and which containers use each, delete one (type its name to confirm), remove unused anonymous volumes, or every unused volume (type `DELETE`) |

Keyboard shortcuts work on every Docker page: `?` lists them, `r` refreshes,
`/` searches, `n` creates (container, stack, network or volume), and `g` then
`o c s t i n v` jumps between sections.

### Containers that talk to each other

Containers on the same network you created reach each other by container
name. Create a network (for example `search`), put Elasticsearch and Kibana on
it, and Kibana uses `http://elasticsearch:9200`. The default `bridge` network
does not resolve names. Compose stacks get their own network automatically;
their services reach each other by service name.

### Put an app on a domain

- **One image**: create a website, open **Runtime settings → Docker → Run an
  image**, enter the image and the port it listens on. The panel runs the
  container and sends the domain to it.
- **A stack (or any container port)**: deploy the stack with its web service
  published on `127.0.0.1`, e.g. `"127.0.0.1:8085:80"`. Then on the website
  choose **Runtime settings → Docker → Use a running stack or port**, pick the
  stack and the port. Only a port a container actually publishes is accepted,
  so a domain can never be pointed at drust, MySQL or another server service.
  The site's Start, Stop, Restart, Redeploy and Logs act on the stack.

Stack files live in `/opt/dpanel/docker/stacks/<name>/` (`compose.yaml` and
`.env`, readable by root only). Stacks started from the shell are listed too;
start, stop, restart and take down work on them.

## How it works

```text
Browser ─▶ dPanel (Laravel, admin-only routes)
             │  bearer token
             ▼
           drust.service (root, 127.0.0.1:9500)  /api/v1/docker
             │  docker CLI, argument list, no shell
             ▼
           dockerd ─▶ containers
```

- The panel never talks to the Docker socket. Only drust does, as root.
- drust runs fixed `docker` commands with an argument list, never through a
  shell. It checks every name, image, port, variable name and mount first, and
  rejects any value that starts with `-`, so a value can never become a flag.
- The panel learns whether Docker is installed by checking for
  `/usr/bin/docker` or `/usr/local/bin/docker`.

The API is documented in [drust API → Docker](drust-api.md#docker).

## Security

- **Docker access is root access.** Anyone who can start a container with a
  host mount can read any file on the server. That is why every Docker route is
  admin-only. Resellers and users never see the menu.
- **Ports are private by default.** A published port binds to `127.0.0.1`.
  Tick **Public** only when the port must be reachable from the internet.
  Docker writes its own iptables rules, so **ufw does not block a public
  container port**.
- **Every action is logged** in the activity log as `docker.<action>`.
  Environment variable values are never logged, only their names, because they
  often hold passwords. Stack files and `.env` are never logged either. Console
  commands are logged (first 500 characters), since they are the audit trail.
- **Clean-up never removes volumes.** Volumes hold data and are only removed
  from the Volumes page, on purpose.
- **Recreating is safe.** Edit & recreate and Update image set the old
  container aside first; if the new one fails to start, the old one is put back.

## Log limits

Docker keeps container logs forever by default, so one chatty container can
fill the disk. A fresh install by `dpanel docker` writes
`/etc/docker/daemon.json`:

```json
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "live-restore": true
}
```

`live-restore` keeps containers running while the Docker daemon restarts.

If Docker was installed some other way, or `daemon.json` already existed, the
installer leaves the file alone and `status` warns that logs are not capped.
To add the limits yourself, write the file above and restart Docker. Running
containers restart, and the limits apply to containers created after that:

```bash
sudo systemctl restart docker
```

## Remove

```bash
sudo dpanel docker remove            # keeps images, containers and volumes
sudo dpanel docker remove --purge    # also deletes /var/lib/docker and /var/lib/containerd
```

Running containers stop first. The menu disappears from the panel again.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Menu does not show Docker after installing | Open `/docker` and press **Check again**, or reload the page. |
| "Docker is installed but not running" | `sudo systemctl start docker`, then `sudo dpanel docker status`. |
| "N check(s) failed" during install | Free RAM or disk, or re-run with `--force` if you accept the risk. |
| Pull fails or times out | The server cannot reach the registry. Check DNS and outbound HTTPS. Very large images can outlast the panel's wait, so pull them from the shell instead: `sudo docker pull <image>`. |
| Container port is reachable from outside although ufw denies it | The port was published as **Public**. Recreate the container without **Public**. |
| Disk is filling up | `sudo dpanel docker status`, then **Images → Remove unused layers**, and remove containers you no longer need. |

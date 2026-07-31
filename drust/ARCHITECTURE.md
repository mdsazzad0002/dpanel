# drust Edge Gateway Plan

This document defines the implementation path for evolving `drust` into a full
Rust-based edge gateway for HTTP serving, reverse proxying, TLS, caching, and reloadable config.

The target is not just a web server. The target is a host service that can:

- terminate TLS
- serve static assets
- reverse proxy to app backends
- route by vhost and path
- compress responses
- cache safe content
- reload configuration without downtime
- store and validate config in a database

## Principles

1. Keep the public request path fast and allocation-light.
2. Compile config into an immutable runtime snapshot.
3. Separate control-plane writes from data-plane reads.
4. Make reloads atomic instead of restarting the process.
5. Prefer explicit policy objects over hidden behavior.

## Phase 1: Runtime Skeleton

Goal: create the runtime boundaries before adding production logic.

Tasks:

- add a dedicated runtime module
- define config snapshot types
- define vhost, route, upstream, and TLS metadata models
- define runtime service handles for reload and health
- keep the request path isolated from database reads

## Phase 2: Static Serving and Routing

Goal: serve direct files and basic virtual hosts.

Tasks:

- document root support
- index file resolution
- safe path normalization
- directory index blocking
- SPA fallback option
- host-based route matching

### First implemented pieces

- `RuntimeSnapshot::resolve_site(host)` chooses the active site by hostname.
- `SiteConfig::resolve_route(path)` picks the most specific prefix match.
- `normalize_request_path(path)` collapses dot segments before file lookup.

## Phase 3: Reverse Proxy

Goal: proxy requests to application backends.

Tasks:

- HTTP upstream support
- unix socket upstream support
- header forwarding
- timeout controls
- retry policy
- upstream health checks

## Phase 4: TLS

Goal: terminate TLS inside `drust`.

Tasks:

- SNI-based certificate selection
- certificate hot reload
- certificate metadata in config storage
- integration with ACME later if needed

## Phase 5: Cache and Compression

Goal: reduce repeated work and bandwidth.

Tasks:

- gzip and brotli compression
- cacheable response support
- file metadata cache
- ETag and Last-Modified generation
- cache invalidation on config version swap

## Phase 6: Zero-Downtime Reload

Goal: change config without dropping active traffic.

Tasks:

- load new config in the background
- validate it before activation
- swap an `Arc<ConfigSnapshot>` atomically
- keep the previous snapshot alive until requests finish

## Phase 7: Database-Backed Control Plane

Goal: make config durable and panel-managed.

Tasks:

- persist vhosts, routes, TLS certs, and cache policy
- write migration schema
- build config compiler from DB records
- add admin APIs for reload and inspection

## Initial Data Model

- `vhosts`
- `routes`
- `upstreams`
- `tls_certs`
- `tls_cert_names`
- `static_roots`
- `cache_policies`
- `rewrite_rules`
- `server_snapshots`
- `config_events`

## Early Milestones

1. Add runtime/config module scaffolding.
2. Add route model and snapshot compiler.
3. Add static file serving.
4. Add proxy forwarding.
5. Add TLS listener.
6. Add reload control path.

<!--
  SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# admin_audit_http_client

[![REUSE status](https://api.reuse.software/badge/github.com/ernolf/admin_audit_http_client)](https://api.reuse.software/info/github.com/ernolf/admin_audit_http_client)
[![Latest release](https://img.shields.io/github/v/release/ernolf/admin_audit_http_client?sort=semver&color=0082c9)](https://github.com/ernolf/admin_audit_http_client/releases/latest)
[![Built with ncmake](https://img.shields.io/badge/built%20with-ncmake-0082c9)](https://github.com/ernolf/ncmake)

Nextcloud app that logs all outgoing HTTP requests made by Nextcloud's built-in HTTP client (`OC\Http\Client\Client`, Guzzle-based).

> [!NOTE]
> The app has no built-in log rotation. Monitor log file sizes and rotate them with the system's logrotate daemon.

## Configuration (config/config.php)

All settings are optional.

### `audit_http_client_logdir`

Explicit override for the log directory. Takes precedence over everything else.

```php
'audit_http_client_logdir' => '/var/log/nextcloud/http_client',
```

If this key is absent, the log directory is derived automatically:

- If `logfile_audit` is set and points outside `datadirectory`: logs go into a `client/`
  subdirectory next to the audit log.
- Else if `logfile` is set and points outside `datadirectory`: logs go into a `client/`
  subdirectory next to the main log.
- Otherwise: `<datadirectory>/admin_audit_http_client_logs/`

The directory is created automatically on first use.

### `audit_http_client_loglevel`

Controls which responses are logged. Default: `0`.

| Value | Logs |
|---|---|
| `0` | all requests |
| `1` | HTTP 400+ only |
| `2` | HTTP 500+ only |

Network errors (connection failures, DNS failures, etc.) are always logged regardless of this setting.

```php
'audit_http_client_loglevel' => 1,
```

### `audit_http_client_format`

Selects the log output format. Default: `'both'`.

| Value | Output |
|---|---|
| `'json'` | `<host>.json` — one JSON object per line |
| `'plain'` | `<host>.log` — one plain-text line per request |
| `'both'` | both files |

Every entry starts with the request ID: the ID of the server request that triggered the outgoing call (the same ID as in `nextcloud.log`), followed by a random per-request suffix. The ID is also sent to the remote server as `X-Nextcloud-ReqId` header; a caller-supplied header is used unchanged.

Plain-text example:

```
67527c3ff4b8-a3f9bc12 2026-05-05T14:23:01+00:00 GET https://example.com/feed HTTP/2 200 compressed=4821 decompressed=18944 ratio=0.25 encoding=br Hdrs=Host,Accept-Encoding,User-Agent "Nextcloud/32 ..."
```

```php
'audit_http_client_format' => 'json',
```

### `audit_http_client_exclude_domains`

List of hostnames to exclude from logging. Requests to matching hosts are passed through without any log entry. Matching is case-insensitive. Wildcard prefix (`*.example.com`) is supported and also matches the bare domain (`example.com`). Default: `[]`.

```php
'audit_http_client_exclude_domains' => [
    'apps.nextcloud.com',
    'updates.nextcloud.com',
    '*.googleapis.com',
],
```

### `audit_http_client_redact_headers`

Additional header names whose values are logged as `[redacted]`. Matching is case-insensitive. Default: `[]`.

The following headers are always redacted and cannot be un-redacted: `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Token`.

```php
'audit_http_client_redact_headers' => [
    'X-Vendor-Secret',
],
```

## Installation

### From a release tarball

Download the latest release tarball from the [releases page](https://github.com/ernolf/admin_audit_http_client/releases) and extract it into your Nextcloud `apps/` directory, so the app lives at `apps/admin_audit_http_client/`. Then set ownership to your web server user and enable it:

```sh
tar -xzf admin_audit_http_client-x.y.z.tar.gz -C /path/to/nextcloud/apps/
chown -R www-data:www-data /path/to/nextcloud/apps/admin_audit_http_client
occ app:enable admin_audit_http_client
```

### From source

The build is driven by [ncmake](https://github.com/ernolf/ncmake) and runs entirely in throwaway containers, so the only requirement is **[podman](https://podman.io/)** (or Docker) — no PHP toolchain on the host. The first `make` fetches the shared ncmake Makefile once into `~/.cache/ncmake/`. Clone and build the tarball:

```sh
git clone https://github.com/ernolf/admin_audit_http_client.git
cd admin_audit_http_client
make build && make dist
```

This writes `build/artifacts/dist/admin_audit_http_client-x.y.z.tar.gz`. Install it exactly like a release tarball above.

If your Nextcloud is on the same machine (or reachable over SSH), you can skip the tarball and deploy straight into its `apps/` directory — `OCC=1` runs the full refresh cycle (`app:disable`, sync, `chown`, `app:enable`) in one go:

```sh
make build && make rsync TARGET=/path/to/nextcloud/apps/ OCC=1
```

`TARGET` is the `apps/` parent directory and may be a local path or a remote `user@host:` path.

For **Nextcloud All-in-One** (or any dockerized instance whose filesystem is not reachable from outside), `make cp` deploys into the running container instead:

```sh
make build && make cp TARGET=nextcloud-aio-nextcloud:/var/www/html/custom_apps/ OCC=1
```

### Update

- Tarball installations: run `occ app:remove admin_audit_http_client`, then install the new tarball as above — this avoids leftover files from previous versions.
- `make rsync`/`make cp` deployments: simply run the same command again after a `git pull`; the sync replaces the app directory as a whole.

## Roadmap

- **Event-based injection:** the middleware is injected via reflection into the private Guzzle client of `OC\Http\Client\Client`, because core dispatches no event that exposes the handler stack. Nextcloud 35 added an optional `$handler` parameter to `IClientService::newClient()`; a core-side `HttpClientHandlerStackReadyEvent` (or a comparable extension point) would allow dropping the reflection entirely. Until then the server-matrix CI job also runs against `master` and fails the moment core changes the private structure.
- **Built-in log rotation** (size/age based) instead of relying on the system's logrotate.

Background and data-flow notes: [Architecture & Roadmap](https://gist.github.com/ernolf/ecd9a66610be46f2afff840b1c70d513).

## Credits

* **Author & Maintainer:** [[ernolf] Raphael Gradenwitz](https://github.com/ernolf)

## License

AGPL-3.0-or-later

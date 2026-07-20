<!--
  SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# admin_audit_http_client

[![REUSE status](https://api.reuse.software/badge/github.com/ernolf/admin_audit_http_client)](https://api.reuse.software/info/github.com/ernolf/admin_audit_http_client)
[![Nextcloud App Store](https://img.shields.io/badge/Nextcloud-App_Store-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/admin_audit_http_client)
[![Latest release](https://img.shields.io/github/v/release/ernolf/admin_audit_http_client?sort=semver&color=0082c9)](https://github.com/ernolf/admin_audit_http_client/releases/latest)
[![built with ncmake](https://cdn.jsdelivr.net/gh/ernolf/ncmake@main/img/ncmake-badge.svg)](https://github.com/ernolf/ncmake)

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

Network errors (connection failures, DNS failures, etc.) are always logged regardless of this setting. Values outside the 0–2 range are clamped to the nearest valid level.

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
gkAokgFCiaAKp30GwoTn-a3f9bc12 2026-05-05T14:23:01+00:00 GET https://example.com/feed HTTP/2 200 compressed=4821 decompressed=18944 ratio=0.25 encoding=br Hdrs=Host,Accept-Encoding,User-Agent "Nextcloud/32 ..."
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

Additional header names whose values are logged as `***REMOVED SENSITIVE VALUE***`. Matching is case-insensitive. Default: `[]`.

The following headers are always redacted and cannot be un-redacted: `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Token`.

```php
'audit_http_client_redact_headers' => [
    'X-Vendor-Secret',
],
```

### `audit_http_client_redact_params`

Additional query parameter names whose values are logged as `***REMOVED SENSITIVE VALUE***` in the `uri` field. Matching is case-insensitive; the parameter name and the rest of the URI stay unchanged. Default: `[]`.

The following parameters are always redacted and cannot be un-redacted: `access_token`, `api_key`, `apikey`, `auth`, `client_secret`, `key`, `password`, `secret`, `signature`, `token`, `X-Amz-Credential`, `X-Amz-Security-Token`, `X-Amz-Signature`.

```php
'audit_http_client_redact_params' => [
    'session_key',
],
```

### `audit_http_client_redact_path_patterns`

Additional PCRE patterns whose matches in the URI **path** are logged as `***REMOVED SENSITIVE VALUE***` — for secrets that live in the path instead of the query string. Default: `[]`.

The following pattern is always applied and cannot be removed: `#(?<=/private-)[^/]+#` — it covers Google's secret iCal addresses (`…/ical/<user>/private-<hash>/basic.ics`), where the hash is the only protection of the calendar. Only the hash is replaced; the `private-` marker stays readable.

```php
'audit_http_client_redact_path_patterns' => [
    '#(?<=/)sess-[0-9a-f]+#',
],
```

Invalid patterns are skipped. To skip logging for a host entirely, use [`audit_http_client_exclude_domains`](#audit_http_client_exclude_domains) instead.

## Installation

The app is published in the [App Store](https://apps.nextcloud.com/apps/admin_audit_http_client). Install it through [Nextcloud's app management UI](https://docs.nextcloud.com/server/latest/admin_manual/apps_management.html#managing-apps) (**Apps** → search for **Auditing / HTTP Client Logging** → Install) or with `occ app:enable admin_audit_http_client`.

It is built with [ncmake](https://github.com/ernolf/ncmake). To build and install it from source — release tarball, `make rsync` or `make cp` — see the [installation guide](https://github.com/ernolf/ncmake/blob/main/doc/INSTALL.md).

## Roadmap

- **Event-based injection:** the middleware is injected via reflection into the private Guzzle client of `OC\Http\Client\Client`, because core dispatches no event that exposes the handler stack. Nextcloud 35 added an optional `$handler` parameter to `IClientService::newClient()`; a core-side `HttpClientHandlerStackReadyEvent` (or a comparable extension point) would allow dropping the reflection entirely. Until then the server-matrix CI job also runs against `master` and fails the moment core changes the private structure.
- **Built-in log rotation** (size/age based) instead of relying on the system's logrotate.

Background and data-flow notes: [Architecture & Roadmap](https://gist.github.com/ernolf/ecd9a66610be46f2afff840b1c70d513).

## Credits

* **Author & Maintainer:** [[ernolf] Raphael Gradenwitz](https://github.com/ernolf)

## License

AGPL-3.0-or-later

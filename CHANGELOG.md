<!--
  SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-19

### Breaking

- All config keys were renamed to the `audit_http_client_*` scheme (`audit_http_client_logdir`, `…_loglevel`, `…_format`, `…_exclude_domains`); the old 0.2.0 key names are no longer read, there is no fallback — rename them in config.php when upgrading.

### Added

- Redact sensitive request and response headers by default
- Redact credentials from logged URIs (query parameters and path secrets)

### Fixed

- Declare supported PHP versions
- Adopt the optional handler parameter added to IClientService in NC 35
- Replace the memcache stats store with a process-local array
- Report immediate-path compression stats honestly and injection failures louder
- Make middleware injection idempotent and header lookups case-insensitive
- Clamp the log level and count rewound stream reads once
- Substitute broken UTF-8 in log entries instead of dropping them; rethrow non-throwable rejection reasons cleanly
- Derive stream consumption from the read position and report detached bodies as unknown

[1.0.0]: https://github.com/ernolf/admin_audit_http_client/releases/tag/v1.0.0

## [0.2.0] - 2026-05-12

### Added
- `loglevel_audit_http_client` config key (int, default `0`): controls which responses are logged — `0` = all, `1` = HTTP 400+ only, `2` = HTTP 500+ only
- `audit_http_client_logs` config key (string, default `'both'`): selects log output format — `'json'`, `'plain'`, or `'both'`
- `audit_http_client_logs_exclude_domain` config key (array, default `[]`): list of hostnames to exclude from logging; supports wildcard prefix (`*.example.com`)

[0.2.0]: https://github.com/ernolf/admin_audit_http_client/releases/tag/v0.2.0

## [0.1.0] - 2026-05-06

### Added
- `LoggingClientService`: reflection-based `IClientService` decorator that injects `HttpClientLoggerMiddleware` into the Guzzle `HandlerStack` via `HandlerStack::unshift()`
- `HttpClientLoggerMiddleware`: logs all outgoing HTTP requests including method, URI, HTTP version, status code, all request/response headers, cURL transfer statistics, and compression metrics
- `CountingStream`: wraps 2xx response body streams to count decompressed bytes; writes the log entry on stream close or garbage collection
- `TransferStatsStore`: static store to correlate asynchronous `on_stats` cURL callback data with the middleware response handler
- `LogPathHelper`: derives per-host log file paths; writes one `.json` and one `.log` file per remote host
- `HandlerStackReadyListener`: prepared for future event-based injection once a `HttpClientHandlerStackReadyEvent` is available in Nextcloud core
- `logdir_audit_http_client` config key with automatic fallback chain: explicit override → sibling of `logfile_audit` → sibling of `logfile` → `<datadirectory>/admin_audit_http_client_logs/`
- Log entries contain: request ID (`X-Nextcloud-ReqId`), timestamp, method, URI, HTTP version, status, request headers, response headers, cURL handler stats (`size_download`, `speed_download`, `total_time`, etc.), compression stats (`encoding`, `compressed_bytes`, `decompressed_bytes`, `ratio`)

[0.1.0]: https://github.com/ernolf/admin_audit_http_client/releases/tag/v0.1.0

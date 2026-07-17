<!--
  SPDX-FileCopyrightText: 2026 [ernolf] Raphael Gradenwitz <raphael.gradenwitz@googlemail.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# admin_audit_http_client

Nextcloud app that logs all outgoing HTTP requests made by Nextcloud's built-in HTTP client (`OC\Http\Client\Client`, Guzzle-based).

---
> [!IMPORTANT]
> **This app is in early development.**
> It is not available in the Nextcloud App Store — [installation is manual only](#installation).
> It has no built-in log rotation yet. Monitor log file sizes and rotate manually or via the system's logrotate daemon until log rotation is implemented.

---
## Configuration (config/config.php)

All settings are optional.

### `logdir_audit_http_client`

Explicit override for the log directory. Takes precedence over everything else.

```php
'logdir_audit_http_client' => '/var/log/nextcloud/http_client',
```

If this key is absent, the log directory is derived automatically:

- If `logfile_audit` is set and points outside `datadirectory`: logs go into a `client/`
  subdirectory next to the audit log.
- Else if `logfile` is set and points outside `datadirectory`: logs go into a `client/`
  subdirectory next to the main log.
- Otherwise: `<datadirectory>/admin_audit_http_client_logs/`

The directory is created automatically on first use.

### `loglevel_audit_http_client`

Controls which responses are logged. Default: `0`.

| Value | Logs |
|---|---|
| `0` | all requests |
| `1` | HTTP 400+ only |
| `2` | HTTP 500+ only |

Network errors (connection failures, DNS failures, etc.) are always logged regardless of this setting.

```php
'loglevel_audit_http_client' => 1,
```

### `audit_http_client_logs`

Selects the log output format. Default: `'both'`.

| Value | Output |
|---|---|
| `'json'` | `<host>.json` — one JSON object per line |
| `'plain'` | `<host>.log` — one plain-text line per request |
| `'both'` | both files |

Plain-text example:

```
a3f9bc 2026-05-05T14:23:01+00:00 GET https://example.com/feed HTTP/2 200 compressed=4821 decompressed=18944 ratio=0.25 encoding=br Hdrs=Host,Accept-Encoding,User-Agent "Nextcloud/32 ..."
```

```php
'audit_http_client_logs' => 'json',
```

### `audit_http_client_logs_exclude_domain`

List of hostnames to exclude from logging. Requests to matching hosts are passed through without any log entry. Matching is case-insensitive. Wildcard prefix (`*.example.com`) is supported and also matches the bare domain (`example.com`). Default: `[]`.

```php
'audit_http_client_logs_exclude_domain' => [
    'apps.nextcloud.com',
    'updates.nextcloud.com',
    '*.googleapis.com',
],
```

## Installation

No npm, no composer, no build step required.

> [!NOTE]
> Installation is typically done as root or a privileged user, not as the web server user itself. After installing, set ownership to match your web server user:
>
> | Distribution | Web server user |
> |---|---|
> | Debian / Ubuntu | `www-data` |
> | CentOS / RHEL / Fedora (Apache) | `apache` |
> | CentOS / RHEL / Fedora (nginx) | `nginx` |
> | Arch Linux | `http` |

### From a release tarball (recommended)

Download the latest release from the [Releases page](https://github.com/ernolf/admin_audit_http_client/releases):

* <details>
  <summary>Installation script</summary>

  ```bash
  TAG=v0.2.0
  NCDIR=/path/to/nextcloud
  HTUSER=www-data
  cd ${NCDIR}/apps
  sudo curl -L https://github.com/ernolf/admin_audit_http_client/releases/download/${TAG}/admin_audit_http_client-${TAG}.tar.gz | sudo tar -xz
  sudo chown -R ${HTUSER}: admin_audit_http_client
  sudo -u ${HTUSER} php ${NCDIR}/occ app:enable admin_audit_http_client
  ```
  </details>

### Nextcloud All-in-One (AIO)

* <details>
  <summary>Installation script</summary>

  ```bash
  TAG=v0.2.0
  curl -L https://github.com/ernolf/admin_audit_http_client/releases/download/${TAG}/admin_audit_http_client-${TAG}.tar.gz \
    | sudo docker exec -i --user www-data nextcloud-aio-nextcloud tar -xz -C /var/www/html/custom_apps/
  sudo docker exec --user www-data -it nextcloud-aio-nextcloud php occ app:enable admin_audit_http_client
  ```
  </details>

### Via git clone (development / always latest)

* <details>
  <summary>Installation script</summary>

  ```bash
  NCDIR=/path/to/nextcloud
  HTUSER=www-data
  cd ${NCDIR}/apps
  git clone https://github.com/ernolf/admin_audit_http_client.git
  sudo chown -R ${HTUSER}: admin_audit_http_client
  sudo -u ${HTUSER} php ${NCDIR}/occ app:enable admin_audit_http_client
  ```
  </details>

### Update

Remove the old directory and reinstall — this avoids leftover files from previous versions.

**From a release tarball:**

* <details>
  <summary>Update script</summary>

  ```bash
  TAG=v0.2.0
  NCDIR=/path/to/nextcloud
  HTUSER=www-data
  cd ${NCDIR}/apps
  sudo -u ${HTUSER} php ${NCDIR}/occ app:remove admin_audit_http_client
  sudo curl -L https://github.com/ernolf/admin_audit_http_client/releases/download/${TAG}/admin_audit_http_client-${TAG}.tar.gz | sudo tar -xz
  sudo chown -R ${HTUSER}: admin_audit_http_client
  sudo -u ${HTUSER} php ${NCDIR}/occ app:enable admin_audit_http_client
  ```
  </details>

### Nextcloud All-in-One (AIO)

* <details>
  <summary>Installation script</summary>

  ```bash
  TAG=v0.2.0
  sudo docker exec --user www-data -it nextcloud-aio-nextcloud php occ app:remove admin_audit_http_client
  curl -L https://github.com/ernolf/admin_audit_http_client/releases/download/${TAG}/admin_audit_http_client-${TAG}.tar.gz \
    | sudo docker exec -i --user www-data nextcloud-aio-nextcloud tar -xz -C /var/www/html/custom_apps/
  sudo docker exec --user www-data -it nextcloud-aio-nextcloud php occ app:enable admin_audit_http_client
  ```
  </details>

**Via git clone:**

* <details>
  <summary>Update script</summary>

  ```bash
  NCDIR=/path/to/nextcloud
  HTUSER=www-data
  cd ${NCDIR}/apps
  sudo -u ${HTUSER} php ${NCDIR}/occ app:remove admin_audit_http_client
  git clone https://github.com/ernolf/admin_audit_http_client.git
  sudo chown -R ${HTUSER}: admin_audit_http_client
  sudo -u ${HTUSER} php ${NCDIR}/occ app:enable admin_audit_http_client
  ```
  </details>

## Roadmap

Architecture, data flow, planned features, and configuration options are documented in the
[Architecture & Roadmap](https://gist.github.com/ernolf/ecd9a66610be46f2afff840b1c70d513).

## Credits

* **Author & Maintainer:** [[ernolf] Raphael Gradenwitz](https://github.com/ernolf)

## License

AGPL-3.0-or-later

# admin_audit_http_client

Nextcloud app that logs all outgoing HTTP requests made by Nextcloud's built-in HTTP client (`OC\Http\Client\Client`, Guzzle-based).

> [!IMPORTANT]
> **This app is in early development.** 
> It has no settings UI, no log rotation, and no filtering. Activate it only on development or staging instances, or temporarily on production for debugging purposes. 
> Log files can grow large quickly — leaving the app permanently enabled is not recommended until log level control and exclusion rules are implemented.

Each request is written as one JSON record and one plain-text line. The log captures request and response headers, cURL transfer stats, and compression metrics (compressed/decompressed byte counts, encoding, ratio).

## Installation

Clone the repository into your Nextcloud `apps` directory and enable the app:

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/ernolf/admin_audit_http_client.git
```

Then enable it in the Nextcloud admin panel or via occ:

```bash
php occ app:enable admin_audit_http_client
```

No npm, no composer, no build step required.

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

## Log format

Two files per target host, written to the configured log directory:

- `<host>.json` — one JSON object per line
- `<host>.log` — one plain-text line per request

Plain-text example:

```
a3f9bc 2026-05-05T14:23:01+00:00 GET https://example.com/feed HTTP/2 200 compressed=4821 decompressed=18944 ratio=0.25 encoding=br Hdrs=Host,Accept-Encoding,User-Agent "Nextcloud/32 ..."
```

## Roadmap

Architecture, data flow, planned features, and configuration options are documented in the
[Architecture & Roadmap](https://gist.github.com/ernolf/ecd9a66610be46f2afff840b1c70d513).

## Credits

* **Author & Maintainer:** [[ernolf] Raphael Gradenwitz](https://github.com/ernolf)

## License

AGPL-3.0-or-later

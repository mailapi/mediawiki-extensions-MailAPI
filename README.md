# MailAPI MediaWiki Extension

MediaWiki extension that sends emails through an external service conforming to the [Mail API Specification](https://github.com/mailapi/mailapi) (`openapi.yaml`).

## Features

- **Mail API Compliant**: Targets v0.4.4 of the vendor-neutral [Mail API OpenAPI Specification](https://github.com/mailapi/mailapi/blob/v0.4.4/openapi.yaml).
- **Simple Configuration**: Uses `$wgMailAPIEndpoint` and `$wgMailAPIToken` for bearer-authenticated providers.
- **Hook Integration**: Uses MediaWiki's `UserMailerTransformMessage` and `AlternateUserMailer` hooks to intercept and route all outgoing emails.
- **Problem Details Handling**: Parses RFC 9457 Problem Details responses from the Mail API server for clear error reporting.

## Installation

Note: Extension:MailAPI targets MediaWiki `1.43+`.

1. Clone or download the extension to your MediaWiki extensions directory:

```bash
cd /path/to/mediawiki/extensions
git clone --depth 1 https://github.com/mailapi/mediawiki-extensions-MailAPI.git
```

2. (Optional) If using Composer merge loading, update `composer.local.json`:

```json
{
    "extra": {
        "merge-plugin": {
            "include": [
                "extensions/*/composer.json",
                "skins/*/composer.json"
            ]
        }
    }
}
```

```bash
cd /path/to/mediawiki
composer update --no-dev
```

## Configuration in LocalSettings.php

Add the following lines to your `LocalSettings.php`:

```php
wfLoadExtension( 'MailAPI' );
$wgMailAPIEndpoint = 'http://localhost:8080'; // or 'http://localhost:8080/v1/messages'
$wgMailAPIToken = getenv( 'MAILAPI_TOKEN' ) ?: ''; // provider-issued secret token
```

### Configuration Options

| Variable | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `$wgMailAPIEndpoint` | `string` | `""` | The base URL or full endpoint URL (`/v1/messages`) of the Mail API service. |
| `$wgMailAPIToken` | `string` | `""` | Provider-issued bearer token. Required by resend-mailer v0.3.0. Empty omits Authorization for providers using a deployment-specific equivalent scheme. |
| `$wgMailAPIWaitTimeout` | `int` | `10` | Prefer waiting for completion for 0–20 seconds; 0 disables waiting. |

## How It Works

1. **Email Interception**: Submits from `UserMailerTransformMessage`, then uses `AlternateUserMailer` to skip the default transport called by `UserMailer::send()`.
2. **Payload Construction**: Formats the sender, recipient(s), subject, text/html content, and supplemental headers into the Mail API `OutboundMessageRequest` schema:
   - `from`: `{ "email": "...", "name": "..." }`
   - `to`: `[ { "email": "...", "name": "..." } ]`
   - `subject`: `"..."`
   - `text`: `"..."` / `html`: `"..."`
   - `replyTo` / `cc` / `bcc`: `[ { "email": "...", ... } ]` (extracted from headers if present)
   - `headers`: `[ { "name": "...", "value": "..." } ]` (supplemental headers)
3. **HTTP Dispatch**: Sends an HTTP `POST` request with `Content-Type: application/json` to `/v1/messages`, with a fresh `Idempotency-Key` for each message and `Authorization: Bearer <token>` when configured.
4. **Result Handling**: On acceptance (HTTP 200 or 202), skips MediaWiki's default mail transport and logs the Mail API message ID; this does not confirm delivery. On failure, logs RFC 9457 problem details and returns an error to MediaWiki without falling back to SMTP, avoiding duplicate submissions after an ambiguous failure. An unconfigured endpoint still uses the default mailer.

## Upgrading from v0.1.x

Configure `$wgMailAPIToken` before connecting to resend-mailer v0.3.0. Both HTTP `200` and `202` are successful submission responses. A configured provider failure now stops mail submission instead of silently trying the default transport; inspect the `mailapi` log when MediaWiki reports a mail error. The client retries transport failures, HTTP 429/503, and in-progress idempotency conflicts up to twice with the same key and body, within a 30-second budget. Terminal failures are reported through the transform hook error parameter with a boolean return, as required by MediaWiki. Retry-After values above two seconds are reported rather than retried early. A 202 response still means acceptance only. Direct Client callers may pass a stable key to `send($payload, $key)` for retries across calls; MediaWiki job-level retries do not automatically share that key.

## Testing

Run PHPUnit tests:

```bash
composer install
./vendor/bin/phpunit
```

For a disposable installed MediaWiki instance with this extension loaded, start the local-only mock router and run the real hook smoke test:

```bash
php -S 127.0.0.1:8099 extensions/MailAPI/tests/maintenance/smokeRouter.php
# In another shell, from the MediaWiki installation directory:
php extensions/MailAPI/tests/maintenance/mailapiSmoke.php --endpoint http://127.0.0.1:8099
```

This checks success, terminal failure, in-progress exhaustion, and transport failure through `UserMailer::send()` and the real HookContainer without sending email.

## License

[Apache License 2.0](LICENSE)

## Hook ordering and uncertain outcomes

MailAPI submits during `UserMailerTransformMessage`. Register other message-transforming or aborting handlers before MailAPI; handlers that run afterwards cannot change the submitted message or undo it. This must be checked when installing other mail-related extensions.

In-progress conflicts wait at least one second between attempts. If ambiguous transport failures (such as timeouts) or in-progress responses exhaust retries, MediaWiki reports an unknown outcome: the message may still be sent, so do not immediately submit it again with a new key. A failure is reported as known instead when the last response settles it (a Problem Details `500` or a deterministic `4xx` such as `401` or `422`), or when no attempt could have reached the provider (DNS failure or refused connection). Detailed errors and exception traces are logged rather than shown to users. Out-of-range `MailAPIWaitTimeout` settings log a warning and use the default of 10 seconds.

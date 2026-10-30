# cPanel deployment

## Hosting requirements

PHP 8.2+ CLI and web runtime, Composer/SSH or a prebuilt vendor directory, MySQL, sufficient disk quota, outbound HTTPS to Hugging Face and its model CDNs, cron and background-worker support are needed. A small PHP hosting plan can manage accounts and requests; serving large model inference requires suitable RAM/VRAM, CPU/GPU and permission to run an inference process. Confirm those hosting capabilities before expecting chat to run on that same account.

## Application location

Keep the application outside `public_html`, for example `/home/USER/online-model`. Point the subdomain's document root to `/home/USER/online-model/public`. Never expose the project root, `.env`, database files or `storage/app/models` through the web server. No public storage symlink is needed.

Set the domain and CLI to PHP 8.2, then follow README installation commands with the correct cPanel PHP binary, often `/opt/cpanel/ea-php82/root/usr/bin/php`. Give the application owner write access to `storage` and `bootstrap/cache`; do not use world-writable permissions. Keep `APP_DEBUG=false`, HTTPS and secure cookies enabled.

Configure real SMTP so registration verification and password-reset emails arrive. Keep `APP_KEY` backed up securely; it encrypts the TOTP secrets and sessions. Configure your own inference endpoint. `HF_TOKEN` is optional for public repositories and may be required for gated files after accepting the repository license. It is used only for Hugging Face access, never for commercial inference.


## Mobile API and Imunify360 bot protection

**Known symptom:** the Flutter mobile app receives HTTP 403 from
`https://cloud.openmindai.org/api/v1/public-config` with the response
`Access denied by Imunify360 bot-protection. IPs used for automation should be whitelisted`.
The hostname and Laravel route are correct, but this response is emitted by the
hosting security layer **before Laravel can process the request**. Changing
`.htaccess`, Laravel middleware, login credentials, OTP handling or mobile API
keys will not reliably remove this WebShield challenge.

**Required hosting-administrator action:**

1. In the host's Imunify360 **Events/Incidents** and WebShield logs, inspect
   the denied GET `/api/v1/public-config` event (time, client IP and rule).
   Confirm whether the response comes from per-IP greylisting, the bot challenge,
   an RBL rule, or an Under Attack Mode rule. These mechanisms require different
   corrections.
2. Ask the host to make first-party API requests from **real mobile apps**
   accessible without a JavaScript/browser-cookie challenge, preferably by
   scoping any exception to the Laravel API routes `/api/v1/*` and retaining
   Laravel Sanctum, 2FA, OTP, user activation, server-side throttles, application
   WAF rules and request limits. Whether path-level exceptions are available
   depends on the particular Imunify360 module, version and hosting provider.
3. If path-scoped WebShield exclusion is unavailable, consider **a dedicated
   API-only hostname/virtual host** with a separately approved security policy
   and no administration panel. Update both the web server and the mobile
   `CloudAccount.apiBase` together. Do not repoint the app to a random
   workaround or disable protection across the whole server.
4. For a **short-lived, known test client**, the server administrator can
   temporarily whitelist that client's IP under Imunify360's White List
   and then retest. That is **not** a production solution: real customers'
   mobile IP addresses change. A domain-wide WebShield whitelist affects
   all paths on that hostname, potentially including the admin panel.
5. Recheck from a real Android mobile network and a different network:
   `GET /api/v1/public-config` must return **200 JSON** with
   `registration_enabled`; then test `POST /api/v1/login` with a legitimate
   test account and separately verify any unfamiliar-network OTP. Do not
   include passwords, OTPs, bearer tokens or full IPs in public CI logs.

The public-config endpoint is only a **read-only health probe**. It does not
establish a user session. A working public-config request does not guarantee
that POST login, OTP email delivery or the Cloud chat gateway also work.

Do **not** use a fake browser user agent, solve the web challenge inside the
mobile application, turn off SSL verification, put a provider/developer key
in the app, or add public unauthenticated login proxy endpoints. Such
workarounds are not safe substitutes for properly configuring the host.

## Queue and scheduler

Preferred: run one persistent worker under the hosting process manager:

```bash
php artisan queue:work database --queue=default --sleep=3 --timeout=7200 --tries=0 --max-time=86400
```

`retry_after=7500` exceeds the job timeout. Download attempts have a 24-hour deadline. Lock contention releases a queued job; transfer/integrity failures are marked failed for an explicit admin retry. Restart persistent workers after deployment with `php artisan queue:restart`.

Cron entry for scheduled expired-token cleanup:

```cron
* * * * * cd /home/USER/online-model && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

If your provider permits long cron processes but not persistent workers, a separate guarded cron may process the queue:

```cron
* * * * * flock -n /home/USER/online-model-worker.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/USER/online-model/artisan queue:work database --stop-when-empty --timeout=7200 --tries=0 >> /home/USER/online-model/storage/logs/worker.log 2>&1
```

A provider may terminate cron jobs or cap process memory/duration. In that case use a permitted worker host sharing the application's database and model filesystem; the download button cannot bypass hosting limits. Do not use the `sync` queue for production multi-GB downloads.

## Storage and recovery

`MODEL_MAX_BYTES` caps each file; `MODEL_STORAGE_BUDGET_BYTES` caps completed files plus the next download. Actual cPanel account quotas may be lower than filesystem free space. Reserve space for logs, database, backups and temporary files. Downloads are serialized with a filesystem lock on a single/shared filesystem.

If the host kills a worker without running Laravel's failure handler, confirm no download worker is running before marking its stale `downloading` entry failed in the database and removing its `{id}.part` file. Never modify status while a worker is active. Admin may then retry. The initial implementation does not support resumable range downloads or model deletion from the panel.

Back up database and `APP_KEY`; model weights can be downloaded again from pinned revisions while they remain available. Chat content is stored in the database; restrict backup access and define your retention policy. Keep the runtime private/authenticated, and test a real model request after connecting it.

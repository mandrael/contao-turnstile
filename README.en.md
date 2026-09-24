# Contao Cloudflare Turnstile

<img src="logo.svg" alt="Contao Turnstile" width="88" align="right">

[Deutsch](README.md) | **English**

Globally replaces Contao's default CAPTCHA (the security question) with
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). Keys are entered in the
Contao back end under **Settings** – no YAML or `.env` editing required.

A single code base for the three Contao LTS versions **4.13, 5.3 and 5.7** (incl. 5.4–5.6).

---

## Screenshots

Keys, theme, size and appearance are entered in the **Contao back end** under *system settings*:

![Cloudflare Turnstile settings in the Contao back end](.github/screenshots/backend-settings.png)

Each form element can override the **captcha protection** (use global setting / Turnstile / Contao security question):

![Captcha protection per form element](.github/screenshots/per-field.png)

The **Turnstile widget** replaces the default security question in the front-end form (shown here with Cloudflare test keys):

![Turnstile widget in a front-end form](.github/screenshots/frontend-widget.png)

---

## How it works

The bundle overrides the captcha field type (`$GLOBALS['TL_FFL']['captcha']`), so Turnstile
replaces the security question everywhere Contao resolves a captcha through the field-type
registry:

| Surface | Turnstile active? |
|---|---|
| Form generator (form **with** a captcha field) | ✅ yes |
| Member registration | ✅ yes |
| Comments | ✅ yes |
| Native newsletter subscription | ⚠️ version-dependent (see „Known limitations") |

**Important:** the bundle replaces the captcha **where a captcha field already exists**. It does
**not** add a captcha to forms that don't have one – add a captcha/security-question field as usual
and it becomes Turnstile automatically.

With **no keys** configured, Contao falls back automatically and losslessly to the default
security question.

**Turnstile activation (global) + per-field control:** under *Settings → Cloudflare Turnstile* the
**Turnstile activation** decides where Turnstile applies:

- **Enable for all forms by default** – default: active everywhere, can be turned off per field.
- **Enable only for selected forms** – only where chosen per field.
- **Disable everywhere** – the Contao security question everywhere (keys stay stored).

Each captcha field in the form generator additionally offers **Captcha protection** with
**Use global setting / Turnstile / Contao security question** to override the global default for that
single field – handy e.g. for forms in the **footer / on every page**.

## Installation

### A) Contao Manager (recommended)

1. In the Contao Manager under **Packages**, click **Add package** and search for `turnstile`
   (or `mandrael/contao-turnstile`).
2. **Add** it, then **Apply changes** – the Manager installs the extension via Composer.
3. Afterwards **update the database** (confirm the Manager's migration step) – this creates the new field.

### B) Terminal (without GUI)

```bash
composer require mandrael/contao-turnstile
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

## Setup

1. Create a Turnstile widget in the
   [Cloudflare dashboard](https://dash.cloudflare.com/?to=/:account/turnstile) and copy the
   **site key** and **secret key**.
2. Add **all domains/hostnames** of the Contao installation to the Turnstile widget
   (e.g. `example.com`, `www.example.com`, any subdomains). If a domain is missing, verification
   fails on that domain.
3. In Contao under **Settings → Cloudflare Turnstile** enter the site key and secret key,
   optionally choose theme/size/appearance. The **theme** defaults to **light** (white) – switch to
   **dark** only if desired, **auto** follows the system colour scheme (the device's light/dark mode,
   not the page).

### Content Security Policy (CSP)

If you run a Content Security Policy, allow the Cloudflare host:

```
script-src https://challenges.cloudflare.com;
frame-src  https://challenges.cloudflare.com;
```

The widget uses the official external `api.js` and **no** inline JavaScript – no
`nonce`/`unsafe-inline` is required.

The **ALTCHA fallback** (`turnstileFailureMode = altcha`, from 0.7.0) adds same-origin sources:

```
script-src  'self';
worker-src  'self';
connect-src 'self';
```

On Contao 5 the bundle adds these automatically in `altcha` mode; on Contao 4.13 (no CSP API) an
integrator with a strict CSP of their own adds them manually.

## Behaviour without a valid token

- **Network/timeout errors** (Cloudflare unreachable, 5 s timeout) → the check counts as failed
  (fail-closed) and an error is written to the Contao system log.
- **Invalid/forged token** (`success: false`) → also failed. This includes a wrong or expired
  site/secret key (a corresponding warning is written to the system log).
- **Mode `block`** (default) → every failed check rejects the form.
- **Mode `altcha`** (recommended for registration, booking and contact forms) → nobody is rejected
  merely because of a Turnstile false positive. First a mechanical check: hidden field, signed
  timestamp, minimum time of 3 seconds, a proof of work solved in the browser (ALTCHA). It can still
  reject, for example without JavaScript; the message then names a way out. Whoever passes is accepted
  and classified:
  - Content signals: gibberish in text fields (a field consisting only of one random word of 16 or more
    mixed upper and lower case letters like `KqWbTzeHuRNmoPLxa`, or syllable chains like "qexira vubot lomeza" without common function words),
    link or markup in the text, the same text from several networks within 24 hours.
  - Address signals: dot-stuffed e-mail address (Gmail from four dots and three single
    characters, e.g. `q.w.er.t.zu.7@gmail.com`; otherwise from six dots and four single characters), domain without an MX record.
  - Origin signal: the submission comes from a Tor exit node. The bundle fetches the list from
    `check.torproject.org` (no user data, cached for 6 hours; on error no match).
  - Extra points only, never sufficient on their own: more than five token-less submissions from one
    network within an hour (behind a reverse proxy only with correct `trusted_proxies`).
  - **"Certain spam"** with at least 7 points **and** signals from at least two of the three groups
    content, address, Tor. Tor weighs heavily: one clear content or address signal on top suffices (gibberish in several
    fields, dot-stuffed address, repetition); Tor with only a link or a missing MX record stays in the grey zone. Then no
    mail of the submission is sent; all of them go to the **spam archive** (see below). Registration: the mail to
    the registered address (activation) is still sent, so a human hit by a false positive is not locked out; the
    other mails go to the archive. Comments: unpublished, no mail to subscribers.
  - Otherwise everything runs normally, including the confirmation to the sender. In the form generator
    and for comments, at most three mails per entered address and day are sent.
- **Optional AI classification** for the grey zone (two groups present but not "certain spam"):
  `TURNSTILE_AI_KEY` in `.env.local`, `TURNSTILE_AI_PROVIDER` (`mistral` or `anthropic`), optionally
  `TURNSTILE_AI_MODEL`. If configured, a certain AI spam verdict also leads to "certain spam" there; any
  other verdict leads to normal processing; errors, timeouts (5 s)
  and the daily budget (150) lead to normal processing. Only text fields and the e-mail address are
  sent, never the IP; the provider is a data processor and belongs in the privacy policy.

### Spam archive

Back end under **System → Spam archive**: list of submissions classified as "certain spam" with date, source,
points, signals and subject. The detail view shows the text of the first mail as a preview and, per
withheld mail, its recipients and status; **"Deliver anyway"**
sends them afterwards unchanged, including attachments. If the outcome of a delivery is unclear (e.g. after an
abort), the view offers a resend only after 15 minutes, with a warning about possible duplicate delivery.

- Entries are deleted automatically after **90 days** (daily cron job).
- A system message on the back-end start page reports unreviewed entries.
- **Daily digest** (settings, off by default): one mail per day with date, source, points, signals and subject of
  the new entries, without the submission's content. Recipient is the configured address, otherwise the
  administrator address.
- If archiving fails (e.g. database error or a mail over 12 MB), the mail goes to the operators with `[Spam]` in
  the subject instead of to the address entered in the form; only without an administrator address does the original
  recipient remain. A lost submission weighs more than a spam mail
  in the error case.
- The archive contains personal data from the submission; with its 90-day retention it belongs in the privacy
  policy.

The secret key and internal data are never written to the log. Neither are form contents; the
classification only logs points and signal names (`fallback-pass`, `fallback-spam`).

## Why Turnstile instead of ALTCHA?

Since 5.4/5.5 Contao ships ALTCHA, its own proof-of-work captcha. Turnstile is a Cloudflare-backed
alternative (risk signals instead of pure in-browser computation) and makes sense for operators
already using Cloudflare. Both exist side by side as separate field types; this bundle does not
touch Contao's **own** ALTCHA field type.

As of **0.7.0** the bundle can optionally fall back to a **self-computed** ALTCHA proof-of-work
challenge (`turnstileFailureMode = altcha`), since **0.8.0** followed by a classification – independent of
Contao's internal ALTCHA (available from 5.4) and therefore identical on 4.13 and 5.x. See
[`UPGRADE.md`](UPGRADE.md) for details.

## Known limitations

- **Native newsletter subscription:** version-dependent. Newer Contao 5 versions resolve the
  newsletter captcha via the field-type registry (verified on 5.7) – there Turnstile applies
  **automatically**. On **Contao 4.13 and 5.3** the captcha is hardcoded to `FormCaptcha` in the
  core; there the default security question remains (no loss of function). The newsletter module
  also has its own core option to disable the captcha.
- **Spam archive:** deletion and the daily digest run via the Contao cron, which must be triggered regularly (cron job
  or visitor requests). The Notification Center's delivery logs show archived mails as sent. Mails created only in a
  background process are not classified.

### Template overrides

Anyone overriding `templates/form_mandrael_turnstile.html5` should compare their own override
against the bundle template after an update: when rendering, the bundle checks whether the
generated HTML contains all required fields (among them `data-sitekey`, the per-field-ID
Cloudflare attributes, and – with the ALTCHA fallback active – `data-challengeurl`/
`data-workerurl`). If something is missing, an error of category `template-outdated` is
written to the system log at most once per hour; the output itself stays unchanged. The exact
list of required markers is in [`UPGRADE.md`](UPGRADE.md). The ALTCHA solver additionally
reports its own errors via `console.warn` in the browser console.

## Compatibility

- **PHP:** 8.1+
- **Contao:** three LTS versions – **4.13 LTS, 5.3 LTS and 5.7 LTS** (incl. the intermediate 5.4–5.6) – from a single shared code base.
- **Tested** on a real instance each: **Contao 4.13 / PHP 8.1**, **Contao 5.3 / PHP 8.3** and
  **Contao 5.7 / PHP 8.4** – each with the active CAPTCHA override, back-end fields and correct
  rendering and fallback. Only Contao 6.0 (removal of the legacy template engine) will require an
  upgrade of this bundle.

## Technical quality features

**Robustness**

- **Automatic token refresh:** The Cloudflare widget stays in the DOM; tokens are refreshed automatically when they expire. The form therefore remains reliably submittable even when filled in slowly or resubmitted after a validation error.
- **Declarative rendering, no inline JavaScript:** Only Cloudflare's official external `api.js` is loaded. This is CSP-friendly (no `nonce`/`unsafe-inline` required); on Contao 5 the Cloudflare host is added to the Content Security Policy automatically.
- **Unique template name:** The front-end template uses a unique name and therefore does not collide with templates from other extensions or existing project templates.
- **Lossless configuration fallback:** With no keys configured, Turnstile globally disabled, or deselected per field, the field automatically uses Contao's default security question – no loss of function.
- **Fail-closed on every error:** Transport/timeout errors when communicating with Cloudflare and an invalid token both count as a failed check; the selected mode (`block` or `altcha`) decides what happens next. The secret key is never written to the log.

**Handling of the keys**

- **Secret stays server-side:** The secret key is used solely on the server for verification and is never delivered to the browser.
- **Does not trigger password managers:** The secret field uses `type="text"` with CSS masking (`-webkit-text-security`) instead of `type="password"`. Browsers and password managers therefore do not recognise it as a login field and offer neither saving nor autofill – while the field stays visually masked. The last characters of the stored secret are shown discreetly for verification.

**Compatibility & quality**

- **Three Contao LTS versions from one code base:** Contao 4.13 LTS, 5.3 LTS and 5.7 LTS (including the intermediate 5.x releases), PHP 8.1+ – verified under real conditions on 4.13, 5.3 and 5.7.
- **Clean install and uninstall:** no `runonce`/install scripts, no writes to the project file system; back-end fields are provided via the DCA (and removed with the bundle), the database columns and the two spam archive tables via `contao:migrate`.
- **Convenient key management** directly in the back end – no YAML or `.env` editing required.
- **Fine-grained control:** global activation mode (everywhere / only selected forms / off) plus per-field override.
- **Tested and maintained:** PHPUnit, PHPStan (level 5), CI across PHP 8.1–8.4; MIT license; adds no tracking whatsoever.

## Trademark notice

Cloudflare and Turnstile are trademarks of Cloudflare, Inc. This extension is an independent,
open-source project and is not affiliated with, endorsed or sponsored by Cloudflare, Inc. The
bundled icon (`logo.svg`) is original artwork and is not the Cloudflare logo.

## License

MIT – see [LICENSE](LICENSE).

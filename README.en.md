# Contao Cloudflare Turnstile

<img src="logo.svg" alt="Contao Turnstile" width="88" align="right">

[Deutsch](README.md) | **English**

Globally replaces Contao's default CAPTCHA (the security question) with
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). Keys are entered in the
Contao back end under **Settings** – no YAML or `.env` editing required.

A single code base for **Contao 4.13 LTS and Contao 5.3+** (incl. 5.4–5.7).

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
| Native newsletter subscription | ❌ no (see „Known limitations") |

**Important:** the bundle replaces the captcha **where a captcha field already exists**. It does
**not** add a captcha to forms that don't have one – add a captcha/security-question field as usual
and it becomes Turnstile automatically.

With **no keys** configured, Contao falls back automatically and losslessly to the default
security question.

## Installation

```bash
composer require mandrael/contao-turnstile
```

Then clear the Contao cache (Contao Manager or `vendor/bin/contao-console cache:clear`).

## Setup

1. Create a Turnstile widget in the
   [Cloudflare dashboard](https://dash.cloudflare.com/?to=/:account/turnstile) and copy the
   **site key** and **secret key**.
2. Add **all domains/hostnames** of the Contao installation to the Turnstile widget
   (e.g. `example.com`, `www.example.com`, any subdomains). If a domain is missing, verification
   fails on that domain.
3. In Contao under **Settings → Cloudflare Turnstile** enter the site key and secret key,
   optionally choose theme/size/appearance.

### Content Security Policy (CSP)

If you run a Content Security Policy, allow the Cloudflare host:

```
script-src https://challenges.cloudflare.com;
frame-src  https://challenges.cloudflare.com;
```

The widget uses the official external `api.js` and **no** inline JavaScript – no
`nonce`/`unsafe-inline` is required.

## Failure behaviour

- **Network/timeout errors** (Cloudflare unreachable, 5 s timeout) → the submission is **allowed**
  (fail-open) and an error is written to the Contao system log, so a Cloudflare outage does not
  bring down all forms.
- **Invalid/forged token** (`success: false`) → the submission is **blocked** (fail-closed). This
  also covers a wrong or expired site/secret key – then all forms block until the keys are fixed
  (a corresponding warning is written to the system log).

The secret key and internal data are never written to the log.

## Why Turnstile instead of ALTCHA?

Since 5.4/5.5 Contao ships ALTCHA, its own proof-of-work captcha. Turnstile is a Cloudflare-backed
alternative (risk signals instead of pure in-browser computation) and makes sense for operators
already using Cloudflare. Both exist side by side as separate field types; this bundle does not
touch ALTCHA.

## Known limitations

- **Native newsletter subscription:** the Contao newsletter module hardcodes its captcha in the
  core (not via the field-type registry). The global override does **not** apply there – the native
  newsletter subscription keeps showing the default security question (no loss of function).
  Coverage is conceivable as a later extension.
- **Non-native surfaces** (third-party newsletter iframes, chat widgets) are intentionally out of
  scope.

## Compatibility

- **PHP:** 8.1+
- **Contao:** 4.13 LTS and 5.3+ (incl. 5.4, 5.5, 5.6, 5.7) – a single shared code base.
- **Tested** on a real instance each: **Contao 4.13 / PHP 8.1**, **Contao 5.3 / PHP 8.3** and
  **Contao 5.7 / PHP 8.4** – each with the active CAPTCHA override, back-end fields and correct
  rendering and fallback. Only Contao 6.0 (removal of the legacy template engine) will require an
  upgrade of this bundle.

## Trademark notice

Cloudflare and Turnstile are trademarks of Cloudflare, Inc. This extension is an independent,
open-source project and is not affiliated with, endorsed or sponsored by Cloudflare, Inc. The
bundled icon (`logo.svg`) is original artwork and is not the Cloudflare logo.

## License

MIT – see [LICENSE](LICENSE).

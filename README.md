# Contao Cloudflare Turnstile

<img src="logo.svg" alt="Contao Turnstile" width="88" align="right">

Ersetzt das Standard-CAPTCHA von Contao (die Sicherheitsfrage) global durch
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). Die Keys werden
bequem im Contao-Backend unter **Einstellungen** eingetragen – keine YAML- oder
`.env`-Bearbeitung nötig.

Eine einzige Codebasis für **Contao 4.13 LTS und Contao 5.3+** (inkl. 5.4–5.7).

*(English version below.)*

---

## Funktionsweise

Das Bundle überschreibt den Captcha-Feldtyp (`$GLOBALS['TL_FFL']['captcha']`). Dadurch wird
überall dort, wo Contao ein Captcha über die Feldtyp-Registry auflöst, automatisch Turnstile
statt der Sicherheitsfrage angezeigt:

| Oberfläche | Turnstile aktiv? |
|---|---|
| Formulargenerator (Formular **mit** Captcha-Feld) | ✅ ja |
| Mitglieder-Registrierung | ✅ ja |
| Kommentare | ✅ ja |
| Native Newsletter-Anmeldung | ❌ nein (siehe „Bekannte Grenzen") |

**Wichtig:** Das Bundle ersetzt das Captcha **dort, wo bereits ein Captcha-Feld vorhanden ist**.
Es fügt Formularen ohne Captcha **kein** Turnstile hinzu. Um ein Formular zu schützen, fügt man
ihm wie gewohnt ein Captcha-/Sicherheitsfrage-Feld hinzu – dieses ist dann automatisch Turnstile.

Sind **keine Keys** hinterlegt, fällt Contao automatisch und verlustfrei auf die
Standard-Sicherheitsfrage zurück.

## Installation

```bash
composer require mandrael/contao-turnstile
```

Anschließend den Contao-Cache leeren (Contao-Manager oder `vendor/bin/contao-console cache:clear`).

## Einrichtung

1. Im [Cloudflare-Dashboard](https://dash.cloudflare.com/?to=/:account/turnstile) ein
   Turnstile-Widget anlegen und **Site Key** + **Secret Key** kopieren.
2. **Alle Domains/Hostnames** der Contao-Installation im Turnstile-Widget hinterlegen
   (z. B. `example.com`, `www.example.com`, ggf. Subdomains). Fehlt eine Domain, schlägt die
   Überprüfung auf dieser Domain fehl.
3. In Contao unter **Einstellungen → Cloudflare Turnstile** Site Key und Secret Key eintragen,
   optional Erscheinungsbild/Größe/Anzeige wählen.

### Content Security Policy (CSP)

Wird auf der Seite eine CSP eingesetzt, muss der Cloudflare-Host erlaubt sein:

```
script-src https://challenges.cloudflare.com;
frame-src  https://challenges.cloudflare.com;
```

Das Widget nutzt das offizielle, externe `api.js` und **kein** Inline-JavaScript – eine
`nonce`/`unsafe-inline` ist nicht erforderlich.

## Verhalten bei Cloudflare-Ausfall

- **Netzwerk-/Timeout-Fehler** (Cloudflare nicht erreichbar, 5 s Timeout) → das Formular wird
  **durchgelassen** (fail-open) und ein Fehler ins Contao-System-Log geschrieben. So legt ein
  Cloudflare-Ausfall nicht alle Formulare lahm.
- **Ungültiges/gefälschtes Token** (`success: false`) → das Formular wird **blockiert**
  (fail-closed).

Secret Key und interne Daten werden niemals ins Log geschrieben.

## Warum Turnstile statt ALTCHA?

Contao bringt seit 5.4/5.5 mit ALTCHA ein eigenes, Proof-of-Work-basiertes Captcha mit. Turnstile
ist eine Cloudflare-gestützte Alternative (Risiko-Signale statt reiner Rechenarbeit im Browser)
und für Betreiber sinnvoll, die ohnehin Cloudflare nutzen. Beide existieren als getrennte
Feldtypen nebeneinander; dieses Bundle berührt ALTCHA nicht.

## Bekannte Grenzen

- **Native Newsletter-Anmeldung:** Das Contao-Newsletter-Modul instanziiert sein Captcha im Core
  hartkodiert (nicht über die Feldtyp-Registry). Der globale Austausch greift dort **nicht** – die
  native Newsletter-Anmeldung zeigt weiterhin die Standard-Sicherheitsfrage (kein
  Funktionsverlust). Eine Abdeckung ist als spätere Erweiterung denkbar.
- **Nicht-native Flächen** (Newsletter-iframes externer Dienste, Chat-Widgets) sind bewusst nicht
  im Umfang.

## Kompatibilität

- **PHP:** 8.1+
- **Contao:** 4.13 LTS sowie 5.3+ (inkl. 5.4, 5.5, 5.6, 5.7) – eine gemeinsame Codebasis.
- Die Codebasis ist gegen die Contao-5.7-Quelle geprüft und aller Voraussicht nach kompatibel;
  ein **expliziter Funktionstest auf einer 5.7-Instanz steht noch aus**. Erst Contao 6.0
  (Entfernung der Legacy-Template-Engine) erfordert ein Upgrade dieses Bundles.

## Markenrechtlicher Hinweis

Cloudflare und Turnstile sind Marken der Cloudflare, Inc. Diese Erweiterung ist ein
unabhängiges, quelloffenes Projekt und steht in keiner Verbindung zu Cloudflare, Inc.; sie wird
von dieser weder unterstützt noch gesponsert. Das mitgelieferte Icon (`logo.svg`) ist eine eigene
Grafik und nicht das Cloudflare-Logo.

## Lizenz

MIT – siehe [LICENSE](LICENSE).

---

# Contao Cloudflare Turnstile (English)

Globally replaces Contao's default CAPTCHA (the security question) with
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). Keys are entered in the
Contao back end under **Settings** – no YAML or `.env` editing required. A single code base for
**Contao 4.13 LTS and Contao 5.3+** (incl. 5.4–5.7).

## How it works

The bundle overrides the captcha field type (`$GLOBALS['TL_FFL']['captcha']`), so Turnstile
replaces the security question everywhere Contao resolves a captcha through the field-type
registry: form generator (forms **that contain a captcha field**), member registration and
comments. It does **not** add a captcha to forms that don't have one – add a captcha field as
usual and it becomes Turnstile automatically. With no keys configured, Contao falls back to the
default security question.

## Installation & setup

```bash
composer require mandrael/contao-turnstile
```

1. Create a Turnstile widget in the Cloudflare dashboard, copy **site key** and **secret key**.
2. Add **all domains/hostnames** of the Contao site to the Turnstile widget – otherwise
   verification fails on a missing domain.
3. Enter both keys under **Settings → Cloudflare Turnstile** (optionally theme/size/appearance).

### CSP

If you run a Content Security Policy, allow `https://challenges.cloudflare.com` in both
`script-src` and `frame-src`. The widget uses the external `api.js` with no inline JavaScript, so
no nonce/`unsafe-inline` is needed.

## Failure behaviour

Network/timeout errors (Cloudflare unreachable, 5 s timeout) → submission is allowed (fail-open)
and logged. Invalid/forged token (`success: false`) → submission is blocked (fail-closed). The
secret key is never logged.

## Known limitations

- The native Contao **newsletter subscribe** module hardcodes its captcha and is therefore not
  covered by the global override; it keeps showing the default security question.
- Non-native surfaces (third-party newsletter iframes, chat widgets) are intentionally out of
  scope.

## Compatibility

PHP 8.1+, Contao 4.13 LTS and 5.3+ (incl. 5.4–5.7), single code base. Checked against the Contao
5.7 source and expected to be compatible; an explicit functional test on a 5.7 instance is still
pending. Contao 6.0 (removal of the legacy template engine) will require a bundle upgrade.

## Trademark notice

Cloudflare and Turnstile are trademarks of Cloudflare, Inc. This extension is an independent,
open-source project and is not affiliated with, endorsed or sponsored by Cloudflare, Inc. The
bundled icon (`logo.svg`) is original artwork and is not the Cloudflare logo.

## License

MIT – see [LICENSE](LICENSE).

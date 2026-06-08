# Contao Cloudflare Turnstile

<img src="logo.svg" alt="Contao Turnstile" width="88" align="right">

**Deutsch** | [English](README.en.md)

Ersetzt das Standard-CAPTCHA von Contao (die Sicherheitsfrage) global durch
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). Die Keys werden
bequem im Contao-Backend unter **Einstellungen** eingetragen – keine YAML- oder
`.env`-Bearbeitung nötig.

Eine einzige Codebasis für **Contao 4.13 LTS und Contao 5.3+** (inkl. 5.4–5.7).

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
  (fail-closed). Dazu zählt auch ein falscher oder abgelaufener Site/Secret Key – dann blockieren
  alle Formulare, bis die Keys korrigiert sind (eine entsprechende Warnung landet im System-Log).

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
- **Getestet** auf je einer echten Instanz: **Contao 4.13 / PHP 8.1**, **Contao 5.3 / PHP 8.3**
  und **Contao 5.7 / PHP 8.4** – jeweils mit aktivem CAPTCHA-Override, Backend-Feldern und
  korrektem Rendering bzw. Fallback. Erst Contao 6.0 (Entfernung der Legacy-Template-Engine)
  erfordert ein Upgrade dieses Bundles.

## Markenrechtlicher Hinweis

Cloudflare und Turnstile sind Marken der Cloudflare, Inc. Diese Erweiterung ist ein
unabhängiges, quelloffenes Projekt und steht in keiner Verbindung zu Cloudflare, Inc.; sie wird
von dieser weder unterstützt noch gesponsert. Das mitgelieferte Icon (`logo.svg`) ist eine eigene
Grafik und nicht das Cloudflare-Logo.

## Lizenz

MIT – siehe [LICENSE](LICENSE).

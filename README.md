# Contao Cloudflare Turnstile

<img src="logo.svg" alt="Contao Turnstile" width="88" align="right">

**Deutsch** | [English](README.en.md)

Ersetzt das Standard-CAPTCHA von Contao (die Sicherheitsfrage) global durch
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/). Die Keys werden
bequem im Contao-Backend unter **Einstellungen** eingetragen – keine YAML- oder
`.env`-Bearbeitung nötig.

Eine einzige Codebasis für **Contao 4.13 LTS und Contao 5.3+** (inkl. 5.4–5.7).

---

## Screenshots

Keys, Theme, Größe und Anzeige werden im **Contao-Backend** unter *Systemeinstellungen* eingetragen:

![Cloudflare-Turnstile-Einstellungen im Contao-Backend](.github/screenshots/backend-settings.png)

Pro Formular-Element lässt sich der **Captcha-Schutz** überschreiben (globale Vorgabe / Turnstile / Contao-Sicherheitsfrage):

![Captcha-Schutz pro Formular-Element](.github/screenshots/per-field.png)

Das **Turnstile-Widget** ersetzt die Standard-Sicherheitsfrage im Frontend-Formular (hier mit Cloudflare-Testkeys):

![Turnstile-Widget in einem Frontend-Formular](.github/screenshots/frontend-widget.png)

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
| Native Newsletter-Anmeldung | ⚠️ versionsabhängig (siehe „Bekannte Grenzen") |

**Wichtig:** Das Bundle ersetzt das Captcha **dort, wo bereits ein Captcha-Feld vorhanden ist**.
Es fügt Formularen ohne Captcha **kein** Turnstile hinzu. Um ein Formular zu schützen, fügt man
ihm wie gewohnt ein Captcha-/Sicherheitsfrage-Feld hinzu – dieses ist dann automatisch Turnstile.

Sind **keine Keys** hinterlegt, fällt Contao automatisch und verlustfrei auf die
Standard-Sicherheitsfrage zurück.

**Turnstile-Aktivierung (global) + Per-Feld-Steuerung:** Unter *Einstellungen → Cloudflare Turnstile*
legt die **Turnstile-Aktivierung** fest, wo Turnstile greift:

- **Standardmäßig für alle Formulare aktivieren** – Standard: überall aktiv, pro Feld abwählbar.
- **Nur bei ausgewählten Formularen aktivieren** – nur dort, wo es pro Feld gewählt wird.
- **Überall deaktivieren** – überall die Contao-Sicherheitsfrage (Keys bleiben gespeichert).

Jedes Captcha-Feld im Formulargenerator hat zusätzlich den **Captcha-Schutz** mit
**Globale Einstellung übernehmen / Turnstile / Contao-Sicherheitsfrage**, um die globale Vorgabe für
dieses eine Feld zu überschreiben – praktisch z. B. für Formulare in der **Fußzeile / auf jeder Seite**.

## Installation

### A) Contao Manager (empfohlen)

1. Im Contao Manager unter **Pakete** auf **Paket hinzufügen** klicken und nach `turnstile`
   (bzw. `mandrael/contao-turnstile`) suchen.
2. Mit **Hinzufügen** auswählen, dann **Änderungen übernehmen** – der Manager installiert die
   Erweiterung per Composer.
3. Danach die **Datenbank aktualisieren** (Manager-Schritt bestätigen) – legt das neue Feld an.

### B) Terminal (ohne GUI)

```bash
composer require mandrael/contao-turnstile
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

## Einrichtung

1. Im [Cloudflare-Dashboard](https://dash.cloudflare.com/?to=/:account/turnstile) ein
   Turnstile-Widget anlegen und **Site Key** + **Secret Key** kopieren.
2. **Alle Domains/Hostnames** der Contao-Installation im Turnstile-Widget hinterlegen
   (z. B. `example.com`, `www.example.com`, ggf. Subdomains). Fehlt eine Domain, schlägt die
   Überprüfung auf dieser Domain fehl.
3. In Contao unter **Einstellungen → Cloudflare Turnstile** Site Key und Secret Key eintragen,
   optional Erscheinungsbild/Größe/Anzeige wählen. Das **Erscheinungsbild** ist standardmäßig **Hell**
   (weiß) – auf **Dunkel** nur bei Bedarf umstellen, **Auto** passt sich dem System-Farbmodus an
   (Hell-/Dunkelmodus des Geräts, nicht der Seite).

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

- **Native Newsletter-Anmeldung:** versionsabhängig. Neuere Contao-5-Versionen lösen das
  Newsletter-Captcha über die Feldtyp-Registry auf (verifiziert in 5.7) – dort greift Turnstile
  **automatisch** mit. Auf **Contao 4.13 und 5.3** ist das Captcha im Core fest auf `FormCaptcha`
  verdrahtet; dort bleibt die Standard-Sicherheitsfrage (kein Funktionsverlust). Das Newsletter-Modul
  hat zudem eine eigene Core-Option „Captcha deaktivieren".
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

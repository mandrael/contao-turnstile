# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und dieses Projekt folgt der [Semantischen Versionierung](https://semver.org/lang/de/).

## [0.5.1] - 2026-06-21

### Behoben
- Mehrere Turnstile-Captchas innerhalb desselben Formulars verwendeten denselben
  Antwort-Feldnamen (`cf-turnstile-response`) und überschrieben sich beim Absenden
  gegenseitig. Der Feldname ist jetzt pro Widget-Instanz eindeutig (analog zum
  Standard-CAPTCHA von Contao), sodass jedes Feld unabhängig geprüft wird.

### Intern
- Testabdeckung ausgebaut: die Aktivierungs-Matrix (globaler Modus × Überschreibung
  je Formular-Element), die Token-Auslesung des Frontend-Widgets sowie die
  Konfigurations- und `remoteip`-Pfade der Server-Verifikation.

## [0.5.0] - 2026-06-09

### Hinzugefügt
- Ersetzt das Standard-CAPTCHA von Contao (Sicherheitsfrage) durch Cloudflare Turnstile;
  Site Key und Secret Key werden im Contao-Backend eingetragen (keine YAML-/`.env`-Bearbeitung).
- Eine gemeinsame Codebasis für die drei Contao-LTS-Versionen (4.13, 5.3, 5.7)
  inklusive der dazwischenliegenden 5.x-Releases; PHP 8.1+.
- Globaler Aktivierungsmodus (überall / nur ausgewählte Formulare / aus) plus Überschreibung
  je Formular-Element.
- Konfigurierbares Erscheinungsbild (hell / dunkel / automatisch), Größe und Widget-Anzeige.
- Deklaratives Widget-Rendering über das offizielle `api.js` von Cloudflare, ohne
  Inline-JavaScript; automatische Eintragung in die Content-Security-Policy unter Contao 5.
- Verlustfreier Fallback auf die Standard-Sicherheitsfrage von Contao, wenn keine Keys
  hinterlegt oder Turnstile deaktiviert ist.
- Differenziertes Fehlerverhalten: fail-open bei Transport-/Timeout-Fehlern, fail-closed bei
  ungültigem Token; der Secret Key wird nie ins Log geschrieben.
- Eindeutiger Frontend-Template-Name zur Vermeidung von Kollisionen mit anderen
  CAPTCHA-Erweiterungen.
- Maskiertes Secret-Key-Feld, das keine Browser-Passwortmanager triggert (kein Speichern-Dialog,
  kein Autofill); zur Kontrolle werden die letzten Zeichen des gespeicherten Secrets angezeigt.
- Deutsche und englische Backend-Beschriftungen und Dokumentation.

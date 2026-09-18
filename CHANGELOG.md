# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und dieses Projekt folgt der [Semantischen Versionierung](https://semver.org/lang/de/).

## [0.7.1] - UNRELEASED

### Geändert
- **Hostname-Bindung:** Der `hostname` der siteverify-Antwort wird jetzt gegen den Request-Host
  geprüft (normalisiert auf Klein-/Großschreibung, abschließenden Punkt und Punycode). Weicht er ab,
  gilt die Turnstile-Prüfung als fehlgeschlagen und eine Warnung mit beiden Namen landet im
  System-Log; wie bei jedem Fehlschlag entscheidet danach die gewählte Fallback-Stufe – in `block`
  und `altcha` wird blockiert (bzw. nur mit gültigem Rechenbeweis durchgelassen), in `filter` kommt
  die Übermittlung nach Honeypot- und Zeitprüfung weiter durch. Übersprungen bei fehlendem Request,
  fehlendem `hostname`-Feld oder einem der drei Cloudflare-Test-Secrets.
- **Transport-/Dekodierfehler blockieren jetzt fail-closed:** Ist Cloudflare nicht erreichbar oder die
  Antwort unverwertbar, entscheidet die konfigurierte Fallback-Stufe (`block`/`filter`/`altcha`) über
  das weitere Vorgehen, statt die Prüfung wie bisher stillschweigend als bestanden zu werten.
- **Kein stiller Soft-Pass mehr im Modus `altcha`:** Ist ALTCHA nicht verfügbar (kein HTTPS erkannt,
  Route/Assets nicht auflösbar), wird jetzt blockiert statt zu `filter` durchgelassen; die
  Log-Kategorie `altcha-unavailable` (seit 0.7.0, jetzt neu mit Level `error` statt `info` und mit
  Blockieren statt Durchlassen) hält die Betriebsstörung im System-Log fest.
- **Zeitstempel im Modus `altcha` fail-closed geprüft:** Ein fehlendes oder falsch signiertes Feld
  `cf-turnstile-ts-<id>` gilt jetzt als Blockierung (neue Log-Kategorie `altcha-timing-invalid`), statt
  wie im Modus `filter` durchgelassen zu werden.
- **Replay-Schutz des ALTCHA-Verifiers fail-closed:** Wirft der Cache oder liefert `save()` `false`,
  gilt der Proof of Work jetzt als ungültig, statt ohne funktionierenden Replay-Marker trotzdem
  durchzugehen.
- **ALTCHA-Challenge-Route nur `GET`:** Die Route `mandrael_turnstile_altcha` akzeptiert nicht mehr
  jede HTTP-Methode.
- **Solver behält die alte Lösung:** Beim Re-Solve (bfcache, 45-Minuten-Intervall) bleibt das Feld
  bis zur neuen Lösung gefüllt, statt für die Dauer der Suche leer zu sein – ein Nutzer mit
  Turnstile-Fehlschlag wurde in diesem Fenster sonst fälschlich als `altcha-empty` geblockt. Schlägt
  das Erneuern fehl, bleibt der alte Wert stehen; der Server lehnt ihn dann gegebenenfalls als
  `altcha-invalid` ab (statt wie zuvor als `altcha-empty`).

## [0.7.0] - 2026-07-12

### Hinzugefügt
- **Fallback-Stufe „ALTCHA" (Proof of Work)** als dritter Wert der Einstellung „Verhalten, wenn
  Turnstile-Prüfung fehlschlägt" (`turnstileFailureMode = altcha`). Schlägt Turnstile fehl und greift
  der Honeypot/Timing-Filter nicht, muss der Browser eine lokale SHA-256-Rechenaufgabe gelöst haben –
  ein serverseitig verifizierter Zweitbeweis statt bloßem Durchlassen. Kein externer Dienst, keine
  Cookies, keine Datenbank, kein Cron. Der Proof of Work wird selbst gerechnet (unabhängig von Contaos
  internem, ab 5.4 verfügbarem ALTCHA) und verhält sich damit auf 4.13 und 5.x identisch.
- Die Rechenaufgabe läuft **headless** im Hintergrund (Web Worker) und schreibt die Lösung in ein
  verstecktes Feld – **kein sichtbares Widget, kein Blockieren des Absendens im Browser.** Fällt die
  Berechnung oder der Challenge-Abruf aus, bleibt das Feld leer und allein der Server entscheidet; wer
  Turnstile besteht, wird nie beeinträchtigt.
- Diagnostische Log-Kategorien für den ALTCHA-Fallback (`altcha-empty` = fehlende Lösung, meist
  kaputtes JavaScript/kein Secure Context; `altcha-invalid` = ungültige/abgelaufene/wiederverwendete
  Lösung), damit sich Betriebsstörung und Angriff im Contao-System-Log unterscheiden lassen.

## [0.6.0] - 2026-07-01

### Hinzugefügt
- **Robusterer Feldname:** Kommt das Antwort-Token unter dem Cloudflare-Standardnamen
  `cf-turnstile-response` (ohne `-<id>`-Suffix) an – etwa durch einen Template-Override –,
  wird es jetzt zusätzlich akzeptiert, statt das Feld still zu blockieren.
- **Diagnose bei fehlendem Token:** Trifft gar kein Token ein (kaputter Template-/Feldname,
  deaktiviertes JavaScript), wird genau eine Warnung ins Contao-System-Log geschrieben. Ein
  flächiger Ausfall ist damit binnen Minuten sichtbar; abgelehnte Tokens bleiben weiterhin still.
- **Fallback-Modus bei fehlgeschlagener Prüfung** (Einstellung „Verhalten, wenn Turnstile-Prüfung
  fehlschlägt", Werte `block`/`filter`, Standard `block` = blockierend). Im **Fallback** (`filter`)
  wird ein fehlgeschlagenes oder fehlendes Token nicht abgewiesen, sondern – nach einem Sekundärfilter –
  durchgelassen und protokolliert (Kategorie `missing-token` bzw. `verification-failed`, ohne
  Token/PII). Brücke gegen Turnstile-False-Positives (Firewalls, Safari/iCloud Private Relay/ITP).
  Tradeoff siehe `UPGRADE.md`.
- **Eingebauter Sekundärfilter für den Fallback:** Vor dem Durchlassen läuft ein zweistufiger
  Bot-Filter – **Honeypot** (verstecktes Feld; befüllt → blockiert) und **Timing** (signierter
  Render-Zeitstempel; Absenden in unter 3 s → blockiert). Ohne Konfiguration, ohne Reibung für echte
  Nutzer. So werden offensichtliche Bots auch im Fallback geblockt, während Turnstile-Fehlalarme
  (Privacy-Browser) weiter durchkommen. Fehlt/bricht der Zeitstempel (Cache/Template-Override),
  greift der Honeypot allein – kein Fehlalarm-Risiko.
- **Option „Besucher-IP an Cloudflare senden"** (Standard: an, unverändertes Verhalten). Hinter
  NAT/VPN/iCloud Private Relay lässt sich das Senden der `remoteip` nun abschalten.
- **`<noscript>`-Hinweis** im Widget-Template (übersetzbar via `MSC.turnstileNoscript`,
  per Leer-Übersetzung abschaltbar) – erspart Integratoren den fehleranfälligen Template-Override.

### Behoben
- **Backend-Dark-Mode:** Der Secret-Key-Hinweis (letzte 4 Zeichen) nutzt die
  Contao-Klasse `tl_gray` statt einer festen Farbe (`#999`) und schaltet im
  Contao-5-Dark-Mode mit.

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

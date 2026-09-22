# Upgrade

## 0.7.1 → 0.8.0

**Keine Migration, keine neuen Einstellungen. Eine Verhaltensänderung in den Modi `filter` und `altcha`.**

**Die Ersatzstufe greift nur noch bei einem bestätigten Cloudflare-Ausfall.** Bis 0.7.1 sprang sie bei
jedem Fehlschlag ein, auch wenn Turnstile einem Absender kein Token gab oder es ablehnte. Ein
Browser-Bot konnte so den Proof-of-Work lösen und durchkommen. Jetzt fragt der Server siteverify mit
einem Platzhalter-Token selbst an; erst wenn das seit mindestens 30 Sekunden scheitert, öffnet die
Ersatzstufe. Sonst wird blockiert, im System-Log mit der Kategorie `fallback-withheld`.

Folgen für Betreiber:

- Besucher, die Cloudflare nur selbst nicht erreichen (Tor, manche Privacy-Browser, Firmen-Firewalls),
  werden auch in `filter`/`altcha` abgewiesen.
- Ein Template-Override ohne Token-Feld oder ein JavaScript-Fehler sperrt die Formulare jetzt auch in
  `filter`/`altcha` vollständig, solange Cloudflare erreichbar ist. Häufen sich nach dem Update
  `fallback-withheld`-Einträge zusammen mit „kein Token im Request", zuerst den Override prüfen
  (siehe unten); Notbremse ist `turnstileMode = off` (Rückfall auf die Contao-Sicherheitsfrage).
- `filter` gilt als veraltet: Im Ausfall schützt es nur per Honeypot und Mindestzeit. Empfohlen ist
  `altcha` oder `block`.

### Template-Overrides

Wer `templates/form_mandrael_turnstile.html5` überschrieben hat, sollte nach dem Update das
System-Log auf die Kategorie `template-outdated` prüfen und den Override gegen das
Bundle-Template abgleichen. Geprüft werden folgende Pflichtmarker (aus
`FormTurnstile::missingTemplateMarkers()` und `FormTurnstile::checkTemplateMarkers()`):

- `cf-turnstile-response-<id>`, `cf-turnstile-hp-<id>`, `cf-turnstile-ts-<id>` – immer.
- `data-sitekey` – immer.
- `altcha-<id>`, `data-mandrael-altcha`, `data-challengeurl`, `data-workerurl` – nur bei
  aktivem ALTCHA-Fallback (`turnstileFailureMode = altcha` und Secure Context).
- `$GLOBALS['TL_BODY']['mandrael-altcha']` (das Solver-Skript, im Log als
  `TL_BODY[mandrael-altcha]`) – ebenfalls nur bei aktivem ALTCHA-Fallback.

Wer die Worker-Adresse in einem Override fest eingetragen hat, kann wieder
`$this->turnstileWorkerUrl` verwenden (seit 0.8.0 same-origin aufgelöst, siehe
`CHANGELOG.md`).

**Bekannte Grenze:** Geprüft wird nur beim echten Rendern, nicht bei Auslieferung aus dem
Seiten-Cache – ein zwischengespeichertes, veraltetes HTML wird nicht erkannt. Ein Override, der
Turnstile per JavaScript rendert (`turnstile.render()`) und dafür das HTML-Attribut
`data-sitekey` weglässt, wird ebenfalls als veraltet gemeldet; ein solcher Override muss
`data-sitekey` im HTML behalten.

## 0.7.0 → 0.7.1

**Keine DB-Migration nötig, keine neuen Einstellungen.** Drei für Betreiber sichtbare
Verhaltensänderungen, weil 0.7.1 mehrere bisherige Fail-open-Stellen auf fail-closed umstellt; die
weiteren Härtungen ohne sichtbares Verhalten stehen im `CHANGELOG.md`:

1. **Ist Cloudflare nicht erreichbar, entscheidet jetzt die gewählte Fallback-Stufe.** Im Modus
   `block` (Standard) sind Formulare für die Dauer des Ausfalls gesperrt; wer das nicht will, wählt
   `filter` oder `altcha`.
2. **Der Modus `altcha` degradiert nicht mehr still zu `filter`.** Wird HTTPS nicht erkannt
   (Reverse-Proxy ohne `trusted_proxies`) oder sind Route/Assets nicht auflösbar, wird blockiert und
   im System-Log ein Fehler `altcha-unavailable` geschrieben. Außerdem muss ein Template-Override das
   Feld `cf-turnstile-ts-<id>` führen; nach einer Rotation von `kernel.secret` (`APP_SECRET`) den
   Seiten-Cache leeren, sonst `altcha-timing-invalid`.
3. **Der von Cloudflare gemeldete Hostname muss zum Host des Requests passen;** Abweichungen stehen
   als Warnung mit beiden Namen im System-Log. Die Cloudflare-Test-Schlüssel sind ausgenommen.

## 0.6.0 → 0.7.0

Additiv und rückwärtskompatibel. **Keine DB-Migration nötig.** Bestandsinstallationen verhalten sich
ohne Änderung unverändert; `altcha` ist ein opt-in-Wert des bestehenden Failure-Modus.

### Neue Fallback-Stufe: ALTCHA (`turnstileFailureMode = altcha`)

Dritter Wert der Einstellung „Verhalten, wenn Turnstile-Prüfung fehlschlägt", nach `block` und `filter`.
Schlägt Turnstile fehl und greift der Honeypot/Timing-Sekundärfilter nicht, verlangt das Bundle einen
**Proof of Work**: der Browser löst im Hintergrund eine lokale SHA-256-Rechenaufgabe, deren Lösung der
Server prüft (Signatur + Ablauf + Einmaligkeit). Ein echter Zweitbeweis statt bloßem Durchlassen – ohne
externen Dienst, ohne Cookies, ohne Datenbank, ohne Cron.

- **Headless, kein Client-Blocking:** Die Rechenaufgabe läuft unsichtbar in einem Web Worker; die Lösung
  landet in einem versteckten Feld. Fällt der Challenge-Abruf oder die Berechnung aus, bleibt das Feld
  leer und der Server entscheidet – **wer Turnstile besteht, kann immer absenden.** Nur Turnstile-Versager
  ohne gültige Lösung werden abgewiesen.
- **Secure Context nötig:** Die Rechenaufgabe braucht die Web-Crypto-API, also HTTPS (oder `localhost`).
  Auf unsicherem Kontext degradiert der `altcha`-Modus automatisch zum `filter`-Verhalten (durchlassen +
  protokollieren), statt echte Besucher hart abzuweisen (**ab 0.7.1: wird blockiert, siehe oben,
  Abschnitt 0.7.0 → 0.7.1**).
  Praktisch heißt das: **`altcha` setzt eine
  HTTPS-Site voraus** (idealerweise mit erzwungenem `http→https`-Redirect). Hinter einem TLS-terminierenden
  Reverse-Proxy muss `framework.trusted_proxies` korrekt gesetzt sein – sonst meldet Symfony
  `isSecure() = false`, und der Modus degradiert **still zu `filter`** (im System-Log als `altcha-unavailable`
  sichtbar; **ab 0.7.1: wird blockiert, siehe oben, Abschnitt 0.7.0 → 0.7.1**).
- **Content-Security-Policy:** Unter Contao 5 trägt das Bundle `script-src`/`worker-src`/`connect-src 'self'`
  automatisch ein, sofern die Seite eine CSP nutzt. Contao 4.13 hat keine CSP-API – dort ergänzt ein
  Integrator mit eigener CSP diese Quellen selbst (gleiche Bringschuld wie beim Turnstile-Host).
- **Nicht-Managed-Setup:** Läuft Contao ohne Manager-Plugin (das Bundle in einer eigenen Symfony-App),
  wird die Challenge-Route nicht registriert. Der `altcha`-Modus erkennt das (die Route lässt sich nicht
  erzeugen) und degradiert dann zum **Filter-Verhalten** (Honeypot/Zeitprüfung, Rest durchlassen und
  protokollieren) – kein Fehler, kein Crash, kein hartes Abweisen echter Besucher (**ab 0.7.1: wird
  blockiert, siehe oben, Abschnitt 0.7.0 → 0.7.1**).
- **Template-Override abgleichen:** Wer `form_mandrael_turnstile.html5` in `templates/` überschrieben hat
  (aus 0.5/0.6), muss den neuen ALTCHA-Block (Hidden-Feld + Solver-Script) übernehmen. Sonst fehlt im
  `altcha`-Modus das Solver-Feld, und **jeder Turnstile-Fehlschlag wird hart geblockt** (Log `altcha-empty`),
  obwohl der mildere Modus gewählt wurde.

Der Failure-Modus bleibt global (kein Per-Feld-Override). `altcha` ersetzt `filter` nicht, sondern erweitert
es: Honeypot und Timing laufen weiterhin **vor** der Rechenaufgabe.

## 0.5.x → 0.6.0

Additiv und rückwärtskompatibel. Bestandsinstallationen verhalten sich ohne Änderung
unverändert (Blockieren, Besucher-IP wird gesendet). **Keine DB-Migration nötig.**

### Neue Einstellung: Verhalten, wenn Turnstile-Prüfung fehlschlägt (`turnstileFailureMode`)

Standard `block`.

- **block** – bei fehlgeschlagener Turnstile-Prüfung wird das Absenden blockiert (bisheriges Verhalten).
- **filter** (Fallback) – das Absenden wird nach einem Sekundärfilter zugelassen und jede durchgelassene
  Submission ins Contao-System-Log geschrieben (Kategorie `missing-token` bzw. `verification-failed`,
  ohne Token/PII).

> **Der Fallback (`filter`) ist eine Brücke, keine vollwertige Bot-Abwehr.** Er ist gedacht zur
> Überbrückung Cloudflare-inhärenter False-Positives (Firewalls, Safari/iCloud Private Relay/ITP),
> bis auf eine andere Lösung migriert wird.

**Eingebauter Sekundärfilter (ab 0.6.0):** Im Fallback läuft vor dem Durchlassen ein zweistufiger
Bot-Filter – ohne Konfiguration, ohne Reibung für echte Nutzer:

- **Honeypot** – ein per CSS verstecktes Feld, das nur Skripte ausfüllen. Befüllt → blockiert.
- **Timing** – ein signierter Render-Zeitstempel. In unter 3 Sekunden abgeschickt → blockiert
  (kein Mensch liest und füllt ein Formular so schnell aus).

Beide greifen nur im Fallback (im `block`-Modus blockiert die fehlgeschlagene Prüfung ohnehin).
Echte Nutzer, die Turnstile fälschlich abweist, kommen weiter durch; der offensichtliche Bot-Müll
nicht mehr. Fehlt/bricht der Zeitstempel (Cache, Template-Override), greift der Honeypot allein – es
entstehen keine Fehlalarme. Dauerhaft im Fallback bleibt dennoch schwächer als `block`.

> **Ausblick 0.7.0:** Der Fallback bekommt eine zusätzliche Stufe **ALTCHA** – nach Honeypot/Timing
> eine lokale Proof-of-Work-Aufgabe (kein externer Dienst, DSGVO-freundlich) statt bloßem Durchlassen.

Der Failure-Modus ist global. Ein Per-Feld-Override (analog zur Aktivierung je Formular-Element)
ist bewusst nicht enthalten; `turnstileMode`/`turnstileField` steuern weiterhin nur, **ob** Turnstile
greift, `turnstileFailureMode` steuert, **was** bei einer fehlgeschlagenen Prüfung passiert.

### Neue Einstellung: Besucher-IP an Cloudflare senden (`turnstileSendRemoteIp`)

Standard an. Abschalten entfernt die `remoteip` aus der siteverify-Anfrage. Hinter NAT/VPN/iCloud
Private Relay kann das sinnvoll sein; Cloudflare validiert die IP nicht strikt (kein dokumentierter
IP-Mismatch-Fehlercode). **Hygiene-Option, kein garantierter Safari-Fix.**

### Diagnose bei Totalausfall

Kommt für ein Feld gar kein Token an (Template-Override, der den Feldnamen
`cf-turnstile-response-<id>` verliert; deaktiviertes JavaScript), schreibt das Bundle nun eine
**Warnung** ins Contao-System-Log („kein Token im Request – Template/Feldname prüfen"). Ein flächiger
Ausfall wird so binnen Minuten sichtbar statt erst durch Kundenmeldungen. Diese Warnung feuert auch
im `filter`-Modus. Abgelehnte Tokens (Bot-Replays) bleiben absichtlich still.

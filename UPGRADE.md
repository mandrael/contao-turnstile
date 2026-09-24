# Upgrade

## 0.7.1 → 0.8.0

**Datenbank-Migration nötig:** `contao:migrate` (bzw. Contao Manager) legt die zwei Tabellen der Spam-Ablage
(`tl_turnstile_spam`, `tl_turnstile_spam_message`) und die neuen Einstellungsfelder an. Zwei Verhaltensänderungen in
der Ersatzstufe, eine neue optionale Umgebungsvariable.

**Einsendungen ohne Token werden angenommen und eingestuft.** Im Modus `altcha` greift nach einem
gescheiterten Turnstile-Versuch wie bisher die mechanische Prüfung (Honeypot, signierter Zeitstempel,
Mindestzeit, Rechenaufgabe). Wer sie besteht, wird jetzt zusätzlich eingestuft. Nur bei „Spam sicher"
(mindestens 7 Punkte aus mindestens zwei der Gruppen Inhalt, Adresse und Tor, über Tor mit einem
Signal ab 3 Punkten, oder mit eingerichteter KI deren sicheres Urteil im Graubereich) geht keine Mail
der Einsendung hinaus; alle landen in der Spam-Ablage (Backend: System → Spam-Ablage) und lassen sich dort mit
„Doch zustellen" nachträglich versenden. Die Ablage löscht Einträge nach 90 Tagen; sie ist die einzige
vollständige Kopie der Einsendung, sofern das Formular nicht speichert. Ungeprüfte Einträge meldet eine
Systemnachricht, auf Wunsch zusätzlich eine Tageszusammenfassung (Einstellungen, Standard aus). Sonst läuft alles
wie bisher, einschließlich Bestätigung an den Absender.

Folgen für Betreiber:

- **Empfohlen für Anmelde-, Buchungs- und Kontaktformulare ist `altcha`.** Standard bleibt `block`;
  bestehende Installationen werden nicht umgestellt.
- **`filter` entfällt als eigene Option und wirkt wie `altcha`.** Das ist eine Verschärfung: Ohne
  gelöste Rechenaufgabe wird eine Einsendung ohne Token jetzt abgewiesen. Ein gespeichertes `filter`
  wird im Backend als `altcha` angezeigt.
- **Notification Center:** Abgefangen wird über einen Dekorator des Symfony-Mailers, also auch Mails des
  Notification Centers samt Anhängen. Mit Notification Center 2.7 geprüft; mit 1.x ungeprüft.
- **Rückfallweg:** Scheitert das Ablegen (Datenbankfehler, Mail über 12 MB), geht die Mail mit `[Spam]` im Betreff
  an die Betreiber; die im Formular eingetragene Adresse wird gestrichen (ohne Administrator-Adresse bleibt sie als
  einziger Empfänger, damit nichts verloren geht). Adressen aus dem Empfängerfeld des
  Formulars, die Admin-Adresse und jede Adresse auf der Domain der Website werden dabei nie gestrichen. Eine
  Notification-Center-Empfängeradresse auf einer fremden Domain kennt das Bundle nicht: Trägt ein Bot genau diese
  Adresse ein, entfällt auf dem Rückfallweg die Mail dorthin. Abhilfe: eine Empfängeradresse auf der Domain der
  Website oder die Admin-Adresse verwenden. Dieselbe Regel gilt für die Mailbegrenzung unten.
- **Registrierung:** Die Mail an die registrierte Adresse (Aktivierung) geht bei „Spam sicher" trotzdem hinaus;
  die übrigen Mails, etwa die Admin-Benachrichtigung, landen in der Ablage. **Kommentare:** bei „Spam sicher" unveröffentlicht, ohne
  Benachrichtigung der Abonnenten.
- **Mailbegrenzung:** Bei Einsendungen ohne Token gehen im Formulargenerator und bei Kommentaren
  höchstens drei Mails je eingetragener Adresse und Tag hinaus; die Mail an den Betreiber bleibt immer.
- **Hinter einem Reverse-Proxy** zählen Netz-Häufung und Tor-Erkennung nur richtig, wenn
  `trusted_proxies` gesetzt ist.
- **Tor-Liste:** Bei einer Einsendung ohne Token lädt das Bundle die Liste der Tor-Ausgangsknoten von
  `check.torproject.org` (6 Stunden zwischengespeichert, nach einem Fehlschlag 10 Minuten Pause). Es werden
  keine Nutzerdaten übertragen. Ohne ausgehende Verbindung fehlt nur dieses Signal.
- **Optionale KI-Einordnung** (Standard aus): `TURNSTILE_AI_KEY` in `.env.local` aktiviert sie,
  `TURNSTILE_AI_PROVIDER` wählt `mistral` (Standard) oder `anthropic`, `TURNSTILE_AI_MODEL`
  überschreibt das Modell (Standard `mistral-small-2603` bzw. `claude-sonnet-5`). Übermittelt werden
  nur Textfelder und Mailadresse einer tokenlosen Einsendung im Graubereich, nie die IP. Der Anbieter
  ist Auftragsverarbeiter und gehört in die Datenschutzerklärung. Ob sie aktiv ist, zeigen die Einstellungen.
- **Datenschutz:** Die Spam-Ablage speichert die zurückgehaltenen Mails 90 Tage lang in der Datenbank; das gehört
  in die Datenschutzerklärung.
- Die Fehlermeldung `$GLOBALS['TL_LANG']['ERR']['turnstile']` nennt jetzt einen Ausweg („direkt per
  E-Mail oder Telefon"); wer eine eigene Übersetzung pflegt, sollte das übernehmen.
- Empfehlung für die Danke-Seite kritischer Formulare: ein Satz wie „Keine Bestätigung erhalten? Bitte
  melden Sie sich direkt unter …".

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

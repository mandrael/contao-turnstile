# Upgrade

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
  protokollieren), statt echte Besucher hart abzuweisen.
- **Content-Security-Policy:** Unter Contao 5 trägt das Bundle `script-src`/`worker-src`/`connect-src 'self'`
  automatisch ein, sofern die Seite eine CSP nutzt. Contao 4.13 hat keine CSP-API – dort ergänzt ein
  Integrator mit eigener CSP diese Quellen selbst (gleiche Bringschuld wie beim Turnstile-Host).
- **Nicht-Managed-Setup:** Läuft Contao ohne Manager-Plugin (das Bundle in einer eigenen Symfony-App),
  wird die Challenge-Route nicht registriert. Der `altcha`-Modus erkennt das (die Route lässt sich nicht
  erzeugen) und degradiert dann zum **Filter-Verhalten** (Honeypot/Zeitprüfung, Rest durchlassen und
  protokollieren) – kein Fehler, kein Crash, kein hartes Abweisen echter Besucher.

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

# Upgrade

## 0.5.x → 0.6.0

Additiv und rückwärtskompatibel. Bestandsinstallationen verhalten sich ohne Änderung
unverändert (hartes Blockieren, Besucher-IP wird gesendet). **Keine DB-Migration nötig.**

### Neue Einstellung: Verhalten bei fehlgeschlagener Prüfung (`turnstileBlocking`)

Standard `hart`.

- **hart** – bei fehlgeschlagener Turnstile-Prüfung wird das Absenden blockiert (bisheriges Verhalten).
- **weich** – das Absenden wird trotzdem zugelassen und jede durchgelassene Submission ins
  Contao-System-Log geschrieben (Kategorie `missing-token` bzw. `verification-failed`, ohne Token/PII).

> **„weich" ist eine Brücke, keine Lösung.** Die Schranke ist dann für alles offen, das den Token
> nicht löst – auch für Bots. Nur für Übergangszeiträume nutzen und idealerweise mit einer
> zusätzlichen Schicht kombinieren (Honeypot/Timing). Gedacht z. B. zur Überbrückung
> Cloudflare-inhärenter False-Positives (Safari/iCloud Private Relay/ITP), bis auf eine andere
> Lösung migriert wird.

Der Blocking-Modus ist global. Ein Per-Feld-Override (analog zur Aktivierung je Formular-Element)
ist bewusst nicht enthalten; `turnstileMode`/`turnstileField` steuern weiterhin nur, **ob** Turnstile
greift, `turnstileBlocking` steuert, **was** bei einer fehlgeschlagenen Prüfung passiert.

### Neue Einstellung: Besucher-IP an Cloudflare senden (`turnstileSendRemoteIp`)

Standard an. Abschalten entfernt die `remoteip` aus der siteverify-Anfrage. Hinter NAT/VPN/iCloud
Private Relay kann das sinnvoll sein; Cloudflare validiert die IP nicht strikt (kein dokumentierter
IP-Mismatch-Fehlercode). **Hygiene-Option, kein garantierter Safari-Fix.**

### Diagnose bei Totalausfall

Kommt für ein Feld gar kein Token an (Template-Override, der den Feldnamen
`cf-turnstile-response-<id>` verliert; deaktiviertes JavaScript), schreibt das Bundle nun eine
**Warnung** ins Contao-System-Log („kein Token im Request – Template/Feldname prüfen"). Ein flächiger
Ausfall wird so binnen Minuten sichtbar statt erst durch Kundenmeldungen. Diese Warnung feuert auch
im `weich`-Modus. Abgelehnte Tokens (Bot-Replays) bleiben absichtlich still.

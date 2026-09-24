<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Service;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stuft eine Einsendung ohne gültiges Turnstile-Token ein, nachdem sie die mechanische Stufe (Honeypot,
 * Zeitstempel, Mindestzeit, Proof-of-Work) bestanden hat. Ergebnis ist nur „Spam sicher" oder „sauber":
 * Es gibt keine Prüfwarteschlange, und „sauber" heißt vollständig normale Verarbeitung samt Bestätigung.
 *
 * Keine Einzelregel entscheidet. „Spam sicher" verlangt eine Punktsumme ab SURE UND Signale aus mindestens
 * zwei von drei unabhängigen Gruppen: Inhalt (was geschrieben wurde), Adresse (wohin die Bestätigung ginge) und
 * Tor (woher die Einsendung kommt). Tor wiegt schwer: Über Tor meldet sich praktisch niemand zu einem Kurs an
 * (Vorgabe Michael, 23.09.2026); zusammen mit einem deutlichen weiteren Signal (ab 3 Punkten) reicht es. Der
 * Netz-Andrang zählt nur Punkte, nie als Gruppe: Hinter einem Firmen- oder Schul-NAT träfe er echte Menschen.
 * Die KI darf nur fehlende Punkte ersetzen, nie eine fehlende Gruppe; auch Tor allein genügt ihr nicht.
 */
class SpamClassifier
{
    public const SURE = 7;

    private const POINTS_GIBBERISH_MANY = 4;
    private const POINTS_GIBBERISH_ONE = 2;
    private const POINTS_LINK = 2;
    private const POINTS_REPEAT = 4;
    private const POINTS_DOTTED_ADDRESS = 3;
    // Nur 2: Ohne MX-Eintrag stellt SMTP an den A-Eintrag zu, kleine Firmendomains kommen so aus. Mit Link und
    // einem Stichwortfeld (2 + 2) bleibt ein solcher Mensch unter SURE.
    private const POINTS_NO_MX = 2;
    private const POINTS_NET_BURST = 2;
    private const POINTS_TOR = 5;

    // Offizielle Ausgangsliste des Tor-Projekts (eine IP je Zeile). Abruf nur bei einer tokenlosen Einsendung,
    // ohne Nutzerdaten; 6 h gecacht, nach einem Fehlschlag 10 min nicht erneut. Fehler = kein Treffer.
    private const TOR_LIST_URL = 'https://check.torproject.org/torbulkexitlist';
    private const TOR_TTL = 21600;
    private const TOR_RETRY = 600;
    private const TOR_TIMEOUT = 3;

    private const REPEAT_NETS = 3;
    private const REPEAT_TTL = 86400;
    private const NET_BURST_LIMIT = 5;
    private const NET_BURST_TTL = 3600;
    private const MX_TTL = 3600;
    private const RECIPIENT_DAILY_LIMIT = 3;

    // Häufigste Funktionswörter der Sprachen, in denen echte Anfragen realistisch kommen. Ein Text mit
    // mindestens fünf Wörtern enthält in jeder dieser Sprachen fast sicher eines davon. Einbuchstabige Wörter
    // fehlen bewusst: Zufallstext enthält sie zufällig.
    private const FUNCTION_WORDS = [
        // de
        'der', 'die', 'das', 'und', 'ist', 'nicht', 'ich', 'sie', 'er', 'es', 'wir', 'ihr', 'ein', 'eine',
        'zu', 'in', 'im', 'mit', 'von', 'für', 'auf', 'den', 'dem', 'des', 'am', 'an', 'bei', 'mich', 'mir',
        'mein', 'meine', 'sich', 'auch', 'als', 'wie', 'was', 'bitte', 'danke', 'hallo', 'gerne', 'habe',
        'hat', 'haben', 'sind', 'bin', 'war', 'oder', 'aber', 'wenn', 'dass', 'um', 'aus', 'nach', 'über',
        // en
        'the', 'and', 'is', 'are', 'to', 'of', 'for', 'on', 'with', 'you', 'we', 'my', 'your', 'it', 'this',
        'that', 'be', 'have', 'not', 'please', 'thanks', 'hello', 'an', 'at', 'from', 'as', 'by', 'or',
        // fr
        'le', 'la', 'les', 'et', 'est', 'un', 'une', 'des', 'de', 'du', 'je', 'vous', 'nous', 'pour',
        'dans', 'avec', 'pas', 'que', 'qui', 'sur', 'au', 'ce', 'merci', 'bonjour',
        // it, es, pt
        'il', 'lo', 'gli', 'di', 'da', 'per', 'con', 'non', 'che', 'sono', 'ho', 'mi', 'ciao', 'grazie',
        'del', 'della', 'el', 'los', 'las', 'en', 'por', 'para', 'no', 'yo', 'soy', 'hola', 'gracias', 'os',
        'as', 'um', 'uma', 'em', 'com', 'não', 'eu', 'sou', 'olá', 'obrigado', 'do',
        // nl
        'het', 'een', 'niet', 'ik', 'je', 'van', 'voor', 'met', 'op', 'dat', 'zijn',
        // pl, cs, sk
        'na', 'się', 'nie', 'że', 'to', 'jest', 'do', 'jak', 'ale', 'po', 'dla', 'jestem', 'mam', 'se',
        'je', 'jsem', 'mám', 'pro', 'není', 'ako', 'som', 'sa',
        // hr, sr, bs, sl
        'je', 'da', 'za', 'od', 'sam', 'su', 'ni', 'in', 'ne',
        // hu, ro, tr
        'az', 'és', 'egy', 'hogy', 'nem', 'van', 'is', 'meg', 'ez', 'și', 'în', 'la', 'cu', 'nu', 'este',
        'pentru', 'că', 've', 'bir', 'bu', 'için', 'ile', 'ne', 'çok', 'ben', 'var', 'merhaba', 'lütfen',
        'teşekkürler', 'ama', 'gibi', 'daha', 'yok',
        // fi, sv, da, no
        'ja', 'on', 'ei', 'että', 'olen', 'minä', 'och', 'att', 'är', 'det', 'ett', 'jag', 'inte', 'på',
        'og', 'er', 'ikke', 'til',
        // vi, sq
        'và', 'của', 'là', 'có', 'không', 'tôi', 'các', 'cho', 'được', 'với', 'này', 'những', 'một',
        'dhe', 'në', 'të', 'është', 'një',
    ];

    /**
     * @param (\Closure(string): bool)|null $mxLookup nur für Tests; Standard ist checkdnsrr()
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly AiSpamJudge $aiJudge,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly ?\Closure $mxLookup = null,
    ) {
    }

    /**
     * @param list<string> $texts  Freitext- und Namensfelder
     * @param list<string> $emails eingetragene Mailadressen
     *
     * @return array{spam: bool, score: int, reasons: list<string>}
     */
    public function classify(array $texts, array $emails, ?string $clientIp): array
    {
        $content = [];
        $address = [];
        $tor = [];
        $origin = [];

        $gibberish = \count(array_filter($texts, self::isGibberish(...)));

        if ($gibberish >= 2) {
            $content['gibberish-many'] = self::POINTS_GIBBERISH_MANY;
        } elseif (1 === $gibberish) {
            $content['gibberish-one'] = self::POINTS_GIBBERISH_ONE;
        }

        if ([] !== array_filter($texts, self::hasLink(...))) {
            $content['link'] = self::POINTS_LINK;
        }

        $net = null !== $clientIp ? self::network($clientIp) : null;

        if (null !== $net && $this->repeatedFromOtherNets($texts, $net)) {
            $content['repeat'] = self::POINTS_REPEAT;
        }

        if ([] !== array_filter($emails, self::isDottedAddress(...))) {
            $address['dotted-address'] = self::POINTS_DOTTED_ADDRESS;
        }

        if ([] !== array_filter($emails, fn (string $email): bool => !$this->domainHasMx($email))) {
            $address['no-mx'] = self::POINTS_NO_MX;
        }

        if (null !== $clientIp && $this->isTorExit($clientIp)) {
            $tor['tor-exit'] = self::POINTS_TOR;
        }

        if (null !== $net && $this->netBurst($net)) {
            $origin['net-burst'] = self::POINTS_NET_BURST;
        }

        $score = array_sum($content) + array_sum($address) + array_sum($tor) + array_sum($origin);
        $reasons = array_keys($content + $address + $tor + $origin);
        $groups = \count(array_filter([$content, $address, $tor]));

        // Mit Tor braucht es ein deutliches Zweitsignal (ab 3 Punkten): Tor plus nur Link oder fehlender MX träfe
        // im Menschen-Korpus 7 von 53 Einsendungen. Solche Fälle bleiben grau und gehen höchstens an die KI.
        $spam = $groups >= 2 && $score >= self::SURE && ([] === $tor || max([0, ...$content, ...$address]) >= 3);

        if (!$spam && $groups >= 2) {
            $verdict = $this->aiJudge->judge($texts, $emails, $reasons);

            if (null !== $verdict) {
                $reasons[] = $verdict['spam'] && $verdict['sure'] ? 'ai-spam' : 'ai-clean';
                $spam = $verdict['spam'] && $verdict['sure'];
            }
        }

        $this->log($spam, $score, $reasons);

        return ['spam' => $spam, 'score' => $score, 'reasons' => $reasons];
    }

    /**
     * Adressen, an die heute schon RECIPIENT_DAILY_LIMIT tokenlose Einsendungen gingen. Zählt diese Einsendung
     * mit. Grenze gegen Missbrauch des Formulars als Versender an fremde Postfächer.
     *
     * @param list<string> $emails
     *
     * @return list<string>
     */
    public function throttledRecipients(array $emails): array
    {
        $throttled = [];

        foreach (array_unique(array_map('strtolower', $emails)) as $email) {
            if ($this->increment('mandrael_turnstile.rcpt.'.hash('sha256', $email), self::REPEAT_TTL) > self::RECIPIENT_DAILY_LIMIT) {
                $throttled[] = $email;
            }
        }

        return $throttled;
    }

    /**
     * Zeichensalat: mindestens 20 Buchstaben und fünf Wörter in lateinischer Schrift, kein häufiges
     * Funktionswort UND das Muster eines Zufallsgenerators: entweder fast keine Vokale (gleichverteilte
     * Buchstaben) oder ein fast lückenloser Wechsel von Konsonant und Vokal (Silbenketten wie
     * „qexira vubot lomeza", die Adresse vom 22.09. „eriqasuxezic" hat 1,0). Gemessen am 23.09.2026:
     * Zufallssilben 0,96–1,0; Stichwortzeilen auf Deutsch, Türkisch, Finnisch, Ungarisch, Italienisch
     * 0,54–0,86. Andere Schriften werden nie gewertet.
     *
     * ponytail: Grenzen aus erfundenen Mustern; japanische Umschrift (0,94) würde treffen. Mit den echten
     * Spam-Texten nachkalibrieren.
     */
    public static function isGibberish(string $text): bool
    {
        if (preg_match('/(?!\p{Latin})\p{L}/u', $text)) {
            return false;
        }

        // Die Spams vom 22.09.2026 hatten je Feld ein einziges Wort aus 16–24 wahllos groß und klein
        // geschriebenen Buchstaben („XxnOWkPadJGBWZZlvN", „VPLfaUYFhOYYwjaj"): 38–59 % Großbuchstaben ab dem
        // zweiten Zeichen. Zusammengeschriebene Namen liegen darunter („McDonaldsGmbH" 25 %, „VanDerBergGmbH"
        // 31 %, „SoftwareEntwicklungGmbH" 14 %). Ein gleichverteiltes Zufallswort mit 16–24 Zeichen verfehlt die
        // Schwelle von einem Drittel in 6 % der Fälle (Messung 23.09.2026, 20.000 Wörter). Nur wenn das Wort das
        // ganze Feld ist: ein Code oder Name mitten in einem Satz bleibt unberührt.
        $word = trim($text);

        if (preg_match('/^\p{L}{16,}$/u', $word)) {
            $upper = preg_match_all('/\p{Lu}/u', mb_substr($word, 1));

            if ($upper >= 3 && $upper / (mb_strlen($word) - 1) >= 1 / 3 && preg_match_all('/\p{Ll}/u', $word) >= 3) {
                return true;
            }
        }

        if (preg_match_all('/\p{L}/u', $text) < 20) {
            return false;
        }

        $words = preg_split('/[^\p{L}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if (\count($words) < 5) {
            return false;
        }

        $words = array_map('mb_strtolower', $words);

        if ([] !== array_intersect($words, self::FUNCTION_WORDS)) {
            return false;
        }

        $isVowel = static fn (string $c): bool => (bool) preg_match('/[aeiouyäöüàáâãåæèéêëìíîïòóôõøùúûýœ]/u', $c);
        $vowels = 0;
        $letters = 0;
        $switches = 0;
        $pairs = 0;

        foreach ($words as $word) {
            $chars = mb_str_split($word);
            $letters += \count($chars);
            $vowels += \count(array_filter($chars, $isVowel));

            for ($i = 1, $n = \count($chars); $i < $n; ++$i) {
                ++$pairs;
                $switches += (int) ($isVowel($chars[$i]) !== $isVowel($chars[$i - 1]));
            }
        }

        return $vowels / $letters < 0.2 || ($pairs > 0 && $switches / $pairs >= 0.92);
    }

    /**
     * Muster aus der Tobar-Referenz (spamscore.php): Schema, www., Pfad nach Domain, Endungen, die im
     * deutschen Fließtext nicht zufällig entstehen, sowie HTML- und Forenauszeichnung.
     */
    public static function hasLink(string $text): bool
    {
        return (bool) preg_match('#https?://|(?<![\w.])www\.[a-z0-9-]|[a-z0-9][a-z0-9-]*\.[a-z]{2,12}/\S|[a-z0-9][a-z0-9-]*\.(com|net|org|info|biz|shop|xyz|top|click|link|ru)\b|</?[a-z][a-z0-9]*(\s[^>]*)?/?>|\[/?(url|link|img|a)\b[^\]]*\]#i', $text);
    }

    /**
     * Punktzerstückelter lokaler Teil wie „e.r.iqas.u.xez.i.c6.9" (7 Punkte): mindestens sechs Punkte und vier
     * Abschnitte aus einem einzigen Zeichen. „j.r.r.tolkien", „dr.j.r.r.t.smith" (5 Punkte) oder
     * „vorname.nachname" treffen nicht.
     */
    public static function isDottedAddress(string $email): bool
    {
        $local = strstr($email, '@', true);

        if (false === $local) {
            return false;
        }

        // Gmail ignoriert Punkte; die Spams vom 22.09.2026 nutzten dieselbe Adresse in drei Schreibweisen
        // (omam.o.m.u.b09.8, o.m.am.o.mub.09.8, e.r.iqas.u.xez.i.c6.9: 5–7 Punkte, 4–5 Einzelzeichen). Dort
        // genügen vier Punkte mit drei Einzelzeichen; „vor.mittel.nach.name.zwei" trifft nicht.
        $gmail = \in_array(strtolower((string) substr((string) strrchr($email, '@'), 1)), ['gmail.com', 'googlemail.com'], true);

        if (substr_count($local, '.') < ($gmail ? 4 : 6)) {
            return false;
        }

        return \count(array_filter(explode('.', $local), static fn (string $part): bool => 1 === \strlen($part))) >= ($gmail ? 3 : 4);
    }

    /**
     * IPv4 auf /24, IPv6 auf /48 gekürzt: eine Serie über wechselnde Adressen eines Netzes zählt zusammen.
     */
    public static function network(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if (false === $packed) {
            return null;
        }

        return bin2hex(substr($packed, 0, 4 === \strlen($packed) ? 3 : 6));
    }

    /**
     * Derselbe Text aus mindestens REPEAT_NETS verschiedenen Netzen in 24 h ist eine Serie. Wiederholtes
     * Absenden aus einem Netz (Doppelklick, Zurück-Taste) zählt nicht.
     *
     * @param list<string> $texts
     */
    private function repeatedFromOtherNets(array $texts, string $net): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', implode(' ', $texts))));

        if (mb_strlen($normalized) < 20) {
            return false;
        }

        try {
            $item = $this->cache->getItem('mandrael_turnstile.repeat.'.hash('sha256', $normalized));
            $nets = $item->isHit() && \is_array($item->get()) ? $item->get() : [];
            $nets[$net] = true;
            // ponytail: PSR-6 ist nicht atomar und je Knoten getrennt; parallele Einsendungen können einen
            // Eintrag verlieren. Für eine Serienerkennung genügt das, sie liegt nie allein über SURE.
            $this->cache->save($item->set(\array_slice($nets, -20, null, true))->expiresAfter(self::REPEAT_TTL));

            return \count($nets) >= self::REPEAT_NETS;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isTorExit(string $ip): bool
    {
        try {
            $item = $this->cache->getItem('mandrael_turnstile.tor_exits');
        } catch (\Throwable) {
            return false;
        }

        $list = $item->isHit() ? $item->get() : null;

        if (!\is_array($list)) {
            $list = [];

            try {
                $body = $this->httpClient->request('GET', self::TOR_LIST_URL, [
                    'timeout' => self::TOR_TIMEOUT,
                    'max_duration' => self::TOR_TIMEOUT,
                ])->getContent();

                foreach (preg_split('/\R/', $body) ?: [] as $line) {
                    if (false !== filter_var(trim($line), \FILTER_VALIDATE_IP)) {
                        $list[trim($line)] = true;
                    }
                }
            } catch (\Throwable) {
            }

            try {
                $this->cache->save($item->set($list)->expiresAfter([] === $list ? self::TOR_RETRY : self::TOR_TTL));
            } catch (\Throwable) {
            }
        }

        return isset($list[$ip]);
    }

    private function netBurst(string $net): bool
    {
        return $this->increment('mandrael_turnstile.net.'.$net, self::NET_BURST_TTL) > self::NET_BURST_LIMIT;
    }

    /**
     * Fester Zeitraum ab dem ersten Treffer. Cache-Fehler zählen als 0: lieber ein Signal verlieren als eine
     * Einsendung.
     */
    private function increment(string $key, int $ttl): int
    {
        try {
            $item = $this->cache->getItem($key);
            $count = ($item->isHit() && \is_array($item->get()) ? $item->get() : ['n' => 0, 'until' => time() + $ttl]);
            ++$count['n'];
            $this->cache->save($item->set($count)->expiresAfter(max(1, $count['until'] - time())));

            return $count['n'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Nur der MX-Eintrag zählt. Der abschließende Punkt verhindert, dass der Resolver eine Suchdomain
     * anhängt. Antwortet auch die Kontrollfrage nicht, liegt es am Netz: dann gilt die Domain als gültig.
     */
    private function domainHasMx(string $email): bool
    {
        $domain = strtolower(trim((string) substr((string) strrchr($email, '@'), 1), " .\t"));

        if ('' === $domain) {
            return true;
        }

        $lookup = $this->mxLookup ?? static fn (string $host): bool => checkdnsrr($host.'.', 'MX');

        try {
            $item = $this->cache->getItem('mandrael_turnstile.mx.'.hash('sha256', $domain));

            if ($item->isHit()) {
                return (bool) $item->get();
            }
        } catch (\Throwable) {
            $item = null;
        }

        // Warnungen des Resolvers (mit throw-Fehlerbehandlung eine Ausnahme) dürfen die Einsendung nie abbrechen.
        try {
            $valid = $lookup($domain) || !$lookup('gmail.com');
        } catch (\Throwable) {
            return true;
        }

        // Nur „gültig" merken: checkdnsrr() hat keine Zeitgrenze und meldet einen Timeout wie „kein MX"; ein
        // gecachter Fehlbefund würde die Domain sonst eine Stunde lang belasten.
        try {
            if (null !== $item && $valid) {
                $this->cache->save($item->set(true)->expiresAfter(self::MX_TTL));
            }
        } catch (\Throwable) {
        }

        return $valid;
    }

    /**
     * @param list<string> $reasons
     */
    private function log(bool $spam, int $score, array $reasons): void
    {
        $message = \sprintf(
            'Cloudflare Turnstile: Einsendung ohne Token eingestuft als %s (%d Punkte: %s).',
            $spam ? 'Spam, keine Rückmeldung an den Absender (fallback-spam)' : 'sauber (fallback-pass)',
            $score,
            [] === $reasons ? 'keine Signale' : implode(', ', $reasons)
        );

        try {
            $this->logger->info($message, ['contao' => new ContaoContext(__METHOD__, ContaoContext::FORMS)]);
        } catch (\Throwable) {
        }
    }
}

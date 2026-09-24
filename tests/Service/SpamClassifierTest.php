<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Mandrael\ContaoTurnstileBundle\Service\AiSpamJudge;
use Mandrael\ContaoTurnstileBundle\Service\SpamClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SpamClassifierTest extends TestCase
{
    // Zufallssilben-Muster (Vokalanteil/Wechselquote-Test), Werte laut Auftrag vom 23.09.2026 gemessen.
    private const GIBBERISH_SWITCH_A = 'Qexira vubot lomeza tirka pobela';
    private const GIBBERISH_SWITCH_B = 'Xumerat qobila venora tuzik palemo rivuta sokenam dulapi';
    private const GIBBERISH_NO_VOWELS = 'xkqzt wprmv bndlf ghjrt zqwxp';

    // -- isGibberish() -------------------------------------------------------------------------------

    public function testIsGibberishDetectsSwitchRatioPattern(): void
    {
        self::assertTrue(SpamClassifier::isGibberish(self::GIBBERISH_SWITCH_A));
        self::assertTrue(SpamClassifier::isGibberish(self::GIBBERISH_SWITCH_B));
    }

    public function testIsGibberishDetectsLowVowelRatioPattern(): void
    {
        self::assertTrue(SpamClassifier::isGibberish(self::GIBBERISH_NO_VOWELS));
    }

    public function testIsGibberishDoesNotMatchShortPolishName(): void
    {
        self::assertFalse(SpamClassifier::isGibberish('Krzysztof Szczepański'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRealSentences(): iterable
    {
        yield 'de' => ['Ich hätte gerne einen Termin nächste Woche bitte'];
        yield 'en' => ['I would like to book a course for next week please'];
        yield 'pl' => ['Chciałbym się zapisać na kurs w przyszłym tygodniu'];
        yield 'tr' => ['Kursa kayıt olmak için bilgi almak istiyorum lütfen'];
        yield 'hu' => ['Ez egy nagyon jó tanfolyam és köszönöm szépen'];
        yield 'fi' => ['Olen kiinnostunut kurssista ja haluaisin lisätietoja'];
        yield 'vi' => ['Tôi muốn đăng ký khóa học vào tuần tới'];
        yield 'cs' => ['Mám zájem o kurz příští týden, děkuji'];
        yield 'hr' => ['Ja sam zainteresiran za ovaj tečaj hvala'];
        yield 'ro' => ['Aș dori să mă înscriu la acest curs, mulțumesc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRealSentences')]
    public function testIsGibberishDoesNotMatchRealSentences(string $sentence): void
    {
        self::assertFalse(SpamClassifier::isGibberish($sentence));
    }

    /**
     * Pflicht-Regressionen vom 23.09.2026: reine Stichwortzeilen ohne Funktionswort, die den
     * Vokalanteil/Wechselquote-Test bestehen müssen (nicht nur die Funktionswort-Prüfung).
     *
     * @return iterable<string, array{string}>
     */
    public static function provideKeywordLinesWithoutFunctionWords(): iterable
    {
        yield 'de-anmeldung' => ['Kursanmeldung Kinesiologie Basis Wien Oktober dringend'];
        yield 'de-workshop' => ['Workshop Wochenende Präsenzzahlung Barrechnung Teilnehmerliste'];
        yield 'tr' => ['Merhaba kursunuza kaydolmak istiyorum lütfen'];
        yield 'fi' => ['Minulla kysymys kurssista lokakuussa Helsinki'];
        yield 'hu' => ['Tanulmányi kérdés tanfolyam október Budapest'];
        yield 'it' => ['Seminario domenica mattina Milano iscrizione'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideKeywordLinesWithoutFunctionWords')]
    public function testIsGibberishDoesNotMatchKeywordLinesWithoutFunctionWords(string $sentence): void
    {
        self::assertFalse(SpamClassifier::isGibberish($sentence));
    }

    public function testIsGibberishDoesNotMatchShortKeywordSubject(): void
    {
        self::assertFalse(SpamClassifier::isGibberish('Kursanmeldung Yoga'));
    }

    public function testIsGibberishNeverMatchesNonLatinScript(): void
    {
        self::assertFalse(SpamClassifier::isGibberish('Здравствуйте, интересует запись на курс, подскажите пожалуйста'));
        self::assertFalse(SpamClassifier::isGibberish('Γειά σας, ενδιαφέρομαι για το μάθημα παρακαλώ'));
    }

    public function testIsGibberishDoesNotMatchShortName(): void
    {
        self::assertFalse(SpamClassifier::isGibberish('Anna Müller'));
    }

    public function testIsGibberishJapaneseTranscriptionEdgeCaseDocumented(): void
    {
        // Dokumentierter Grenzfall (kein Härtungsauftrag, siehe Docblock von isGibberish()): eine
        // Kette echter japanischer Nachnamen in lateinischer Umschrift erfüllt die Wechselquote (0,94)
        // und gilt als Zeichensalat. Nachkalibrierung erst mit echtem Spam-Korpus.
        self::assertTrue(SpamClassifier::isGibberish('Takahashi Yamamoto Nakamura Suzuki Watanabe'));
    }

    /**
     * Echte Spam-Wörter vom 22.09.2026: ein einzelnes Token mit wahlloser Groß-/Kleinschreibung, unabhängig
     * von Wortanzahl oder 20-Zeichen-Grenze des restlichen Textes.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideRealSpamTokens(): iterable
    {
        yield 'a' => ['XxnOWkPadJGBWZZlvN'];
        yield 'b' => ['VPLfaUYFhOYYwjaj'];
        yield 'c' => ['TQEQbPbuEPUBeBEgzV'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRealSpamTokens')]
    public function testIsGibberishDetectsRealSpamTokenPattern(string $token): void
    {
        self::assertTrue(SpamClassifier::isGibberish($token));
        self::assertTrue(SpamClassifier::isGibberish(" $token\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonMatchingMixedCaseWords(): iterable
    {
        yield 'mcdonald' => ['McDonald'];
        yield 'wordpress' => ['WordPress'];
        yield 'powerpoint' => ['PowerPoint'];
        yield 'youtube' => ['YouTube'];
        yield 'code-ohne-kleinbuchstaben' => ['ABCD1234XY'];
        yield 'mcdonaldsgmbh' => ['McDonaldsGmbH'];
        yield 'softwareentwicklunggmbh' => ['SoftwareEntwicklungGmbH'];
        yield 'wordpressgmbh' => ['WordPressGmbH'];
        yield 'vanderberggmbh' => ['VanDerBergGmbH'];
        yield 'delacruzmartinez' => ['DeLaCruzMartinez'];
        yield 'iphonexsmax' => ['iPhoneXSMax'];
        yield 'kurz unter 16 Zeichen' => ['KqWbTzeHuRNmoPL'];
        yield 'mitten im Satz' => ['Mein Gutscheincode lautet KqWbTzeHuRNmoPLxa, bitte einlösen.'];
        yield 'zwei Wörter im Feld' => ['KqWbTzeHuRNmoPLxa VbnRTzuQaWeLkPoiu'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideNonMatchingMixedCaseWords')]
    public function testIsGibberishDoesNotMatchRealWordsOrCodes(string $word): void
    {
        self::assertFalse(SpamClassifier::isGibberish($word));
    }

    // -- isDottedAddress() ----------------------------------------------------------------------------

    public function testIsDottedAddressMatchesFragmentedLocalPart(): void
    {
        self::assertTrue(SpamClassifier::isDottedAddress('e.r.iqas.u.xez.i.c6.9@gmail.com'));
    }

    public function testIsDottedAddressDoesNotMatchRealName(): void
    {
        self::assertFalse(SpamClassifier::isDottedAddress('j.r.r.tolkien@example.org'));
    }

    public function testIsDottedAddressDoesNotMatchFirstnameDotLastname(): void
    {
        self::assertFalse(SpamClassifier::isDottedAddress('vorname.nachname@gmail.com'));
    }

    public function testIsDottedAddressDoesNotMatchThreeDots(): void
    {
        self::assertFalse(SpamClassifier::isDottedAddress('a.b.c.d@x.at'));
    }

    public function testIsDottedAddressDoesNotMatchFiveDots(): void
    {
        // dr.j.r.r.t.smith: 5 Punkte, unter der seit dem Review geltenden Grenze von 6.
        self::assertFalse(SpamClassifier::isDottedAddress('dr.j.r.r.t.smith@example.org'));
    }

    public function testIsDottedAddressNeedsFourSingleCharSegmentsNotThree(): void
    {
        // Beide Adressen haben 6 Punkte (7 Abschnitte); nur die zweite hat vier Einzelzeichen-Abschnitte.
        self::assertFalse(SpamClassifier::isDottedAddress('a.b.c.defgh.ij.kl.mn@example.com'));
        self::assertTrue(SpamClassifier::isDottedAddress('a.b.c.d.ij.kl.mn@example.com'));
    }

    /**
     * Gmail/Googlemail ignorieren Punkte im lokalen Teil: dort genügen vier Punkte mit drei Einzelzeichen-
     * Abschnitten statt sechs mit vier. Fälle vom 22.09.2026, dieselbe Adresse in drei Schreibweisen.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function provideDottedAddressTable(): iterable
    {
        yield 'gmail 5 Punkte' => ['omam.o.m.u.b09.8@gmail.com', true];
        yield 'gmail 6 Punkte' => ['o.m.am.o.mub.09.8@gmail.com', true];
        yield 'gmail 7 Punkte (22.09.)' => ['e.r.iqas.u.xez.i.c6.9@gmail.com', true];
        yield 'gmail 1 Punkt' => ['max.mustermann@gmail.com', false];
        yield 'gmail 2 Punkte' => ['a.b.mustermann@gmail.com', false];
        yield 'gmail 3 Punkte, 3 Einzelzeichen' => ['a.b.c.mustermann@gmail.com', false];
        yield 'gmail 4 Punkte, echte Namensteile' => ['vor.mittel.nach.name.zwei@gmail.com', false];
        yield 'gmail 4 Punkte, 2 Einzelzeichen' => ['j.r.tolkien.fan.club@gmail.com', false];
        yield 'gmail 4 Punkte, 3 Einzelzeichen' => ['q.w.er.t.zu@gmail.com', true];
        yield 'googlemail 4 Punkte, 3 Einzelzeichen' => ['q.w.er.t.zu@googlemail.com', true];
        yield 'andere Domain 4 Punkte, 3 Einzelzeichen' => ['q.w.er.t.zu@example.org', false];
        yield 'nicht-gmail 5 Punkte, 4 Einzelzeichen' => ['dr.j.r.r.t.smith@example.org', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideDottedAddressTable')]
    public function testIsDottedAddressTable(string $email, bool $expected): void
    {
        self::assertSame($expected, SpamClassifier::isDottedAddress($email));
    }

    // -- hasLink() --------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLinkTexts(): iterable
    {
        yield 'http' => ['Besuchen Sie https://example.com für mehr Informationen'];
        yield 'www' => ['Besuch www.example.com heute noch'];
        yield 'domain-pfad' => ['Siehe domain.at/kurse für Details'];
        yield 'com-endung' => ['besuchen sie unser-shop.com jetzt'];
        yield 'html-tag' => ['<b>Hallo</b> und herzlich willkommen'];
        yield 'bbcode' => ['Schau mal [url]meine-seite[/url] an'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideLinkTexts')]
    public function testHasLinkDetectsLinks(string $text): void
    {
        self::assertTrue(SpamClassifier::hasLink($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonLinkTexts(): iterable
    {
        yield 'preis' => ['Kosten 33,70 €'];
        yield 'kleiner-als' => ['ich bin <30 Jahre'];
        yield 'abkuerzung-datum' => ['z.B. am 3.10.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideNonLinkTexts')]
    public function testHasLinkIgnoresNonLinks(string $text): void
    {
        self::assertFalse(SpamClassifier::hasLink($text));
    }

    // -- network() --------------------------------------------------------------------------------------

    public function testNetworkGroupsIpv4BySlash24(): void
    {
        self::assertSame(SpamClassifier::network('1.2.3.4'), SpamClassifier::network('1.2.3.200'));
        self::assertNotSame(SpamClassifier::network('1.2.3.4'), SpamClassifier::network('1.2.4.1'));
    }

    public function testNetworkGroupsIpv6BySlash48(): void
    {
        self::assertSame(
            SpamClassifier::network('2001:db8:1234::1'),
            SpamClassifier::network('2001:db8:1234:ffff::2')
        );
        self::assertNotSame(
            SpamClassifier::network('2001:db8:1234::1'),
            SpamClassifier::network('2001:db8:1235::1')
        );
    }

    public function testNetworkReturnsNullForInvalidIp(): void
    {
        self::assertNull(SpamClassifier::network('not-an-ip'));
    }

    // -- classify() -------------------------------------------------------------------------------------

    public function testClassifyContentSignalAloneIsNotSpamAndSkipsAi(): void
    {
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge);

        $result = $classifier->classify(
            [self::GIBBERISH_SWITCH_A, self::GIBBERISH_SWITCH_B],
            ['vorname.nachname@gmail.com'],
            null
        );

        self::assertFalse($result['spam']);
    }

    public function testClassifyAddressSignalAloneIsNotSpamAndSkipsAi(): void
    {
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge);

        $result = $classifier->classify(
            ['Vielen Dank für die schnelle Antwort, bis bald'],
            ['e.r.iqas.u.xez.i.c6.9@gmail.com'],
            null
        );

        self::assertFalse($result['spam']);
        self::assertContains('dotted-address', $result['reasons']);
    }

    public function testClassifyBothGroupsSureIsSpamWithoutAi(): void
    {
        // Fall 22.09.2026: Zeichensalat >= 2 Felder (4 Punkte) + Punktadresse (3 Punkte) = 7 -> sicher.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge);

        $result = $classifier->classify(
            [self::GIBBERISH_SWITCH_A, self::GIBBERISH_SWITCH_B],
            ['e.r.iqas.u.xez.i.c6.9@gmail.com'],
            null
        );

        self::assertTrue($result['spam']);
        self::assertSame(7, $result['score']);
    }

    public function testClassifyBelowSureAsksAiAndSpamNeedsSpamAndSure(): void
    {
        // Link (2) + MX fehlt (2) = 4, beide Gruppen (Inhalt/Adresse) vertreten, aber unter SURE -> KI wird gefragt.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::once())->method('judge')->willReturn(['spam' => true, 'sure' => true]);

        $classifier = $this->createClassifier($aiJudge, mxLookup: self::mxLookupWithGmailControlNegative());

        $result = $classifier->classify(
            ['Besuchen Sie unser-shop.com für mehr Informationen'],
            ['kontakt@keine-mx-domain.example'],
            null
        );

        self::assertSame(4, $result['score']);
        self::assertTrue($result['spam']);
        self::assertContains('ai-spam', $result['reasons']);
    }

    public function testClassifyAiSpamWithoutSureIsNotSpam(): void
    {
        $aiJudge = $this->createStub(AiSpamJudge::class);
        $aiJudge->method('judge')->willReturn(['spam' => true, 'sure' => false]);

        $classifier = $this->createClassifier($aiJudge, mxLookup: self::mxLookupWithGmailControlNegative());

        $result = $classifier->classify(
            ['Besuchen Sie unser-shop.com für mehr Informationen'],
            ['kontakt@keine-mx-domain.example'],
            null
        );

        self::assertFalse($result['spam']);
        self::assertContains('ai-clean', $result['reasons']);
    }

    public function testClassifyAiNullVerdictIsNotSpam(): void
    {
        $aiJudge = $this->createStub(AiSpamJudge::class);
        $aiJudge->method('judge')->willReturn(null);

        $classifier = $this->createClassifier($aiJudge, mxLookup: self::mxLookupWithGmailControlNegative());

        $result = $classifier->classify(
            ['Besuchen Sie unser-shop.com für mehr Informationen'],
            ['kontakt@keine-mx-domain.example'],
            null
        );

        self::assertFalse($result['spam']);
        self::assertNotContains('ai-spam', $result['reasons']);
        self::assertNotContains('ai-clean', $result['reasons']);
    }

    public function testClassifyGibberishOneAloneScoresExactlyTwo(): void
    {
        $classifier = $this->createClassifier();

        $result = $classifier->classify(
            [self::GIBBERISH_SWITCH_A, 'Ich hätte gerne einen Termin bitte'],
            [],
            null
        );

        self::assertSame(2, $result['score']);
        self::assertContains('gibberish-one', $result['reasons']);
        self::assertFalse($result['spam']);
    }

    public function testClassifyNetBurstAloneWithContentIsNeverSpamAndSkipsAi(): void
    {
        // Netz-Andrang zaehlt nur Punkte, erfuellt nie die Adress-/Herkunftsgruppe (dsh-Review): ohne
        // Adresssignal bleibt bothGroups falsch, egal wie hoch die Inhalts- und Origin-Punkte sind.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge);

        for ($i = 1; $i <= 5; ++$i) {
            $classifier->classify(
                [self::GIBBERISH_SWITCH_A, self::GIBBERISH_SWITCH_B],
                [],
                '198.51.100.9'
            );
        }

        $sixth = $classifier->classify(
            [self::GIBBERISH_SWITCH_A, self::GIBBERISH_SWITCH_B],
            [],
            '198.51.100.9'
        );

        self::assertContains('net-burst', $sixth['reasons']);
        self::assertFalse($sixth['spam']);
    }

    public function testClassifyLinkAndNoMxAndSingleGibberishStaysNotSpamWithoutAi(): void
    {
        // Regression aus dem dsh-Review (a): Link (2) + no-mx (2) + Zeichensalat in genau einem Feld (2) = 6,
        // bleibt unter SURE; ohne konfigurierte KI (Stub liefert null) nicht spam.
        $classifier = $this->createClassifier(mxLookup: self::mxLookupWithGmailControlNegative());

        $result = $classifier->classify(
            [self::GIBBERISH_SWITCH_A, 'Besuchen Sie unser-shop.com für mehr Informationen'],
            ['kontakt@keine-mx-domain.example'],
            null
        );

        self::assertSame(6, $result['score']);
        self::assertFalse($result['spam']);
        self::assertNotContains('ai-spam', $result['reasons']);
    }

    public function testClassifyLinkAndNetBurstAndRealNameAddressStaysNotSpam(): void
    {
        // Regression (b): Link + net-burst, aber eine echte Namensadresse (j.r.r.tolkien) ohne Adresssignal
        // -> Adressgruppe leer, bothGroups falsch, KI wird nicht gefragt.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge);

        for ($i = 1; $i <= 5; ++$i) {
            $classifier->classify(['Besuchen Sie unser-shop.com für mehr Informationen'], ['j.r.r.tolkien@example.org'], '198.51.100.11');
        }

        $sixth = $classifier->classify(['Besuchen Sie unser-shop.com für mehr Informationen'], ['j.r.r.tolkien@example.org'], '198.51.100.11');

        self::assertContains('net-burst', $sixth['reasons']);
        self::assertFalse($sixth['spam']);
    }

    public function testClassifyRepeatFromSingleNetWithNormalAddressStaysNotSpam(): void
    {
        // Regression (c): dreimal derselbe Text aus EINEM Netz (kein repeat-Signal) plus unauffällige
        // Adresse -> beide Gruppen leer, nie spam, KI nicht gefragt.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $cache = new ArrayAdapter();
        $text = 'Ich interessiere mich für den Kurs am kommenden Wochenende';
        $classifier = $this->createClassifier($aiJudge, $cache);

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $result = $classifier->classify([$text], ['jemand@gmail.com'], $ip);
            self::assertNotContains('repeat', $result['reasons']);
            self::assertFalse($result['spam']);
        }
    }

    public function testClassifyMxLookupThrowingCountsAsValidWithoutException(): void
    {
        $classifier = $this->createClassifier(mxLookup: static function (string $host): bool {
            throw new \RuntimeException('DNS-Resolver nicht erreichbar');
        });

        $result = $classifier->classify([], ['kontakt@broken-resolver.example'], null);

        self::assertNotContains('no-mx', $result['reasons']);
    }

    public function testClassifyMxLookupCountsAsValidWhenControlQuestionAlsoFails(): void
    {
        // checkdnsrr-Ausfall (auch die Kontrollfrage gmail.com liefert kein MX) -> Domain gilt als gültig,
        // kein no-mx-Signal.
        $classifier = $this->createClassifier(mxLookup: static fn (string $host): bool => false);

        $result = $classifier->classify([], ['kontakt@broken-dns.example'], null);

        self::assertNotContains('no-mx', $result['reasons']);
    }

    public function testClassifyMxNegativeResultIsNeverCachedLookupRunsAgain(): void
    {
        // checkdnsrr() hat keine Zeitgrenze; ein gecachter Fehlbefund würde eine Domain eine Stunde lang
        // zu Unrecht belasten. Kontrollfrage (gmail.com) positiv -> echter no-mx-Befund, aber ungecacht.
        $calls = 0;
        $cache = new ArrayAdapter();
        $mxLookup = static function (string $host) use (&$calls): bool {
            ++$calls;

            return 'gmail.com' === $host;
        };

        $classifier = $this->createClassifier(cache: $cache, mxLookup: $mxLookup);

        $first = $classifier->classify([], ['kontakt@keine-mx-domain.example'], null);
        self::assertContains('no-mx', $first['reasons']);
        self::assertSame(2, $calls);

        $second = $classifier->classify([], ['kontakt@keine-mx-domain.example'], null);
        self::assertContains('no-mx', $second['reasons']);
        self::assertSame(4, $calls);
    }

    public function testClassifyMxPositiveResultIsCachedLookupRunsOnce(): void
    {
        $calls = 0;
        $cache = new ArrayAdapter();
        $mxLookup = static function (string $host) use (&$calls): bool {
            ++$calls;

            return true;
        };

        $classifier = $this->createClassifier(cache: $cache, mxLookup: $mxLookup);

        $first = $classifier->classify([], ['kontakt@example.at'], null);
        self::assertNotContains('no-mx', $first['reasons']);
        self::assertSame(1, $calls);

        $second = $classifier->classify([], ['kontakt@example.at'], null);
        self::assertNotContains('no-mx', $second['reasons']);
        self::assertSame(1, $calls);
    }

    public function testClassifyKeywordLinesWithoutFunctionWordsPlusShortDottedAddressIsNotSpam(): void
    {
        // Regression (Grok-Review): reine Stichwortzeilen sind seit der Vokalanteil/Wechselquote-Prüfung
        // kein Zeichensalat mehr, und die 5-Punkte-Adresse liegt unter der neuen 6-Punkte-Grenze ->
        // keine Inhaltsgruppe, KI wird nicht gefragt.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge);

        $result = $classifier->classify(
            ['Kursanmeldung Kinesiologie Basis Wien Oktober dringend', 'Workshop Wochenende Präsenzzahlung Barrechnung Teilnehmerliste'],
            ['dr.j.r.r.t.smith@example.org'],
            null
        );

        self::assertFalse($result['spam']);
        self::assertSame([], $result['reasons']);
    }

    // -- Tor --------------------------------------------------------------------------------------------

    public function testClassifyTorPlusWeakSignalStaysGreyAndAsksAi(): void
    {
        // Tor (5) + Link (2) = 7 und zwei Gruppen, aber kein Zweitsignal ab 3 Punkten: grau, die KI entscheidet
        // (Michael 23.09.2026). Ohne KI-Urteil normale Verarbeitung.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::once())->method('judge')->willReturn(null);

        $classifier = $this->createClassifier($aiJudge, httpClient: self::torListClient(['198.51.100.200']));

        $result = $classifier->classify(
            ['Besuchen Sie unser-shop.com für mehr Informationen'],
            [],
            '198.51.100.200'
        );

        self::assertFalse($result['spam']);
        self::assertSame(7, $result['score']);
        self::assertContains('tor-exit', $result['reasons']);
    }

    public function testClassifyTorPlusStrongSignalIsSpamWithoutAi(): void
    {
        // Tor (5) + punktzerstückelte Adresse (3) = 8: deutliches Zweitsignal, sicher ohne KI.
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge, httpClient: self::torListClient(['198.51.100.200']));

        $result = $classifier->classify(['Anmeldung'], ['q.w.er.t.zu@gmail.com'], '198.51.100.200');

        self::assertTrue($result['spam']);
        self::assertSame(8, $result['score']);
    }

    public function testClassifyTorPlusTwoWeakSignalsStaysGrey(): void
    {
        // Tor (5) + Link (2) + kein MX (2) = 9 und drei Gruppen, aber kein Einzelsignal ab 3 Punkten.
        $classifier = $this->createClassifier(
            mxLookup: static fn (string $host): bool => 'nomx.example' !== $host,
            httpClient: self::torListClient(['198.51.100.200']),
        );

        $result = $classifier->classify(['Details unter https://bauer-it.example/schulung'], ['karl@nomx.example'], '198.51.100.200');

        self::assertSame(9, $result['score']);
        self::assertFalse($result['spam']);
    }

    public function testClassifyTorAloneNeverAsksAi(): void
    {
        // Tor allein ist nur eine Gruppe: Die KI darf keine fehlende Gruppe ersetzen (Gutachten 23.09.2026).
        $aiJudge = $this->createMock(AiSpamJudge::class);
        $aiJudge->expects(self::never())->method('judge');

        $classifier = $this->createClassifier($aiJudge, httpClient: self::torListClient(['198.51.100.201']));

        $result = $classifier->classify([], [], '198.51.100.201');

        self::assertSame(5, $result['score']);
        self::assertFalse($result['spam']);
    }

    public function testClassifyTorListUnreachableYieldsNoSignalAndNoException(): void
    {
        $classifier = $this->createClassifier(
            httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]))
        );

        $result = $classifier->classify([], [], '198.51.100.202');

        self::assertNotContains('tor-exit', $result['reasons']);
        self::assertFalse($result['spam']);
    }

    public function testClassifyTorListIsLoadedOnlyOncePerCacheTtl(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse("198.51.100.203\n");
        });

        $cache = new ArrayAdapter();
        $classifier = $this->createClassifier(cache: $cache, httpClient: $client);

        $first = $classifier->classify([], [], '198.51.100.203');
        self::assertContains('tor-exit', $first['reasons']);
        self::assertSame(1, $calls);

        $second = $classifier->classify([], [], '198.51.100.203');
        self::assertContains('tor-exit', $second['reasons']);
        self::assertSame(1, $calls);
    }

    /**
     * @param list<string> $ips
     */
    private static function torListClient(array $ips): HttpClientInterface
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse(implode("\n", $ips)));
    }

    // -- Regression 22.09.2026 (echte Spam-Muster, volle classify()-Aufrufe) ----------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRealSpamPatterns(): iterable
    {
        yield 'a' => ['XxnOWkPadJGBWZZlvN', 'omam.o.m.u.b09.8@gmail.com'];
        yield 'b' => ['VPLfaUYFhOYYwjaj', 'o.m.am.o.mub.09.8@gmail.com'];
        yield 'c' => ['TQEQbPbuEPUBeBEgzV', 'e.r.iqas.u.xez.i.c6.9@gmail.com'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRealSpamPatterns')]
    public function testClassifyRealSpamPatternFromSeptember22IsSpamWithTorGroup(string $name, string $email): void
    {
        $classifier = $this->createClassifier(httpClient: self::torListClient(['198.51.100.204']));

        $result = $classifier->classify([$name], [$email], '198.51.100.204');

        self::assertTrue($result['spam']);
        self::assertContains('tor-exit', $result['reasons']);
    }

    public function testClassifyRepeatSignalOnlyAfterThreeDifferentNets(): void
    {
        $cache = new ArrayAdapter();
        $text = 'Ich interessiere mich für den Kurs am kommenden Wochenende';

        $first = $this->createClassifier(cache: $cache)->classify([$text], [], '10.0.0.1');
        self::assertNotContains('repeat', $first['reasons']);

        $second = $this->createClassifier(cache: $cache)->classify([$text], [], '10.0.1.1');
        self::assertNotContains('repeat', $second['reasons']);

        $third = $this->createClassifier(cache: $cache)->classify([$text], [], '10.0.2.1');
        self::assertContains('repeat', $third['reasons']);
    }

    public function testClassifySingleGroupAboveSureIsNotSpam(): void
    {
        // Nur Inhalt: gibberish-many (4) + link (2) + repeat (4) = 10 Punkte, aber eine Gruppe.
        $cache = new ArrayAdapter();
        $texts = ['XxnOWkPadJGBWZZlvN', 'VPLfaUYFhOYYwjaj www.example.com'];

        foreach (['10.0.0.1', '10.0.1.1', '10.0.2.1'] as $ip) {
            $result = $this->createClassifier(cache: $cache)->classify($texts, ['anna.huber@gmail.com'], $ip);
        }

        self::assertGreaterThanOrEqual(SpamClassifier::SURE, $result['score']);
        self::assertFalse($result['spam']);
    }

    public function testClassifyRepeatWithRealFourDotGmailAddressStaysNotSpam(): void
    {
        // Mensch sendet dieselbe Anfrage aus drei Netzen erneut, Adresse mit vier Punkten aus Namensteilen.
        $cache = new ArrayAdapter();
        $text = 'Ich interessiere mich für den Kurs am kommenden Wochenende';

        foreach (['10.0.0.1', '10.0.1.1', '10.0.2.1'] as $ip) {
            $result = $this->createClassifier(cache: $cache)->classify([$text], ['vor.mittel.nach.name.zwei@gmail.com'], $ip);
        }

        self::assertContains('repeat', $result['reasons']);
        self::assertFalse($result['spam']);
    }

    public function testClassifyRepeatSignalNotTriggeredFromSameNet(): void
    {
        $cache = new ArrayAdapter();
        $text = 'Ich interessiere mich für den Kurs am kommenden Wochenende';

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $result = $this->createClassifier(cache: $cache)->classify([$text], [], $ip);
            self::assertNotContains('repeat', $result['reasons']);
        }
    }

    public function testClassifyNetBurstOnSixthSubmissionFromSameNet(): void
    {
        $cache = new ArrayAdapter();
        $classifier = $this->createClassifier(cache: $cache);

        for ($i = 1; $i <= 5; ++$i) {
            $result = $classifier->classify([], [], '198.51.100.7');
            self::assertNotContains('net-burst', $result['reasons']);
        }

        $sixth = $classifier->classify([], [], '198.51.100.7');
        self::assertContains('net-burst', $sixth['reasons']);
    }

    public function testClassifyRunsWithoutExceptionWhenCacheThrows(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Cache nicht erreichbar'));
        $cache->method('save')->willThrowException(new \RuntimeException('Cache nicht erreichbar'));

        $classifier = $this->createClassifier(cache: $cache);

        $result = $classifier->classify(['Ich hätte gerne einen Termin bitte'], ['jemand@example.com'], '203.0.113.9');

        self::assertArrayHasKey('spam', $result);
    }

    // -- throttledRecipients() --------------------------------------------------------------------------

    public function testThrottledRecipientsOnlyFromFourthSubmissionOnwardsCaseInsensitive(): void
    {
        $classifier = $this->createClassifier();

        self::assertSame([], $classifier->throttledRecipients(['user@example.com']));
        self::assertSame([], $classifier->throttledRecipients(['user@example.com']));
        self::assertSame([], $classifier->throttledRecipients(['user@example.com']));
        self::assertSame(['user@example.com'], $classifier->throttledRecipients(['USER@EXAMPLE.COM']));
    }

    /**
     * Domain ohne MX, Kontrollfrage (gmail.com) meldet dagegen MX vorhanden -> echter Ausfall statt
     * DNS-weiter Störung, no-mx-Signal greift.
     */
    private static function mxLookupWithGmailControlNegative(): \Closure
    {
        return static fn (string $host): bool => 'gmail.com' === $host;
    }

    private function createClassifier(
        ?AiSpamJudge $aiJudge = null,
        ?CacheItemPoolInterface $cache = null,
        ?\Closure $mxLookup = null,
        ?HttpClientInterface $httpClient = null,
    ): SpamClassifier {
        return new SpamClassifier(
            $cache ?? new ArrayAdapter(),
            $aiJudge ?? $this->createStub(AiSpamJudge::class),
            new NullLogger(),
            // Standard: leere Tor-Liste (kein Treffer), kein Testfall in dieser Datei braucht sonst HTTP.
            $httpClient ?? new MockHttpClient(static fn (): MockResponse => new MockResponse('')),
            $mxLookup ?? static fn (string $host): bool => true,
        );
    }
}

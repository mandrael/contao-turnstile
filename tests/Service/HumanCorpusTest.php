<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Service;

use Mandrael\ContaoTurnstileBundle\Service\AiSpamJudge;
use Mandrael\ContaoTurnstileBundle\Service\SpamClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Freigabekriterium aus dem Plan: realistische Einsendungen von Menschen dürfen ohne KI nie, auch über Tor nicht,
 * „Spam sicher" ergeben. Jeder Fall ist absichtlich unbequem (Stichwortzeilen, Fremdsprachen, Links, Initialen,
 * Firmen ohne MX, zusammengeschriebene Namen, Codes). Adressen auf nomx.example haben keinen MX-Eintrag.
 */
class HumanCorpusTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideHumanSubmissions(): iterable
    {
        yield 'de Kursanmeldung' => [['Anna Huber', 'Anmeldung Grundkurs', 'Ich möchte mich für den Grundkurs im Oktober anmelden.'], 'anna.huber@gmail.com'];
        yield 'de Stichworte' => [['Max Gruber', 'Rückfrage Termin', 'Termin Oktober Kursort Wien Parkplatz'], 'm.gruber@gmx.at'];
        yield 'de nur Name' => [['Leitner', 'Anmeldung', 'Anmeldung'], 'leitner@aon.at'];
        yield 'de Link zur Firma' => [['Petra Wallner', 'Kooperation', 'Wir sind eine Praxis in Graz, siehe www.praxis-wallner.at – gerne Kontakt.'], 'office@praxis-wallner.at'];
        yield 'de Firma ohne MX mit Link' => [['Karl Bauer', 'Inhouse-Schulung', 'Details unter https://bauer-it.example/schulung'], 'karl@nomx.example'];
        yield 'de CamelCase-Firma' => [['SoftwareEntwicklungGmbH', 'Anfrage Firmenkurs', 'Wir hätten Interesse an einem Kurs für 8 Personen.'], 'info@softwareentwicklung.at'];
        yield 'de Gutscheincode im Satz' => [['Eva Moser', 'Gutschein', 'Mein Gutscheincode lautet KqWbTzeHuRNmoPLxa, bitte einlösen.'], 'eva.moser@gmail.com'];
        yield 'de Initialen-Adresse gmail' => [['J. R. R. Tolkien', 'Anmeldung', 'Bitte um Anmeldung für den Aufbaukurs.'], 'j.r.r.tolkien@gmail.com'];
        yield 'de vier Punkte Namensteile' => [['Maria Theresia von Habsburg', 'Frage', 'Gibt es noch freie Plätze im November?'], 'maria.theresia.von.habs.burg@gmail.com'];
        yield 'de Rechnungsadresse' => [['Dr. Susanne Fink', 'Rechnung', 'Bitte Rechnung an: Fink KG, Hauptstraße 12, 8010 Graz, UID ATU12345678'], 'fink@fink-kg.at'];
        yield 'de Telefonnummer' => [['Hannes Pichler', 'Rückruf', '+43 664 1234567, erreichbar ab 17 Uhr'], 'h.pichler@a1.net'];
        yield 'de Tippfehler' => [['Sabine Kofler', 'anmledung', 'hallo ich wil mich anmleden fur den kurs danke'], 'sabine.kofler@yahoo.de'];
        yield 'de nur Kleinbuchstaben kurz' => [['tom', 'kurs', 'wann'], 'tom@t-online.de'];
        yield 'de Aufzählung' => [['Institut Sonnenhof', 'Gruppenanmeldung', "1. Lisa Berger\n2. Jonas Wolf\n3. Mia Huber\n4. Paul Stein"], 'verwaltung@sonnenhof.at'];
        yield 'de Wiederholung per Doppelklick' => [['Anna Huber', 'Anmeldung Grundkurs', 'Ich möchte mich für den Grundkurs im Oktober anmelden.'], 'anna.huber@gmail.com'];
        yield 'de Signatur mit Links' => [['Martin Egger', 'Frage zum Skript', "Gibt es das Skript als PDF?\n--\nMartin Egger\nwww.egger-coaching.at\nhttps://linkedin.com/in/martinegger"], 'martin@egger-coaching.at'];
        yield 'de Produktnamen' => [['Lukas Brandl', 'Technik', 'Mein iPhoneXSMax zeigt das Formular nicht richtig an, MacBookPro geht.'], 'lukas.brandl@icloud.com'];
        yield 'de Abkürzungen' => [['OA Dr. med. univ. K. Reiter', 'Fortbildung', 'DFP-Punkte? ÖÄK-Anrechnung? LG KR'], 'k.reiter@kages.at'];
        yield 'en course booking' => [['John Smith', 'Booking', 'I would like to book a place in the October course, thank you.'], 'john.smith@outlook.com'];
        yield 'en keywords' => [['Emily Clarke', 'Question', 'Price discount students Vienna'], 'emily.clarke@proton.me'];
        yield 'en link' => [['Mark Lee', 'Partnership', 'Please see our program at https://example.org/program'], 'mark@example.org'];
        yield 'en dotted initials' => [['A. B. C. Johnson', 'Registration', 'Registering for the workshop.'], 'a.b.c.johnson@gmail.com'];
        yield 'fr' => [['Claire Dubois', 'Inscription', "Je voudrais m'inscrire au cours de novembre, merci."], 'claire.dubois@orange.fr'];
        yield 'it' => [['Giulia Rossi', 'Iscrizione', 'Vorrei iscrivermi al corso di ottobre, grazie mille.'], 'giulia.rossi@libero.it'];
        yield 'it Stichworte' => [['Marco Bianchi', 'Domanda', 'Corso Vienna ottobre prezzo sconto'], 'marco.bianchi@virgilio.it'];
        yield 'es' => [['Lucía García', 'Inscripción', 'Quisiera inscribirme en el curso de octubre, gracias.'], 'lucia.garcia@gmail.com'];
        yield 'pt' => [['João Silva', 'Inscrição', 'Gostaria de me inscrever no curso, obrigado.'], 'joao.silva@sapo.pt'];
        yield 'nl' => [['Pieter de Vries', 'Aanmelding', 'Ik wil mij graag aanmelden voor de cursus in oktober.'], 'pieter.devries@ziggo.nl'];
        yield 'pl' => [['Katarzyna Wójcik', 'Zapis', 'Chciałabym zapisać się na kurs w październiku, dziękuję.'], 'k.wojcik@wp.pl'];
        yield 'pl Name kurz' => [['Grzegorz Brzęczyszczykiewicz', 'Kurs', 'Proszę o informację.'], 'g.brzeczyszczykiewicz@onet.pl'];
        yield 'cs' => [['Jiří Novák', 'Přihláška', 'Chtěl bych se přihlásit na kurz v říjnu, děkuji.'], 'jiri.novak@seznam.cz'];
        yield 'sk' => [['Zuzana Horváthová', 'Prihláška', 'Chcela by som sa prihlásiť na kurz, ďakujem.'], 'zuzana.horvathova@azet.sk'];
        yield 'hr' => [['Ivana Kovačević', 'Prijava', 'Željela bih se prijaviti na tečaj u listopadu, hvala.'], 'ivana.kovacevic@gmail.com'];
        yield 'sl' => [['Matej Kranjc', 'Prijava', 'Rad bi se prijavil na tečaj, hvala.'], 'matej.kranjc@siol.net'];
        yield 'hu' => [['Nagy Eszter', 'Jelentkezés', 'Szeretnék jelentkezni az októberi tanfolyamra, köszönöm.'], 'nagy.eszter@freemail.hu'];
        yield 'hu Stichworte' => [['Kovács Péter', 'Kérdés', 'Tanfolyam október Bécs ár kedvezmény'], 'kovacs.peter@citromail.hu'];
        yield 'ro' => [['Andrei Popescu', 'Înscriere', 'Aș dori să mă înscriu la cursul din octombrie, mulțumesc.'], 'andrei.popescu@yahoo.com'];
        yield 'tr' => [['Ayşe Yılmaz', 'Kayıt', 'Merhaba, ekim ayındaki kursa kayıt olmak istiyorum, teşekkürler.'], 'ayse.yilmaz@hotmail.com'];
        yield 'tr Stichworte' => [['Mehmet Demir', 'Soru', 'Kurs ekim Viyana fiyat indirim'], 'mehmet.demir@gmail.com'];
        yield 'fi' => [['Mikko Virtanen', 'Ilmoittautuminen', 'Haluaisin ilmoittautua lokakuun kurssille, kiitos.'], 'mikko.virtanen@elisa.fi'];
        yield 'fi Stichworte' => [['Aino Korhonen', 'Kysymys', 'Kurssi lokakuu Wien hinta alennus'], 'aino.korhonen@gmail.com'];
        yield 'sv' => [['Erik Johansson', 'Anmälan', 'Jag vill anmäla mig till kursen i oktober, tack.'], 'erik.johansson@telia.se'];
        yield 'da' => [['Mette Nielsen', 'Tilmelding', 'Jeg vil gerne tilmelde mig kurset i oktober, tak.'], 'mette.nielsen@mail.dk'];
        yield 'vi' => [['Nguyễn Văn An', 'Đăng ký', 'Tôi muốn đăng ký khóa học tháng mười, cảm ơn.'], 'nguyen.van.an@gmail.com'];
        yield 'sq' => [['Arta Krasniqi', 'Regjistrim', 'Dëshiroj të regjistrohem në kursin e tetorit, faleminderit.'], 'arta.krasniqi@gmail.com'];
        yield 'ja Umschrift' => [['Tanaka Hiroshi', 'Moushikomi', 'Kosu ni moushikomi shitai desu'], 'tanaka.hiroshi@yahoo.co.jp'];
        yield 'ru kyrillisch' => [['Иван Петров', 'Запись', 'Хочу записаться на курс в октябре, спасибо.'], 'ivan.petrov@mail.ru'];
        yield 'el griechisch' => [['Γιώργος Παπαδόπουλος', 'Εγγραφή', 'Θα ήθελα να εγγραφώ στο μάθημα.'], 'g.papadopoulos@gmail.com'];
        yield 'Base64 im Anhangshinweis' => [['Stefan Huber', 'Datei', 'Die Datei kam als dGhpcyBpcyBhIHRlc3Q an, bitte nochmal senden.'], 'stefan.huber@gmail.com'];
        yield 'Firma ohne MX kurz' => [['Kleinbetrieb Maier', 'Anfrage', 'Termin?'], 'maier@nomx.example'];
        yield 'Firma ohne MX Stichworte' => [['Tischlerei Hofer', 'Schulung', 'Schulung Mitarbeiter Termin Preis'], 'hofer@nomx.example'];
        yield 'Kommentar mit HTML' => [['Laura', 'Danke für den Beitrag! <b>Sehr hilfreich</b>, siehe auch <a href="https://example.org">hier</a>.'], 'laura.m@gmail.com'];
        yield 'Registrierung Namen' => [['Florian', 'Steiner', 'Steiner & Partner OG', 'Mariahilfer Straße 1', 'Wien'], 'f.steiner@steiner-partner.at'];
    }

    /**
     * @param list<string> $texts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideHumanSubmissions')]
    public function testHumanSubmissionIsNeverSureSpamWithoutAi(array $texts, string $email): void
    {
        // Einmal ohne, einmal mit Tor-Herkunft: auch ein Mensch über Tor bleibt ohne KI-Urteil sauber.
        foreach (['', "198.51.100.7\n"] as $torList) {
            $classifier = new SpamClassifier(
                new ArrayAdapter(),
                $this->createStub(AiSpamJudge::class),
                new NullLogger(),
                new MockHttpClient(static fn (): MockResponse => new MockResponse($torList)),
                static fn (string $host): bool => 'nomx.example' !== $host,
            );

            $result = $classifier->classify($texts, [$email], '198.51.100.7');

            self::assertFalse($result['spam'], ('' === $torList ? 'ohne' : 'mit').' Tor: '.implode(', ', $result['reasons']).' = '.$result['score'].' Punkte');
        }
    }

    public function testCorpusHasAtLeastFiftySubmissions(): void
    {
        self::assertGreaterThanOrEqual(50, iterator_count(self::provideHumanSubmissions()));
    }
}

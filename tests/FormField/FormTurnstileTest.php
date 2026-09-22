<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\FormField;

use Contao\System;
use Contao\TestCase\ContaoTestCase;
use Contao\Widget;
use Mandrael\ContaoTurnstileBundle\FormField\FormTurnstile;
use Mandrael\ContaoTurnstileBundle\Service\AltchaVerifier;
use Mandrael\ContaoTurnstileBundle\Service\TurnstileVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class FormTurnstileTest extends ContaoTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_CONFIG']);

        parent::tearDown();
    }

    /**
     * Deckt die komplette Aktivierungs-Matrix ab: globaler Modus (off/optout/optin)
     * kreuz mit Per-Feld-Override (Vorgabe/on/off).
     */
    #[DataProvider('provideModeMatrix')]
    public function testTurnstileApplies(string $mode, string $field, bool $expected): void
    {
        $GLOBALS['TL_CONFIG']['turnstileMode'] = $mode;

        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();
        $applies = new \ReflectionMethod($widget, 'turnstileApplies');

        self::assertSame($expected, $applies->invoke($widget, ['turnstileField' => $field]));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideModeMatrix(): iterable
    {
        // 'off' ist die globale Notbremse und schlägt jeden Per-Feld-Override.
        yield 'off schlägt Feld-on' => ['off', 'on', false];
        yield 'off, Vorgabe' => ['off', '', false];

        // 'optout': standardmäßig für alle, einzelne Felder können abwählen.
        yield 'optout Vorgabe greift' => ['optout', '', true];
        yield 'optout Feld-off wählt ab' => ['optout', 'off', false];
        yield 'optout Feld-on greift' => ['optout', 'on', true];

        // 'optin': nur ausgewählte Felder.
        yield 'optin Vorgabe greift nicht' => ['optin', '', false];
        yield 'optin Feld-on greift' => ['optin', 'on', true];
        yield 'optin Feld-off greift nicht' => ['optin', 'off', false];
    }

    public function testValidateReadsPerInstanceToken(): void
    {
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->expects(self::once())->method('validate')->with('a-token')->willReturn(true);

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'a-token'], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testValidateFallsBackToDefaultFieldName(): void
    {
        // Template-Override ohne -<id>-Suffix: Cloudflare-Default cf-turnstile-response wird gelesen.
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->expects(self::once())->method('validate')->with('a-token')->willReturn(true);

        $widget = $this->createWidget('42', ['cf-turnstile-response' => 'a-token'], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testValidateIgnoresForeignInstanceToken(): void
    {
        // Token eines ANDEREN Turnstile-Felds im selben Formular darf das eigene nicht erfüllen.
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->expects(self::once())->method('validate')->with('')->willReturn(false);

        $widget = $this->createWidget('42', ['cf-turnstile-response-99' => 'fremdes-token'], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testValidateCoercesArrayTokenToEmpty(): void
    {
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->expects(self::once())->method('validate')->with('')->willReturn(false);

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => ['x']], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testFilterModeInvalidTokenPassesWithoutError(): void
    {
        // 'filter' = Fallback: fehlgeschlagene Prüfung wird durchgelassen + protokolliert, nicht geblockt.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logSoftPass')->with('verification-failed');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'bad-token'], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testFilterModeMissingTokenPassesAndLogsCategory(): void
    {
        // Fehlendes Token im filter-Modus: kein Error, aber protokolliert (Kategorie missing-token).
        // Die eigentliche Missing-Token-WARNUNG kommt aus dem Verifier (hier gemockt, separat getestet).
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->with('')->willReturn(false);
        $verifier->expects(self::once())->method('logSoftPass')->with('missing-token');

        $widget = $this->createWidget('42', [], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testFilterModeHoneypotFilledBlocks(): void
    {
        // Befüllter Honeypot ist ein eindeutiges Bot-Signal: trotz filter-Modus blocken, nicht durchlassen.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-hp-42' => 'ich bin ein bot',
        ], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testFilterModeTooFastSubmissionBlocks(): void
    {
        // Gültig signierter, aber unmenschlich frischer Zeitstempel: blocken.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-ts-42' => self::signTime(time()),
        ], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testFilterModePassesWhenSlowEnoughAndHoneypotEmpty(): void
    {
        // Langsam genug ausgefüllt + Honeypot leer: mehrdeutiger Rest -> durchlassen + protokollieren.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logSoftPass')->with('verification-failed');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testFilterModeForgedTimingIsIgnored(): void
    {
        // Ungültige Signatur (z. B. Cache/Template-Override/Fälschung): Timing greift NICHT (fail-open),
        // Honeypot leer -> durchlassen + protokollieren. Kein Fehlalarm durch kaputte Zeitstempel.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logSoftPass')->with('verification-failed');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-ts-42' => time().'.deadbeefdeadbeef',
        ], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testBlockIsDefaultWhenFailureModeUnset(): void
    {
        // Ohne gesetzte Einstellung gilt 'block': fehlgeschlagene Prüfung wird abgewiesen.
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('isCloudflareOutageConfirmed');
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'bad-token'], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testUnknownFailureModeBlocks(): void
    {
        // Unbekannter Wert fällt auf 'block' zurück (sichere Vorgabe), nie still durchlassen.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'bogus';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('isCloudflareOutageConfirmed');
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'bad-token'], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testBlockModeExplicitlyBlocks(): void
    {
        // Explizit gesetzter Standardwert 'block' weist ab (nicht nur der ungesetzte Default).
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'block';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('isCloudflareOutageConfirmed');
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'bad-token'], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeBlocksWithoutOutageEvenWithValidPow(): void
    {
        // Produktionsfall 22.09.2026: Turnstile gab dem Browser-Bot kein Token, er löste den Proof-of-Work.
        // Ohne bestätigten Cloudflare-Ausfall darf die Ersatzstufe das nicht aufheben.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('isCloudflareOutageConfirmed')->willReturn(false);
        $verifier->expects(self::once())->method('logFallbackWithheld');
        $verifier->expects(self::never())->method('logAltchaPass');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'a-payload',
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeBlocksRejectedTokenWithoutOutage(): void
    {
        // Abgelehntes, nicht leeres Token: Cloudflare hat geantwortet, kein Ersatz.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->with('bad-token')->willReturn(false);
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(false);
        $verifier->expects(self::never())->method('logAltchaPass');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'altcha-42' => 'a-payload',
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testFilterModeBlocksRejectedTokenWithoutOutage(): void
    {
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(false);
        $verifier->expects(self::once())->method('logFallbackWithheld');
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testFilterModeBlocksMissingTokenWithoutOutage(): void
    {
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->with('')->willReturn(false);
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(false);
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', ['cf-turnstile-ts-42' => self::signTime(time() - 30)], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testValidTokenNeverProbesForOutage(): void
    {
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(true);
        $verifier->expects(self::never())->method('isCloudflareOutageConfirmed');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'a-token'], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testFilterModeHoneypotArrayBlocks(): void
    {
        // Manipuliertes Honeypot-Feld als Array (Nicht-String) gilt als Bot-Signal -> blocken.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-hp-42' => ['x'],
        ], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeValidSolutionPasses(): void
    {
        // 'altcha': Turnstile schlägt fehl, aber der PoW-Zweitbeweis ist gültig -> durchlassen + Pass loggen.
        // Gültig signierter, ausreichend alter Zeitstempel, damit der neue Timing-Zweig nicht blockt.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaPass');
        $verifier->expects(self::never())->method('logAltchaBlock');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::once())->method('validate')->with('a-payload')->willReturn(true);

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'a-payload',
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testAltchaModeInvalidSolutionBlocks(): void
    {
        // Gefülltes, aber ungültiges Payload = Angriff/Replay -> blocken, Kategorie altcha-invalid.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaBlock')->with('altcha-invalid');
        $verifier->expects(self::never())->method('logAltchaPass');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::once())->method('validate')->with('bad')->willReturn(false);

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'bad',
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeEmptyFieldBlocksAndLogsEmpty(): void
    {
        // Leeres Feld = JS/Endpoint kaputt -> blocken, Kategorie altcha-empty; der Verifier wird nicht bemüht.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaBlock')->with('altcha-empty');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [
            'cf-turnstile-ts-42' => self::signTime(time() - 30),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeMissingTimestampBlocksWithTimingInvalidCategory(): void
    {
        // altcha ist aktiv, aber cf-turnstile-ts-<id> fehlt (Template-Override ohne das Feld) ->
        // fail-closed blocken, Kategorie altcha-timing-invalid; der PoW-Verifier wird nicht bemüht.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaBlock')->with('altcha-timing-invalid');
        $verifier->expects(self::never())->method('logAltchaUnavailable');
        $verifier->expects(self::never())->method('logAltchaPass');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', ['altcha-42' => 'a-payload'], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeForgedTimestampBlocksWithTimingInvalidCategory(): void
    {
        // Falsch signierter Zeitstempel (Fälschung oder Seiten-Cache über eine kernel.secret-Rotation
        // hinweg) -> ebenfalls fail-closed mit Kategorie altcha-timing-invalid.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaBlock')->with('altcha-timing-invalid');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'a-payload',
            'cf-turnstile-ts-42' => time().'.deadbeefdeadbeef',
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeHourOldValidTimestampIsNotBlockedByTiming(): void
    {
        // Gültig signiert und eine Stunde alt (z. B. aus dem Seiten-Cache): "zu alt" ist kein Fehler,
        // geprüft wird weiter nur die Mindestzeit. Der PoW-Zweitbeweis entscheidet weiter.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaPass');
        $verifier->expects(self::never())->method('logAltchaBlock');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::once())->method('validate')->with('a-payload')->willReturn(true);

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'a-payload',
            'cf-turnstile-ts-42' => self::signTime(time() - 3600),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testAltchaModeTooFastBlocksWithoutLog(): void
    {
        // "Zu schnell" blockt weiterhin wie bisher, aber ohne eigenes Log (kein altcha-timing-invalid).
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logAltchaBlock');
        $verifier->expects(self::never())->method('logAltchaPass');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'a-payload',
            'cf-turnstile-ts-42' => self::signTime(time()),
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeHoneypotBlocksBeforePow(): void
    {
        // Billiger Filter zuerst (nach dem altchaActive-Check): befüllter Honeypot blockt, der
        // PoW-Verifier wird gar nicht erst gerufen.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logAltchaPass');
        $verifier->expects(self::never())->method('logAltchaBlock');
        $verifier->expects(self::never())->method('logAltchaUnavailable');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [
            'altcha-42' => 'a-payload',
            'cf-turnstile-hp-42' => 'ich bin ein bot',
        ], $verifier, $altcha);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaModeUnavailableBlocksAndLogsError(): void
    {
        // ALTCHA nicht verfügbar (altchaActive=false: unsicherer Kontext ODER fehlende Route): fail-
        // closed blocken + Betriebsstörung auf error loggen, nicht mehr durchlassen. Der PoW-Verifier
        // wird nicht bemüht. Reihenfolge: dieser Zweig läuft zuerst, auch wenn zugleich der
        // Zeitstempel fehlt (kein cf-turnstile-ts-42 im Post), bleibt logAltchaUnavailable() die
        // Meldung, nicht logAltchaBlock('altcha-timing-invalid').
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
        // Bestätigter Cloudflare-Ausfall: nur dann greift die Ersatzstufe (seit 0.8.0).
        $verifier->method('isCloudflareOutageConfirmed')->willReturn(true);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::once())->method('logAltchaUnavailable');
        $verifier->expects(self::never())->method('logAltchaBlock');
        $verifier->expects(self::never())->method('logSoftPass');

        $altcha = $this->createMock(AltchaVerifier::class);
        $altcha->expects(self::never())->method('validate');

        $widget = $this->createAltchaWidget('42', [], $verifier, $altcha, false);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testAltchaChallengeUrlDegradesWhenRouteMissing(): void
    {
        // Nicht-Managed-Setup: Route nicht registriert -> generate() wirft -> '' (kein Crash), altcha inaktiv.
        $router = $this->createMock(\Symfony\Component\Routing\RouterInterface::class);
        $router->method('generate')->willThrowException(new \Symfony\Component\Routing\Exception\RouteNotFoundException());

        self::assertSame('', $this->invokeAltchaChallengeUrl($router));
    }

    public function testAltchaChallengeUrlReturnsGeneratedUrl(): void
    {
        $router = $this->createMock(\Symfony\Component\Routing\RouterInterface::class);
        $router->method('generate')->willReturn('/_mandrael_turnstile/altcha');

        self::assertSame('/_mandrael_turnstile/altcha', $this->invokeAltchaChallengeUrl($router));
    }

    private function invokeAltchaChallengeUrl(object $router): string
    {
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        $container = new Container();
        $container->set('router', $router);
        System::setContainer($container);

        return (string) (new \ReflectionMethod($widget, 'altchaChallengeUrl'))->invoke($widget);
    }

    public function testBundleUrlUsesRequestBasePath(): void
    {
        // Installation unter /sub/: SCRIPT_NAME/SCRIPT_FILENAME müssen übereinstimmen, damit
        // Symfony überhaupt einen Basis-Pfad erkennt (siehe Request::prepareBaseUrl()).
        $request = Request::create('http://example.com/sub/index.php/kontakt');
        $request->server->set('SCRIPT_NAME', '/sub/index.php');
        $request->server->set('SCRIPT_FILENAME', '/var/www/sub/index.php');

        self::assertSame(
            '/sub/bundles/mandraelcontaoturnstile/altcha/worker.js',
            $this->invokeBundleUrl($request)
        );
    }

    public function testBundleUrlIsRootRelativeWithoutBasePath(): void
    {
        self::assertSame(
            '/bundles/mandraelcontaoturnstile/altcha/worker.js',
            $this->invokeBundleUrl(Request::create('http://example.com/kontakt'))
        );
    }

    public function testBundleUrlEmptyWithoutRequest(): void
    {
        self::assertSame('', $this->invokeBundleUrl(null));
    }

    public function testBundleUrlIgnoresAssetPackage(): void
    {
        // Selbst wenn ein assets.packages-Dienst eine CDN-Adresse liefern würde, bleibt die Adresse
        // same-origin – bundleUrl() fragt den Dienst gar nicht mehr ab.
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        $stack = new RequestStack();
        $stack->push(Request::create('http://example.com/kontakt'));

        $packages = $this->createMock(\Symfony\Component\Asset\Packages::class);
        $packages->expects(self::never())->method('getUrl');

        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('assets.packages', $packages);
        System::setContainer($container);

        self::assertSame(
            '/bundles/mandraelcontaoturnstile/altcha/worker.js',
            (string) (new \ReflectionMethod($widget, 'bundleUrl'))->invoke($widget, 'worker.js')
        );
    }

    private function invokeBundleUrl(?Request $request): string
    {
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        $stack = new RequestStack();

        if (null !== $request) {
            $stack->push($request);
        }

        $container = new Container();
        $container->set('request_stack', $stack);
        System::setContainer($container);

        return (string) (new \ReflectionMethod($widget, 'bundleUrl'))->invoke($widget, 'worker.js');
    }

    /**
     * @return list<string>
     */
    #[DataProvider('provideSingleMissingMarker')]
    public function testMissingTemplateMarkersDetectsEachMissingMarker(string $removedMarker): void
    {
        $widget = $this->createMarkerWidget('42', true);
        $html = str_replace($removedMarker, '', self::completeMarkerHtml('42'));

        self::assertSame([$removedMarker], $this->invokeMissingTemplateMarkers($widget, $html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSingleMissingMarker(): iterable
    {
        yield 'response' => ['cf-turnstile-response-42'];
        yield 'honeypot' => ['cf-turnstile-hp-42'];
        yield 'zeitstempel' => ['cf-turnstile-ts-42'];
        yield 'altcha' => ['altcha-42'];
        yield 'altcha data attribute' => ['data-mandrael-altcha'];
    }

    public function testMissingTemplateMarkersToleratesQuotingWhitespaceAndAttributeOrder(): void
    {
        // Einfache Anführungszeichen, Leerzeichen um "=", andere Attributreihenfolge – str_contains
        // sucht nur das nackte Token, das bleibt in jeder dieser Schreibweisen unverändert erhalten.
        $html = <<<'HTML'
            <div data-sitekey = 'site-key' data-theme='light' data-response-field-name = 'cf-turnstile-response-42' data-size='normal'></div>
            <input type="text" name = 'cf-turnstile-hp-42' tabindex="-1">
            <input name='cf-turnstile-ts-42' type = "hidden" value="">
            <input data-mandrael-altcha data-challengeurl='/x' data-workerurl = "/y" name = 'altcha-42' type="hidden">
            HTML;

        $widget = $this->createMarkerWidget('42', true);

        self::assertSame([], $this->invokeMissingTemplateMarkers($widget, $html));
    }

    public function testMissingTemplateMarkersSkipsAltchaMarkersWhenAltchaInactive(): void
    {
        // ALTCHA inaktiv: die beiden ALTCHA-Marker fehlen im HTML, zählen aber nicht als fehlend.
        $html = '<div data-sitekey="site-key" data-response-field-name="cf-turnstile-response-42"></div>'
            .'<input name="cf-turnstile-hp-42">'
            .'<input name="cf-turnstile-ts-42">';

        $widget = $this->createMarkerWidget('42', false);

        self::assertSame([], $this->invokeMissingTemplateMarkers($widget, $html));
    }

    public function testBundledTemplateContainsAllRequiredMarkers(): void
    {
        // Schützt davor, dass jemand das Bundle-Template ändert und die Selbstprüfung dann bei
        // JEDEM Rendern Fehlalarm gibt (die Template-Datei ist der Maßstab für die Marker-Stämme).
        $path = \dirname(__DIR__, 2).'/contao/templates/form_mandrael_turnstile.html5';
        $html = (string) file_get_contents($path);

        foreach ([
            'cf-turnstile-response-',
            'cf-turnstile-hp-',
            'cf-turnstile-ts-',
            'altcha-',
            'data-mandrael-altcha',
            'mandrael-altcha',
            'data-sitekey',
            'data-challengeurl',
            'data-workerurl',
        ] as $stem) {
            self::assertStringContainsString($stem, $html, \sprintf('Template-Stamm "%s" fehlt im Bundle-Template.', $stem));
        }
    }

    public function testIntactTemplateDoesNotTouchCache(): void
    {
        // Bei leerer Marker-Liste (intaktes Template, TL_BODY-Eintrag gesetzt) wird logTemplateOutdated()
        // nie aufgerufen – checkTemplateMarkers() rührt den Verifier (und damit dessen Cache) nur an,
        // wenn wirklich etwas fehlt.
        $GLOBALS['TL_BODY']['mandrael-altcha'] = '<script></script>';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->expects(self::never())->method('logTemplateOutdated');

        $widget = $this->createMarkerWidget('42', true);
        (new \ReflectionProperty(FormTurnstile::class, 'fallbackToCaptcha'))->setValue($widget, false);

        $container = new Container();
        $container->set(TurnstileVerifier::class, $verifier);
        System::setContainer($container);

        (new \ReflectionMethod($widget, 'checkTemplateMarkers'))->invoke($widget, self::completeMarkerHtml('42'));

        unset($GLOBALS['TL_BODY']);
    }

    public function testCheckTemplateMarkersSkipsCaptchaFallback(): void
    {
        // Der Wächter sitzt jetzt in checkTemplateMarkers() selbst (einzige entscheidende Stelle):
        // eine auf form_captcha zurückgefallene Installation darf nie template-outdated loggen, auch
        // nicht bei HTML ohne jeden Marker.
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->expects(self::never())->method('logTemplateOutdated');

        $widget = $this->createMarkerWidget('42', true);
        (new \ReflectionProperty(FormTurnstile::class, 'fallbackToCaptcha'))->setValue($widget, true);

        $container = new Container();
        $container->set(TurnstileVerifier::class, $verifier);
        System::setContainer($container);

        (new \ReflectionMethod($widget, 'checkTemplateMarkers'))->invoke($widget, '');
    }

    public function testMissingTemplateMarkersDoesNotMatchLongerId(): void
    {
        // Präfix-Kollision: "cf-turnstile-hp-2" darf nicht bereits durch "cf-turnstile-hp-25" erfüllt gelten.
        $htmlOfFieldTwentyFive = self::completeMarkerHtml('25');

        $widgetTwo = $this->createMarkerWidget('2', false);
        self::assertSame(
            ['cf-turnstile-response-2', 'cf-turnstile-hp-2', 'cf-turnstile-ts-2'],
            $this->invokeMissingTemplateMarkers($widgetTwo, $htmlOfFieldTwentyFive)
        );

        // Gegenprobe: ID 25 gegen das eigene HTML -> nichts fehlt.
        $widgetTwentyFive = $this->createMarkerWidget('25', false);
        self::assertSame([], $this->invokeMissingTemplateMarkers($widgetTwentyFive, $htmlOfFieldTwentyFive));
    }

    public function testMissingTemplateMarkersDetectsMissingSitekeyAndAltchaUrls(): void
    {
        // data-sitekey wird immer verlangt, data-challengeurl/data-workerurl nur bei aktivem ALTCHA.
        $widget = $this->createMarkerWidget('42', true);

        $htmlWithoutSitekey = str_replace('data-sitekey="site-key" ', '', self::completeMarkerHtml('42'));
        self::assertSame(['data-sitekey'], $this->invokeMissingTemplateMarkers($widget, $htmlWithoutSitekey));

        $htmlWithoutChallengeUrl = str_replace('data-challengeurl="/x" ', '', self::completeMarkerHtml('42'));
        self::assertSame(['data-challengeurl'], $this->invokeMissingTemplateMarkers($widget, $htmlWithoutChallengeUrl));

        $htmlWithoutWorkerUrl = str_replace('data-workerurl="/y" ', '', self::completeMarkerHtml('42'));
        self::assertSame(['data-workerurl'], $this->invokeMissingTemplateMarkers($widget, $htmlWithoutWorkerUrl));

        // Bei inaktivem ALTCHA werden data-challengeurl/data-workerurl nicht verlangt.
        $widgetInactive = $this->createMarkerWidget('42', false);
        $htmlInactive = '<div data-response-field-name="cf-turnstile-response-42"></div>'
            .'<input name="cf-turnstile-hp-42">'
            .'<input name="cf-turnstile-ts-42">';
        self::assertSame(['data-sitekey'], $this->invokeMissingTemplateMarkers($widgetInactive, $htmlInactive));
    }

    private static function completeMarkerHtml(string $id): string
    {
        return '<div data-sitekey="site-key" data-response-field-name="cf-turnstile-response-'.$id.'"></div>'
            .'<input name="cf-turnstile-hp-'.$id.'">'
            .'<input name="cf-turnstile-ts-'.$id.'">'
            .'<input data-mandrael-altcha data-challengeurl="/x" data-workerurl="/y" name="altcha-'.$id.'">';
    }

    private function createMarkerWidget(string $id, bool $altchaActive): FormTurnstile
    {
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(Widget::class, 'strId'))->setValue($widget, $id);
        (new \ReflectionProperty(FormTurnstile::class, 'altchaActive'))->setValue($widget, $altchaActive);

        return $widget;
    }

    /**
     * @return list<string>
     */
    private function invokeMissingTemplateMarkers(FormTurnstile $widget, string $html): array
    {
        /** @var list<string> $missing */
        $missing = (new \ReflectionMethod($widget, 'missingTemplateMarkers'))->invoke($widget, $html);

        return $missing;
    }

    public function testIsSecureContextRecognisesLoopbackHosts(): void
    {
        // Request::getHost() liefert IPv6-Loopback in Klammern ('[::1]') – der frühere nackte '::1'-Eintrag
        // matchte nie. HTTPS-los, aber Loopback = Secure Context.
        self::assertTrue($this->invokeIsSecureContext(Request::create('http://[::1]:8000/')));
        self::assertTrue($this->invokeIsSecureContext(Request::create('http://127.0.0.1/')));
        self::assertTrue($this->invokeIsSecureContext(Request::create('http://app.localhost/')));
        // Echte Domain ohne HTTPS ist kein Secure Context.
        self::assertFalse($this->invokeIsSecureContext(Request::create('http://example.com/')));
        // Echte Domain MIT HTTPS schon.
        self::assertTrue($this->invokeIsSecureContext(Request::create('https://example.com/')));
    }

    private function invokeIsSecureContext(Request $request): bool
    {
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        $stack = new RequestStack();
        $stack->push($request);

        $container = new Container();
        $container->set('request_stack', $stack);
        System::setContainer($container);

        return (bool) (new \ReflectionMethod($widget, 'isSecureContext'))->invoke($widget);
    }

    private static function signTime(int $time): string
    {
        // Muss bitgenau zu FormTurnstile::signTime() passen (Format pinnen).
        return $time.'.'.substr(hash_hmac('sha256', (string) $time, 'test-secret'), 0, 16);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function createAltchaWidget(string $id, array $post, TurnstileVerifier $verifier, AltchaVerifier $altcha, bool $altchaActive = true): FormTurnstile
    {
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(Widget::class, 'strId'))->setValue($widget, $id);
        (new \ReflectionProperty(FormTurnstile::class, 'altchaActive'))->setValue($widget, $altchaActive);

        $requestStack = new RequestStack();
        $requestStack->push(new Request([], $post));

        $container = new Container();
        $container->setParameter('kernel.secret', 'test-secret');
        $container->set('request_stack', $requestStack);
        $container->set(TurnstileVerifier::class, $verifier);
        $container->set(AltchaVerifier::class, $altcha);
        System::setContainer($container);

        return $widget;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function createWidget(string $id, array $post, TurnstileVerifier $verifier): FormTurnstile
    {
        $widget = (new \ReflectionClass(FormTurnstile::class))->newInstanceWithoutConstructor();

        $strId = new \ReflectionProperty(Widget::class, 'strId');
        $strId->setValue($widget, $id);

        $requestStack = new RequestStack();
        $requestStack->push(new Request([], $post));

        $container = new Container();
        $container->setParameter('kernel.secret', 'test-secret');
        $container->set('request_stack', $requestStack);
        $container->set(TurnstileVerifier::class, $verifier);
        System::setContainer($container);

        return $widget;
    }
}

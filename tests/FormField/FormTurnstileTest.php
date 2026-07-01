<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\FormField;

use Contao\System;
use Contao\TestCase\ContaoTestCase;
use Contao\Widget;
use Mandrael\ContaoTurnstileBundle\FormField\FormTurnstile;
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
        // 'off' ist die globale Notbremse und schlaegt jeden Per-Feld-Override.
        yield 'off schlaegt Feld-on' => ['off', 'on', false];
        yield 'off, Vorgabe' => ['off', '', false];

        // 'optout': standardmaessig fuer alle, einzelne Felder koennen abwaehlen.
        yield 'optout Vorgabe greift' => ['optout', '', true];
        yield 'optout Feld-off waehlt ab' => ['optout', 'off', false];
        yield 'optout Feld-on greift' => ['optout', 'on', true];

        // 'optin': nur ausgewaehlte Felder.
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
        // Token eines ANDEREN Turnstile-Felds im selben Formular darf das eigene nicht erfuellen.
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
        // 'filter' = Fallback: fehlgeschlagene Pruefung wird durchgelassen + protokolliert, nicht geblockt.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
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
        $verifier->method('validate')->with('')->willReturn(false);
        $verifier->expects(self::once())->method('logSoftPass')->with('missing-token');

        $widget = $this->createWidget('42', [], $verifier);
        $widget->validate();

        self::assertFalse($widget->hasErrors());
    }

    public function testFilterModeHoneypotFilledBlocks(): void
    {
        // Befuellter Honeypot ist ein eindeutiges Bot-Signal: trotz filter-Modus blocken, nicht durchlassen.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
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
        // Gueltig signierter, aber unmenschlich frischer Zeitstempel: blocken.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
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
        // Langsam genug ausgefuellt + Honeypot leer: mehrdeutiger Rest -> durchlassen + protokollieren.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
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
        // Ungueltige Signatur (z. B. Cache/Template-Override/Faelschung): Timing greift NICHT (fail-open),
        // Honeypot leer -> durchlassen + protokollieren. Kein Fehlalarm durch kaputte Zeitstempel.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
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
        // Ohne gesetzte Einstellung gilt 'block': fehlgeschlagene Pruefung wird abgewiesen.
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'bad-token'], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testUnknownFailureModeBlocks(): void
    {
        // Unbekannter Wert faellt auf 'block' zurueck (sichere Vorgabe), nie still durchlassen.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'bogus';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
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
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', ['cf-turnstile-response-42' => 'bad-token'], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    public function testFilterModeHoneypotArrayBlocks(): void
    {
        // Manipuliertes Honeypot-Feld als Array (Nicht-String) gilt als Bot-Signal -> blocken.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'filter';

        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
        $verifier->expects(self::never())->method('logSoftPass');

        $widget = $this->createWidget('42', [
            'cf-turnstile-response-42' => 'bad-token',
            'cf-turnstile-hp-42' => ['x'],
        ], $verifier);
        $widget->validate();

        self::assertTrue($widget->hasErrors());
    }

    private static function signTime(int $time): string
    {
        // Muss bitgenau zu FormTurnstile::signTime() passen (Format pinnen).
        return $time.'.'.substr(hash_hmac('sha256', (string) $time, 'test-secret'), 0, 16);
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

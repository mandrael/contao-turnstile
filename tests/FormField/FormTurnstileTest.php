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
        // Befüllter Honeypot ist ein eindeutiges Bot-Signal: trotz filter-Modus blocken, nicht durchlassen.
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
        // Gültig signierter, aber unmenschlich frischer Zeitstempel: blocken.
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
        // Langsam genug ausgefüllt + Honeypot leer: mehrdeutiger Rest -> durchlassen + protokollieren.
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
        // Ungültige Signatur (z. B. Cache/Template-Override/Fälschung): Timing greift NICHT (fail-open),
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
        // Ohne gesetzte Einstellung gilt 'block': fehlgeschlagene Prüfung wird abgewiesen.
        $verifier = $this->createMock(TurnstileVerifier::class);
        $verifier->method('validate')->willReturn(false);
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

    public function testAltchaModeValidSolutionPasses(): void
    {
        // 'altcha': Turnstile schlägt fehl, aber der PoW-Zweitbeweis ist gültig -> durchlassen + Pass loggen.
        // Gültig signierter, ausreichend alter Zeitstempel, damit der neue Timing-Zweig nicht blockt.
        $GLOBALS['TL_CONFIG']['turnstileFailureMode'] = 'altcha';

        $verifier = $this->createMock(TurnstileVerifier::class);
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

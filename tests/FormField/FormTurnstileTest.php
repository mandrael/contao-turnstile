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
        $container->set('request_stack', $requestStack);
        $container->set(TurnstileVerifier::class, $verifier);
        System::setContainer($container);

        return $widget;
    }
}

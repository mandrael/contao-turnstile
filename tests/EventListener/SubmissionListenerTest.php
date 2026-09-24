<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\EventListener;

use Contao\Form;
use Contao\FormFieldModel;
use Contao\TestCase\ContaoTestCase;
use Mandrael\ContaoTurnstileBundle\EventListener\SubmissionListener;
use Mandrael\ContaoTurnstileBundle\Mailer\SpamAwareMailer;
use Mandrael\ContaoTurnstileBundle\Service\SpamClassifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class SubmissionListenerTest extends ContaoTestCase
{
    // -- onTokenlessPass() ----------------------------------------------------------------------------

    public function testOnTokenlessPassWithoutFormGeneratorSubmitSetsPendingWithIp(): void
    {
        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->expects(self::never())->method('classify');

        $requestStack = $this->stack('203.0.113.9');
        $listener = new SubmissionListener($requestStack, $classifier);

        $listener->onTokenlessPass(['FORM_SUBMIT' => 'tg_form_1']);

        self::assertSame(['mode' => 'pending', 'ip' => '203.0.113.9'], SpamAwareMailer::state($requestStack));
    }

    public function testOnTokenlessPassWithoutCommentFieldSetsPending(): void
    {
        // com_-Formular, aber ohne 'comment'-Schlüssel im POST (z. B. Kontaktformular) -> pending, kein Kommentar.
        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->expects(self::never())->method('classify');

        $requestStack = $this->stack('203.0.113.9');
        $listener = new SubmissionListener($requestStack, $classifier);

        $listener->onTokenlessPass(['FORM_SUBMIT' => 'com_tl_news_5']);

        self::assertSame(['mode' => 'pending', 'ip' => '203.0.113.9'], SpamAwareMailer::state($requestStack));
    }

    public function testOnTokenlessPassCommentClassifiesAndSuppressesOnSpam(): void
    {
        $post = ['FORM_SUBMIT' => 'com_tl_news_5', 'name' => 'Max Mustermann', 'comment' => 'Toller Artikel!', 'email' => 'max@example.com'];

        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->expects(self::once())->method('classify')
            ->with(['Max Mustermann', 'Toller Artikel!'], ['max@example.com'], '203.0.113.9')
            ->willReturn(['spam' => true, 'score' => 9, 'reasons' => []]);
        $classifier->expects(self::never())->method('throttledRecipients');

        $requestStack = $this->stack('203.0.113.9');
        $listener = new SubmissionListener($requestStack, $classifier);

        $listener->onTokenlessPass($post);

        self::assertSame([
            'mode' => 'suppress',
            'source' => 'comment',
            'score' => 9,
            'reasons' => [],
            'addresses' => ['max@example.com'],
            'protected' => [],
            'throttled' => [],
        ], SpamAwareMailer::state($requestStack));
    }

    public function testOnTokenlessPassCommentCleansAndThrottlesOnNonSpam(): void
    {
        $post = ['FORM_SUBMIT' => 'com_tl_news_5', 'name' => 'Max Mustermann', 'comment' => 'Toller Artikel!', 'email' => 'max@example.com'];

        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->method('classify')->willReturn(['spam' => false, 'score' => 0, 'reasons' => []]);
        $classifier->expects(self::once())->method('throttledRecipients')->with(['max@example.com'])->willReturn(['max@example.com']);

        $requestStack = $this->stack('203.0.113.9');
        $listener = new SubmissionListener($requestStack, $classifier);

        $listener->onTokenlessPass($post);

        self::assertSame([
            'mode' => 'clean',
            'source' => 'comment',
            'score' => 0,
            'reasons' => [],
            'addresses' => ['max@example.com'],
            'protected' => [],
            'throttled' => ['max@example.com'],
        ], SpamAwareMailer::state($requestStack));
    }

    // -- onPrepareFormData() ----------------------------------------------------------------------------

    public function testOnPrepareFormDataDoesNothingWithoutPendingState(): void
    {
        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->expects(self::never())->method('classify');

        $requestStack = $this->stack('203.0.113.9');
        SpamAwareMailer::setState($requestStack, ['mode' => 'clean']);

        $listener = new SubmissionListener($requestStack, $classifier);
        $listener->onPrepareFormData([], [], [], $this->createClassWithPropertiesStub(Form::class, ['recipient' => '']));
    }

    public function testOnPrepareFormDataCollectsEmailAndTextFieldsExcludingNonTextRgxp(): void
    {
        $submitted = [
            'email' => 'max@example.com',
            'message' => 'Bitte um Rückruf',
            'name' => 'Max Mustermann',
            'homepage' => 'https://example.com',
            'phone' => '+43 1 2345678',
            'consent' => '1',
        ];

        $fields = [
            'name' => $this->createClassWithPropertiesStub(FormFieldModel::class, ['name' => 'name', 'type' => 'text', 'rgxp' => '']),
            'email' => $this->createClassWithPropertiesStub(FormFieldModel::class, ['name' => 'email', 'type' => 'text', 'rgxp' => 'email']),
            'message' => $this->createClassWithPropertiesStub(FormFieldModel::class, ['name' => 'message', 'type' => 'textarea', 'rgxp' => '']),
            'homepage' => $this->createClassWithPropertiesStub(FormFieldModel::class, ['name' => 'homepage', 'type' => 'text', 'rgxp' => 'url']),
            'phone' => $this->createClassWithPropertiesStub(FormFieldModel::class, ['name' => 'phone', 'type' => 'text', 'rgxp' => 'phone']),
            'consent' => $this->createClassWithPropertiesStub(FormFieldModel::class, ['name' => 'consent', 'type' => 'checkbox', 'rgxp' => '']),
        ];

        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->expects(self::once())->method('classify')
            ->with(['Max Mustermann', 'Bitte um Rückruf'], ['max@example.com'], '203.0.113.9')
            ->willReturn(['spam' => false, 'score' => 0, 'reasons' => []]);
        $classifier->method('throttledRecipients')->willReturn([]);

        $requestStack = $this->stack('203.0.113.9');
        SpamAwareMailer::setState($requestStack, ['mode' => 'pending', 'ip' => '203.0.113.9']);

        $form = $this->createClassWithPropertiesStub(Form::class, ['recipient' => 'institut@nk.at, zweigstelle@nk.at ,']);

        $listener = new SubmissionListener($requestStack, $classifier);
        $listener->onPrepareFormData($submitted, [], $fields, $form);

        $state = SpamAwareMailer::state($requestStack);
        self::assertSame('clean', $state['mode']);
        self::assertSame('form', $state['source']);
        self::assertSame(['max@example.com'], $state['addresses']);
        self::assertSame(['institut@nk.at', 'zweigstelle@nk.at'], $state['protected']);
        self::assertSame([], $state['throttled']);
    }

    public function testOnPrepareFormDataProtectedParsesFriendlyAddresses(): void
    {
        $classifier = $this->createStub(SpamClassifier::class);
        $classifier->method('classify')->willReturn(['spam' => false, 'score' => 0, 'reasons' => []]);
        $classifier->method('throttledRecipients')->willReturn([]);

        $requestStack = $this->stack('203.0.113.9');
        SpamAwareMailer::setState($requestStack, ['mode' => 'pending', 'ip' => '203.0.113.9']);

        $form = $this->createClassWithPropertiesStub(Form::class, ['recipient' => 'Institut <institut@example.org>, office@example.org']);

        $listener = new SubmissionListener($requestStack, $classifier);
        $listener->onPrepareFormData([], [], [], $form);

        self::assertSame(
            ['institut@example.org', 'office@example.org'],
            SpamAwareMailer::state($requestStack)['protected']
        );
    }

    // -- Registrierung (im Widget eingestuft) ---------------------------------------------------------

    public function testOnTokenlessPassRegistrationMarksOnSpamWithoutThrottle(): void
    {
        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->expects(self::once())->method('classify')
            ->with(['Max', 'Mustermann', 'Mustermann GmbH', 'Hauptstraße 1', 'Wien'], ['max@example.com'], '203.0.113.9')
            ->willReturn(['spam' => true, 'score' => 9, 'reasons' => ['tor-exit', 'dotted-address']]);
        $classifier->expects(self::never())->method('throttledRecipients');

        $requestStack = $this->stack('203.0.113.9');
        $listener = new SubmissionListener($requestStack, $classifier);
        $listener->onTokenlessPass([
            'FORM_SUBMIT' => 'tl_registration_12',
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'company' => 'Mustermann GmbH',
            'street' => 'Hauptstraße 1',
            'city' => 'Wien',
            'email' => 'max@example.com',
        ]);

        $state = SpamAwareMailer::state($requestStack);
        self::assertSame('mark', $state['mode']);
        self::assertSame('registration', $state['source']);
        self::assertSame(['tor-exit', 'dotted-address'], $state['reasons']);
        self::assertSame(['max@example.com'], $state['addresses']);
        self::assertSame([], $state['throttled']);
    }

    public function testOnTokenlessPassRegistrationCleansOnNonSpamWithoutThrottle(): void
    {
        $classifier = $this->createMock(SpamClassifier::class);
        $classifier->method('classify')->willReturn(['spam' => false, 'score' => 0, 'reasons' => []]);
        $classifier->expects(self::never())->method('throttledRecipients');

        $requestStack = $this->stack('203.0.113.9');
        (new SubmissionListener($requestStack, $classifier))->onTokenlessPass(['FORM_SUBMIT' => 'tl_registration_12', 'email' => 'max@example.com']);

        self::assertSame('clean', SpamAwareMailer::state($requestStack)['mode']);
    }

    // -- onAddComment() -------------------------------------------------------------------------------
    //
    // Der Registry-Pfad (tatsächliches Setzen von published/notified auf der aus der Model-Registry
    // geholten Instanz) lässt sich ohne Datenbank/Comments-Bundle nicht sauber unit-testen: Model::__set()
    // ruft Model::convertToPhpValue() auf, das bei leerem Spalten-Cache reale Container-/DB-Infrastruktur
    // braucht (siehe Model.php:491-503). Hier wird nur der Wächter geprüft; der Registry-Pfad ist laut
    // Auftrag "live zu prüfen".

    public function testOnAddCommentDoesNothingWithoutSuppressState(): void
    {
        $classifier = $this->createStub(SpamClassifier::class);
        $requestStack = $this->stack('203.0.113.9');
        SpamAwareMailer::setState($requestStack, ['mode' => 'clean']);

        $listener = new SubmissionListener($requestStack, $classifier);

        // Ohne 'suppress' darf die Registry nie angefasst werden: $GLOBALS['TL_MODELS']['tl_comments'] ist
        // absichtlich NICHT gesetzt. Würde der Code dennoch Registry::fetch('tl_comments', ...) erreichen,
        // wirft Model::getClassFromTable() eine RuntimeException (Model.php:1401-1409) - der Aufruf hier
        // muss also exceptionsfrei durchlaufen.
        $listener->onAddComment(1, []);

        $this->addToAssertionCount(1);
    }

    private function stack(string $clientIp): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(new Request([], [], [], [], [], ['REMOTE_ADDR' => $clientIp]));

        return $stack;
    }
}

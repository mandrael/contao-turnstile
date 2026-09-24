<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\DataContainer;
use Contao\System;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoTurnstileBundle\Backend\SpamArchiveController;
use Mandrael\ContaoTurnstileBundle\Service\SpamArchive;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class SpamArchiveControllerTest extends ContaoTestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        parent::setUp();

        // Message::* und Date::parse() greifen intern auf System::getContainer() zu; ein minimaler
        // Container reicht, weil hier nur die HTML-Erzeugung geprüft wird, nicht Contaos volle Bootstrap-Kette.
        $this->requestStack = new RequestStack();

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher->method('isBackendRequest')->willReturn(false);
        $scopeMatcher->method('isFrontendRequest')->willReturn(false);

        $container = new ContainerBuilder();
        $container->set('request_stack', $this->requestStack);
        $container->set('contao.routing.scope_matcher', $scopeMatcher);
        System::setContainer($container);

        $GLOBALS['TL_CONFIG']['dateFormat'] = 'Y-m-d';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['tl_turnstile_spam']);

        parent::tearDown();
    }

    public function testGetRendersEntryAndNeverDelivers(): void
    {
        $this->pushRequest(Request::create('/', 'GET'));

        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAssociative')->willReturn($this->headRow());
        $db->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::never())->method('deliver');

        $controller = $this->controller($db, $archive);
        $output = $controller->view($this->dc(5));

        self::assertIsString($output);
        self::assertStringContainsString('Hallo', $output);
        self::assertStringContainsString('<form', $output);
    }

    public function testMissingEntryRedirectsWithoutDelivering(): void
    {
        $this->pushRequest(Request::create('/', 'GET'));

        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);

        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::never())->method('deliver');

        $controller = $this->controller($db, $archive);
        $response = $controller->view($this->dc(99));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testPostDeliverCallsArchiveWithIdAndUser(): void
    {
        $request = Request::create('/', 'POST', ['FORM_SUBMIT' => 'turnstile_spam_deliver', 'retryUnclear' => '1']);
        $this->pushRequest($request);

        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->headRow());

        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::once())->method('deliver')
            ->with(5, 42, true)
            ->willReturn(['sent' => 1, 'failed' => 0, 'unclear' => 0]);

        $controller = $this->controller($db, $archive);
        $response = $controller->view($this->dc(5));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testGetNeverCallsDeliverEvenWithFormSubmitInQuery(): void
    {
        // GET-Requests loesen nie deliver() aus, auch nicht mit einem gleichnamigen Query-Parameter.
        $this->pushRequest(Request::create('/?FORM_SUBMIT=turnstile_spam_deliver', 'GET'));

        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->headRow());
        $db->method('fetchAllAssociative')->willReturn([]);

        $archive = $this->createMock(SpamArchive::class);
        $archive->expects(self::never())->method('deliver');

        $controller = $this->controller($db, $archive);
        $controller->view($this->dc(5));
    }

    private function controller(Connection $db, SpamArchive $archive): SpamArchiveController
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/contao?do=turnstile_spam');

        $csrf = $this->createMock(ContaoCsrfTokenManager::class);
        $csrf->method('getDefaultTokenValue')->willReturn('token');

        $user = $this->createClassWithPropertiesMock(BackendUser::class, ['id' => 42]);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return new SpamArchiveController($db, $archive, $this->requestStack, $router, $csrf, $tokenStorage);
    }

    private function pushRequest(Request $request): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $this->requestStack->push($request);
    }

    private function dc(int $id): DataContainer
    {
        return $this->createClassWithPropertiesMock(DataContainer::class, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function headRow(): array
    {
        return [
            'id' => 5,
            'tstamp' => 1000,
            'created' => 1000,
            'source' => 'form',
            'score' => 9,
            'reasons' => 'tor, links',
            'subject' => 'Test',
            'recipients' => 'a@example.com',
            'preview' => 'Hallo',
            'digested' => 0,
            'delivered' => 0,
            'label' => 'unreviewed',
            'label_by' => 0,
            'label_at' => 0,
            'rule_version' => '0.8.0',
        ];
    }
}

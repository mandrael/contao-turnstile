<?php

declare(strict_types=1);

namespace Mandrael\ContaoTurnstileBundle\Tests\DependencyInjection;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Mandrael\ContaoTurnstileBundle\EventListener\SubmissionListener;
use Mandrael\ContaoTurnstileBundle\Mailer\SpamAwareMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Lädt config/services.yaml in einen eigenständigen ContainerBuilder (nicht über die Extension, die
 * zusätzlich services_csp.yaml und damit CloudflareCspSourceRegistrar/ResponseContextAccessor zieht –
 * hier geht es nur um die Verdrahtung aus services.yaml selbst). Externe Dienste, die services.yaml
 * voraussetzt (mailer.mailer, request_stack, monolog.logger.contao, cache.app, http_client,
 * contao.framework, kernel.secret), werden durch Stubs ersetzt.
 */
class ServiceWiringTest extends TestCase
{
    public function testMailerMailerIsDecoratedBySpamAwareMailer(): void
    {
        // compile() ist noetig, damit die DecoratorServicePass tatsaechlich dekoriert (ohne compile()
        // bleibt "mailer.mailer" der unveraenderte StubMailer). Der umbenannte Innendienst wird danach
        // von einer Optimierungs-Pass inlined/entfernt, weil er nur einmal referenziert wird - deshalb
        // wird er hier per Reflexion direkt am Dekorator abgegriffen statt separat aus dem Container geholt.
        $container = $this->buildContainer();
        $container->compile();

        $decorated = $container->get('mailer.mailer');
        self::assertInstanceOf(SpamAwareMailer::class, $decorated);

        $property = new \ReflectionProperty(SpamAwareMailer::class, 'inner');
        $inner = $property->getValue($decorated);
        self::assertInstanceOf(StubMailer::class, $inner);

        // Funktionsprobe: ohne Turnstile-Zustand reicht der Dekorator unverändert an den Stub durch.
        $message = new RawMessage('Test');
        $decorated->send($message, new Envelope(new Address('a@b.at'), [new Address('c@d.at')]));
        self::assertCount(1, $inner->sent);
    }

    public function testSpamAwareMailerDefinitionDecoratesMailerMailerWithFixedPriority(): void
    {
        // Strukturprüfung an der Definition selbst, unabhängig vom vollen Compile-Lauf.
        $container = $this->buildContainer();

        $definition = $container->getDefinition(SpamAwareMailer::class);
        $decorated = $definition->getDecoratedService();

        self::assertNotNull($decorated);
        self::assertSame('mailer.mailer', $decorated[0]);
        self::assertSame(10, $decorated[2]);
    }

    public function testSpamAwareMailerSitsInsideContaoMailer(): void
    {
        // Contao dekoriert mailer.mailer ohne Priorität (4.13 services.yml:415, 5.x services.yaml). Die Ablage muss
        // innen liegen, damit Absender und X-Transport schon gesetzt sind.
        $container = $this->buildContainer();
        $container->register('contao.mailer', StubDecorator::class)
            ->setDecoratedService('mailer.mailer')
            ->setArguments([new Reference('.inner')])
            ->setPublic(true);
        $container->compile();

        $outer = $container->get('mailer.mailer');
        self::assertInstanceOf(StubDecorator::class, $outer);
        self::assertInstanceOf(SpamAwareMailer::class, $outer->inner);
    }

    public function testSubmissionListenerCarriesTheTwoContaoHookTags(): void
    {
        $container = $this->buildContainer();

        $tags = $container->getDefinition(SubmissionListener::class)->getTag('contao.hook');
        $hooks = array_map(static fn (array $tag): array => [$tag['hook'] ?? null, $tag['method'] ?? null], $tags);

        // Registrierung wird schon im Widget eingestuft, createNewUser ist kein Einstufungsort mehr.
        self::assertCount(2, $tags);
        self::assertContains(['prepareFormData', 'onPrepareFormData'], $hooks);
        self::assertContains(['addComment', 'onAddComment'], $hooks);
    }

    public function testSubmissionListenerIsPublicAndAutowiresSpamClassifier(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        $listener = $container->get(SubmissionListener::class);
        self::assertInstanceOf(SubmissionListener::class, $listener);
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', 'test-secret');

        $container->register('mailer.mailer', StubMailer::class)->setPublic(true);
        $container->set('request_stack', new RequestStack());
        $container->set('monolog.logger.contao', new NullLogger());
        $container->set('cache.app', new ArrayAdapter());
        $container->set('http_client', new MockHttpClient());
        $container->set('contao.framework', $this->createStub(ContaoFramework::class));
        $container->set('database_connection', $this->createStub(Connection::class));
        $container->set('mailer.transports', $this->createStub(TransportInterface::class));
        $container->set('router', $this->createStub(RouterInterface::class));
        $container->set('contao.csrf.token_manager', $this->createStub(ContaoCsrfTokenManager::class));
        $container->set('security.token_storage', $this->createStub(TokenStorageInterface::class));
        $container->setAlias('mailer', 'mailer.mailer');

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');

        return $container;
    }
}

/**
 * Zählt zugestellte Nachrichten, um die Dekorationskette funktional zu belegen.
 */
class StubMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent[] = $message;
    }
}

/**
 * Steht für Contaos contao.mailer, der mailer.mailer ohne Priorität dekoriert.
 */
class StubDecorator implements MailerInterface
{
    public function __construct(public readonly MailerInterface $inner)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->inner->send($message, $envelope);
    }
}

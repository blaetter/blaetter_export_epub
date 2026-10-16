<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Controller;

use Drupal\blaetter_export_epub\Controller\DownloadController;
use Drupal\blaetter_export_epub\EpubCredentials;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\nodeshop\NodeShop;
use Drupal\Tests\blaetter_export_epub\Unit\EpubTestTrait;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the access check and the download of the watermarked ePubs.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(DownloadController::class)]
#[Group('blaetter_export_epub')]
class DownloadControllerTest extends UnitTestCase
{
    use EpubTestTrait;

    private const NODE_ID = 29381;

    /**
     * The loadable nodes, keyed by id.
     *
     * @var array
     */
    private $nodes = [];

    /**
     * The loadable users, keyed by id.
     *
     * @var array
     */
    private $users = [];

    /**
     * The requests sent to pepgen as [method, url, options].
     *
     * @var array
     */
    private $requests = [];

    /**
     * The responses of pepgen (or exceptions), in the order of the requests.
     *
     * @var array
     */
    private $responses = [];

    /**
     * The error messages shown to the user.
     *
     * @var array
     */
    private $errors = [];

    /**
     * The logged errors.
     *
     * @var array
     */
    private $logged_errors = [];

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = new ContainerBuilder();
        $container->set('config.factory', $this->getConfigFactoryStub([
            'blaetter_export_epub.settings' => [
                'pepgen' => [
                    'salt' => 'salt',
                    'base_url' => 'https://pepgen.example.com',
                    'download_url' => 'https://pepgen.example.com/download',
                ],
            ],
            'nodeshop.settings' => [
                'products' => ['type' => ['story' => ['access_field' => 'field_freigabe']]],
            ],
        ]));

        $storages = [];
        foreach (['node' => 'nodes', 'user' => 'users'] as $entity_type => $property) {
            $storage = $this->createMock(EntityStorageInterface::class);
            $storage->method('load')->willReturnCallback(fn ($id) => $this->{$property}[$id] ?? null);
            $storages[$entity_type] = $storage;
        }
        $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
        $entity_type_manager->method('getStorage')->willReturnCallback(fn ($entity_type) => $storages[$entity_type]);
        $container->set('entity_type.manager', $entity_type_manager);

        // The current user is the customer with the id 15.
        $container->set('current_user', $this->createAccount(15));

        $messenger = $this->createMock(MessengerInterface::class);
        $messenger->method('addError')->willReturnCallback(function ($message) use ($messenger) {
            $this->errors[] = (string) $message;
            return $messenger;
        });
        $container->set('messenger', $messenger);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message, $context = []) {
            $this->logged_errors[] = strtr($message, $context);
        });
        $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
        $logger_factory->method('get')->willReturn($logger);
        $container->set('logger.factory', $logger_factory);

        $url_generator = $this->createMock(UrlGeneratorInterface::class);
        $url_generator->method('generateFromRoute')->willReturnCallback(
            fn ($route, $parameters) => '/node/' . $parameters['node']
        );
        $container->set('url_generator', $url_generator);
        $container->set('string_translation', $this->getStringTranslationStub());
        \Drupal::setContainer($container);

        // NodeShop caches the config statically, so reset it to use the config of this test.
        (new \ReflectionProperty(NodeShop::class, 'config'))->setValue(null, null);

        $this->users = [
            0 => $this->createUserMock('', '', ''),
            15 => $this->createUserMock('Erika', 'Muster', 'erika@example.com'),
        ];
        foreach ($this->users as $uid => $user) {
            $user->method('id')->willReturn($uid);
        }
    }

    /**
     * Creates an account with the given id.
     */
    private function createAccount(int $uid): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($uid);
        return $account;
    }

    /**
     * Creates a free story, only the given user may view it.
     */
    private function createStory(int $viewer_uid): NodeInterface
    {
        $node = $this->createNodeMock(self::NODE_ID);
        $node->method('getType')->willReturn('story');
        $node->method('hasField')->willReturnCallback(fn ($field) => 'field_freigabe' === $field);
        $node->method('get')->willReturn(new class {
            public function getString()
            {
                return '1';
            }
        });
        $node->method('access')->willReturnCallback(
            fn ($operation, $account = null) => 'view' === $operation && $viewer_uid === (int) $account->id()
        );
        return $node;
    }

    /**
     * Creates the controller with a http client, that answers with the prepared responses.
     */
    private function createController(): DownloadController
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(function ($method, $url, $options = []) {
            $this->requests[] = [$method, $url, $options];
            $response = array_shift($this->responses);
            if ($response instanceof \Exception) {
                throw $response;
            }
            return $response;
        });
        return new DownloadController($client);
    }

    /**
     * Returns the content of a streamed response.
     */
    private function getStreamedContent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();
        return ob_get_clean();
    }

    /**
     * Tests, that missing, too old and unknown nodes are not found.
     */
    public function testAccessNotFound(): void
    {
        foreach ([null, '', 100, 99999] as $node_id) {
            try {
                $this->createController()->access($this->createAccount(15), $node_id);
                $this->fail('No 404 for node ' . var_export($node_id, true));
            } catch (NotFoundHttpException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Tests, that the access is checked for the given account and not for the current user.
     */
    public function testAccessChecksGivenAccount(): void
    {
        $this->nodes[self::NODE_ID] = $this->createStory(15);
        $controller = $this->createController();

        $customer = $controller->access($this->createAccount(15), (string) self::NODE_ID);
        $anonymous = $controller->access($this->createAccount(0), (string) self::NODE_ID);

        $this->assertTrue($customer->isAllowed());
        $this->assertSame(0, $customer->getCacheMaxAge());
        $this->assertFalse($anonymous->isAllowed());
        $this->assertSame(0, $anonymous->getCacheMaxAge());
    }

    /**
     * Tests, that an account without user entity gets no access.
     */
    public function testAccessForbiddenForUnknownUser(): void
    {
        $this->nodes[self::NODE_ID] = $this->createStory(15);

        $result = $this->createController()->access($this->createAccount(4711), self::NODE_ID);

        $this->assertTrue($result->isForbidden());
    }

    /**
     * Tests, that the ePub is generated by pepgen and passed through to the client.
     */
    public function testDownloadEpub(): void
    {
        $node = $this->createStory(15);
        $epub = DownloadController::EPUB_SIGNATURE . str_repeat('x', 3 * DownloadController::CHUNK_SIZE);
        $this->responses = [
            new Response(200, [], '"Success"'),
            new Response(200, ['Content-Length' => (string) strlen($epub)], $epub),
        ];

        $response = $this->createController()->downloadEpub($node);

        $credentials = new EpubCredentials($node, $this->users[15], 'salt');
        $this->assertSame(
            [
                [
                    'POST',
                    'https://pepgen.example.com/',
                    [
                        'headers' => ['Content-type' => 'application/x-www-form-urlencoded'],
                        'form_params' => [
                            'watermark' => urlencode('Erika Muster, E-Mail: erika@example.com'),
                            'id' => self::NODE_ID,
                            'token' => $credentials->getToken(),
                        ],
                    ],
                ],
                [
                    'GET',
                    'https://pepgen.example.com/download/' . $credentials->getToken() . '.' . self::NODE_ID . '.epub',
                    ['stream' => true],
                ],
            ],
            $this->requests
        );
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/epub+zip', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename=Ebook_Blaetter.' . self::NODE_ID . '.epub',
            $response->headers->get('Content-Disposition')
        );
        $this->assertSame((string) strlen($epub), $response->headers->get('Content-Length'));
        $this->assertSame($epub, $this->getStreamedContent($response));
        $this->assertSame([], $this->errors);
        $this->assertSame([], $this->logged_errors);
    }

    /**
     * Tests, that the user is lead back to the node, if pepgen could not be reached.
     */
    public function testDownloadEpubPepgenNotReachable(): void
    {
        $this->responses = [new ConnectException('Connection refused', new Request('POST', '/'))];

        $response = $this->createController()->downloadEpub($this->createStory(15));

        $this->assertRedirectToNodeWithError($response, 'There was an error generating your epub. Please try again.');
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('Connection refused', $this->logged_errors[0]);
    }

    /**
     * Tests, that the error of pepgen is shown to the user and the ePub is not fetched.
     */
    public function testDownloadEpubPepgenError(): void
    {
        $this->responses = [new Response(200, [], '"Invalid token"')];

        $response = $this->createController()->downloadEpub($this->createStory(15));

        $this->assertRedirectToNodeWithError(
            $response,
            'Your epub could not be downloaded, please contact us and provide the following error message: ' .
            '<em class="placeholder">Invalid token</em>'
        );
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('Invalid token', $this->logged_errors[0]);
    }

    /**
     * Tests, that no broken file is sent, if the ePub can not be fetched from pepgen.
     */
    public function testDownloadEpubFileMissingOrBroken(): void
    {
        $cases = [
            'not found' => [new Response(404, [], 'Not found'), 'status 404'],
            'empty' => [new Response(200, [], ''), '0 bytes'],
            'html instead of epub' => [new Response(200, [], '<html>Error</html>'), '18 bytes'],
            'connection lost' => [new ConnectException('Timeout', new Request('GET', '/')), 'Timeout'],
        ];
        foreach ($cases as $case => [$download, $logged]) {
            $this->requests = $this->errors = $this->logged_errors = [];
            $this->responses = [new Response(200, [], '"Success"'), $download];

            $response = $this->createController()->downloadEpub($this->createStory(15));

            $this->assertRedirectToNodeWithError(
                $response,
                'There was an error generating your epub. Please try again.',
                $case
            );
            $this->assertCount(2, $this->requests, $case);
            $this->assertStringContainsString($logged, $this->logged_errors[0], $case);
        }
    }

    /**
     * Asserts, that the user is lead back to the node with the given error message.
     */
    private function assertRedirectToNodeWithError($response, string $error, string $message = ''): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $response, $message);
        $this->assertSame('/node/' . self::NODE_ID, $response->getTargetUrl(), $message);
        $this->assertSame([$error], $this->errors, $message);
        $this->assertCount(1, $this->logged_errors, $message);
    }
}

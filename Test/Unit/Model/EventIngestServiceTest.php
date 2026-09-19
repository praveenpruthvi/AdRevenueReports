<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model;

use Aavirbhava\AdsAnalytics\Model\Config\IngestConfig;
use Aavirbhava\AdsAnalytics\Model\Data\Event;
use Aavirbhava\AdsAnalytics\Model\EventIngestService;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Header as HttpHeader;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * docs/TESTING.md §1/§2, docs/SECURITY.md §3/§4.
 *
 * The identity tests here are the important ones: this class is the only
 * thing standing between a client-supplied ip_hash/user_agent and the request
 * log.
 */
class EventIngestServiceTest extends TestCase
{
    private const TOPIC = 'aavirbhava.adsanalytics.event';

    /** @var PublisherInterface&MockObject */
    private $publisher;
    /** @var RemoteAddress&MockObject */
    private $remoteAddress;
    /** @var HttpHeader&MockObject */
    private $httpHeader;
    /** @var EncryptorInterface&MockObject */
    private $encryptor;
    /** @var CacheInterface&MockObject */
    private $cache;
    /** @var IngestConfig&MockObject */
    private $config;
    /** @var LoggerInterface&MockObject */
    private $logger;

    private EventIngestService $service;

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->httpHeader = $this->createMock(HttpHeader::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->config = $this->createMock(IngestConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Permissive defaults; individual tests tighten what they care about.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSamplingRate')->willReturn(100);
        $this->config->method('getRateLimitMaxRequests')->willReturn(120);
        $this->config->method('getRateLimitWindowSeconds')->willReturn(60);
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.9');
        $this->httpHeader->method('getHttpUserAgent')->willReturn('RealAgent/1.0');
        $this->encryptor->method('hash')->willReturnCallback(static fn ($v) => 'hashed:' . $v);
        $this->cache->method('load')->willReturn('0');

        $this->service = new EventIngestService(
            $this->publisher,
            $this->remoteAddress,
            $this->httpHeader,
            $this->encryptor,
            $this->cache,
            $this->config,
            $this->logger
        );
    }

    private function makeEvent(array $data = []): Event
    {
        $event = new Event();
        $event->setVisitorUuid($data['uuid'] ?? 'visitor-1');
        $event->setEventType('landing');
        if (isset($data['ip_hash'])) {
            $event->setIpHash($data['ip_hash']);
        }
        if (isset($data['user_agent'])) {
            $event->setUserAgent($data['user_agent']);
        }

        return $event;
    }

    public function testPublishesWhenEnabled(): void
    {
        $this->publisher->expects($this->once())->method('publish')
            ->with(self::TOPIC, $this->isInstanceOf(Event::class));

        $this->service->ingest($this->makeEvent());
    }

    public function testKillSwitchPreventsPublish(): void
    {
        $config = $this->createMock(IngestConfig::class);
        $config->method('isEnabled')->willReturn(false);

        $this->publisher->expects($this->never())->method('publish');

        $this->serviceWith($config)->ingest($this->makeEvent());
    }

    /**
     * docs/SECURITY.md §3. A client may put anything in these fields; the
     * server must overwrite both before the message leaves this method.
     */
    public function testClientSuppliedIpHashAndUserAgentAreOverwritten(): void
    {
        $event = $this->makeEvent(['ip_hash' => 'CLIENT-FORGED', 'user_agent' => 'CLIENT-FORGED']);

        $this->publisher->expects($this->once())->method('publish')
            ->willReturnCallback(function (string $topic, Event $published) {
                $this->assertSame('hashed:203.0.113.9', $published->getIpHash());
                $this->assertSame('RealAgent/1.0', $published->getUserAgent());
                return null;
            });

        $this->service->ingest($event);
    }

    /** The raw IP must never be what gets stored. */
    public function testIpIsHashedNotStoredRaw(): void
    {
        $this->publisher->method('publish')->willReturnCallback(function (string $t, Event $e) {
            $this->assertStringNotContainsString('203.0.113.9', (string)$e->getIpHash());
            return null;
        });

        $this->service->ingest($this->makeEvent());
    }

    public function testUserAgentIsTruncatedToColumnWidth(): void
    {
        $httpHeader = $this->createMock(HttpHeader::class);
        $httpHeader->method('getHttpUserAgent')->willReturn(str_repeat('U', 400));

        $this->publisher->method('publish')->willReturnCallback(function (string $t, Event $e) {
            $this->assertSame(255, mb_strlen((string)$e->getUserAgent()));
            return null;
        });

        $this->serviceWith(null, $httpHeader)->ingest($this->makeEvent());
    }

    public function testMissingRemoteAddressStillPublishesWithNullIpHash(): void
    {
        $remote = $this->createMock(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn(false);

        $this->publisher->expects($this->once())->method('publish')
            ->willReturnCallback(function (string $t, Event $e) {
                $this->assertNull($e->getIpHash());
                return null;
            });

        $this->serviceWith(null, null, $remote)->ingest($this->makeEvent());
    }

    public function testRateLimitedRequestIsNotPublished(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('120');

        $this->publisher->expects($this->never())->method('publish');

        $this->serviceWith(null, null, null, $cache)->ingest($this->makeEvent());
    }

    public function testCounterIsIncrementedWithConfiguredWindow(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('4');
        $cache->expects($this->once())->method('save')
            ->with('5', $this->isType('string'), [], 60);

        $this->serviceWith(null, null, null, $cache)->ingest($this->makeEvent());
    }

    /**
     * A broker outage must log and return, never propagate — the beacon fires
     * on every storefront page (CLAUDE.md #3).
     */
    public function testPublishFailureIsSwallowedAndLogged(): void
    {
        $this->publisher->method('publish')->willThrowException(new \RuntimeException('broker down'));
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('failed to publish'));

        $this->service->ingest($this->makeEvent());
        $this->addToAssertionCount(1); // reaching here means nothing propagated
    }

    /**
     * Sampling must be stable per visitor: sampling each event independently
     * would keep a visitor's add_to_cart but drop their landing, leaving a
     * funnel that can never be reconstructed.
     */
    public function testSamplingIsDeterministicPerVisitor(): void
    {
        $config = $this->createMock(IngestConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getSamplingRate')->willReturn(50);
        $config->method('getRateLimitMaxRequests')->willReturn(120);
        $config->method('getRateLimitWindowSeconds')->willReturn(60);

        $published = 0;
        $this->publisher->method('publish')->willReturnCallback(static function () use (&$published) {
            $published++;
            return null;
        });

        $service = $this->serviceWith($config);
        for ($i = 0; $i < 10; $i++) {
            $service->ingest($this->makeEvent(['uuid' => 'stable-visitor']));
        }

        $this->assertContains($published, [0, 10], 'one visitor must be entirely in or entirely out of the sample');
    }

    public function testSamplingAtFullRateAlwaysPublishes(): void
    {
        $this->publisher->expects($this->once())->method('publish');
        $this->service->ingest($this->makeEvent(['uuid' => 'any-visitor-at-all']));
    }

    private function serviceWith(
        ?IngestConfig $config = null,
        ?HttpHeader $httpHeader = null,
        ?RemoteAddress $remoteAddress = null,
        ?CacheInterface $cache = null
    ): EventIngestService {
        return new EventIngestService(
            $this->publisher,
            $remoteAddress ?? $this->remoteAddress,
            $httpHeader ?? $this->httpHeader,
            $this->encryptor,
            $cache ?? $this->cache,
            $config ?? $this->config,
            $this->logger
        );
    }
}

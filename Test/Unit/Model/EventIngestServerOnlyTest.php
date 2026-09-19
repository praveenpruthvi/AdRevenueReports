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
 * docs/SECURITY.md §3 — server-only event types.
 *
 * Without this gate a caller could POST
 * {"event_type":"order_placed","entity_id":<someone else's order>} to the
 * public endpoint and attribute that order, and its revenue, to their visit.
 */
class EventIngestServerOnlyTest extends TestCase
{
    /** @var PublisherInterface&MockObject */ private $publisher;
    private EventIngestService $service;

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(PublisherInterface::class);

        $config = $this->createMock(IngestConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getSamplingRate')->willReturn(100);
        $config->method('getRateLimitMaxRequests')->willReturn(120);
        $config->method('getRateLimitWindowSeconds')->willReturn(60);

        $remote = $this->createMock(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('203.0.113.9');
        $header = $this->createMock(HttpHeader::class);
        $header->method('getHttpUserAgent')->willReturn('UA/1.0');
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturn('hashed');
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('0');

        $this->service = new EventIngestService(
            $this->publisher, $remote, $header, $encryptor, $cache, $config,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function event(string $type): Event
    {
        return (new Event())->setVisitorUuid('v1')->setEventType($type);
    }

    /**
     * @dataProvider serverOnlyProvider
     */
    public function testPublicEndpointDropsServerOnlyEventTypes(string $type): void
    {
        $this->publisher->expects($this->never())->method('publish');

        $this->service->ingest($this->event($type));
    }

    public function serverOnlyProvider(): array
    {
        return [['order_placed'], ['add_to_cart']];
    }

    /**
     * @dataProvider clientAllowedProvider
     */
    public function testPublicEndpointStillAcceptsClientEventTypes(string $type): void
    {
        $this->publisher->expects($this->once())->method('publish');

        $this->service->ingest($this->event($type));
    }

    public function clientAllowedProvider(): array
    {
        return [['landing'], ['product_view'], ['checkout_start'], ['checkout_step_shipping']];
    }

    /** The module's own observers reach the queue via the trusted entry point. */
    public function testTrustedPathAcceptsServerOnlyEventTypes(): void
    {
        $this->publisher->expects($this->once())->method('publish');

        $this->service->ingestFromServer($this->event('order_placed'));
    }
}

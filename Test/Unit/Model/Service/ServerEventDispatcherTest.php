<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Service;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterfaceFactory;
use Aavirbhava\AdsAnalytics\Model\Data\Event;
use Aavirbhava\AdsAnalytics\Model\EventIngestService;
use Aavirbhava\AdsAnalytics\Model\Service\ServerEventDispatcher;
use Aavirbhava\AdsAnalytics\Model\Service\VisitorCookie;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** P2-T2 / P2-T4. */
class ServerEventDispatcherTest extends TestCase
{
    /** @var EventIngestService&MockObject */ private $ingest;
    /** @var VisitorCookie&MockObject */ private $cookie;
    /** @var LoggerInterface&MockObject */ private $logger;
    private ServerEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->ingest = $this->createMock(EventIngestService::class);
        $this->cookie = $this->createMock(VisitorCookie::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $factory = $this->createMock(EventInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new Event());

        $this->dispatcher = new ServerEventDispatcher($this->ingest, $factory, $this->cookie, $this->logger);
    }

    public function testDispatchesThroughTheTrustedIngestPath(): void
    {
        $this->cookie->method('getVisitorUuid')->willReturn('v1');
        $this->ingest->expects($this->once())->method('ingestFromServer')
            ->willReturnCallback(function (Event $e) {
                $this->assertSame('add_to_cart', $e->getEventType());
                $this->assertSame('v1', $e->getVisitorUuid());
                $this->assertSame(42, $e->getEntityId());
            });

        $this->assertTrue($this->dispatcher->dispatch('add_to_cart', 42));
    }

    /**
     * No cookie means the beacon never ran for this visitor. Minting an id
     * here would invent a visit with no landing behind it and manufacture
     * unattributable traffic on every admin or API order.
     */
    public function testNoVisitorCookieDropsTheEventWithoutMintingAnId(): void
    {
        $this->cookie->method('getVisitorUuid')->willReturn(null);
        $this->ingest->expects($this->never())->method('ingestFromServer');

        $this->assertFalse($this->dispatcher->dispatch('order_placed', 7));
    }

    /**
     * An observer runs inside the customer's request — a tracking failure
     * must never surface as a broken add-to-cart or a failed order.
     */
    public function testIngestFailureIsContainedNotRethrown(): void
    {
        $this->cookie->method('getVisitorUuid')->willReturn('v1');
        $this->ingest->method('ingestFromServer')->willThrowException(new \RuntimeException('broker down'));
        $this->logger->expects($this->once())->method('error');

        $this->assertFalse($this->dispatcher->dispatch('order_placed', 7));
    }

    public function testEntityIdIsOptional(): void
    {
        $this->cookie->method('getVisitorUuid')->willReturn('v1');
        $this->ingest->expects($this->once())->method('ingestFromServer')
            ->willReturnCallback(function (Event $e) {
                $this->assertNull($e->getEntityId());
            });

        $this->assertTrue($this->dispatcher->dispatch('checkout_start'));
    }
}

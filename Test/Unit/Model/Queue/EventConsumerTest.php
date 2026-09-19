<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Queue;

use Aavirbhava\AdsAnalytics\Model\Data\Event;
use Aavirbhava\AdsAnalytics\Model\Queue\EventConsumer;
use Aavirbhava\AdsAnalytics\Model\Service\EventValidator;
use Aavirbhava\AdsAnalytics\Model\Service\RequestLogWriter;
use Aavirbhava\AdsAnalytics\Model\Service\TrafficResolver;
use Aavirbhava\AdsAnalytics\Model\Service\VisitManager;
use Aavirbhava\AdsAnalytics\Model\Visit;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** P1-T6 orchestration: log -> validate -> classify -> persist. */
class EventConsumerTest extends TestCase
{
    /** @var RequestLogWriter&MockObject */ private $logWriter;
    /** @var EventValidator&MockObject */ private $validator;
    /** @var TrafficResolver&MockObject */ private $resolver;
    /** @var VisitManager&MockObject */ private $visitManager;
    /** @var LoggerInterface&MockObject */ private $logger;
    private EventConsumer $consumer;

    protected function setUp(): void
    {
        $this->logWriter = $this->createMock(RequestLogWriter::class);
        $this->validator = $this->createMock(EventValidator::class);
        $this->resolver = $this->createMock(TrafficResolver::class);
        $this->visitManager = $this->createMock(VisitManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resolver->method('resolve')->willReturn([
            'traffic_type' => 'paid', 'platform_code' => 'google', 'click_id_param' => 'gclid',
            'click_id_value' => 'G1', 'source' => 'google', 'medium' => 'cpc', 'campaign' => null,
        ]);

        $this->consumer = new EventConsumer(
            $this->logWriter, $this->validator, $this->resolver, $this->visitManager, $this->logger
        );
    }

    private function event(string $type = 'landing'): Event
    {
        $e = new Event();
        $e->setVisitorUuid('v1')->setEventType($type);
        return $e;
    }

    public function testLandingUpsertsVisitAndMarksAccepted(): void
    {
        $this->logWriter->method('logPending')->willReturn(11);
        $this->validator->method('validate')->willReturn(null);
        $this->validator->method('isLanding')->willReturn(true);

        $this->visitManager->expects($this->once())->method('upsertLanding');
        $this->visitManager->expects($this->never())->method('recordFunnelEvent');
        $this->logWriter->expects($this->once())->method('markAccepted')->with(11);

        $this->consumer->process($this->event());
    }

    public function testNonLandingResolvesVisitAndRecordsFunnelEvent(): void
    {
        $this->logWriter->method('logPending')->willReturn(12);
        $this->validator->method('validate')->willReturn(null);
        $this->validator->method('isLanding')->willReturn(false);

        $visit = $this->createMock(Visit::class);
        $this->visitManager->expects($this->once())->method('resolveOrCreateVisit')->with('v1')->willReturn($visit);
        $this->visitManager->expects($this->once())->method('recordFunnelEvent')->with($visit);
        $this->visitManager->expects($this->never())->method('upsertLanding');

        $this->consumer->process($this->event('add_to_cart'));
    }

    /** A rejected event must not reach the analytics tables at all. */
    public function testRejectedEventPersistsNothing(): void
    {
        $this->logWriter->method('logPending')->willReturn(13);
        $this->validator->method('validate')->willReturn('unknown event_type');

        $this->logWriter->expects($this->once())->method('markRejected')->with(13, 'unknown event_type');
        $this->logWriter->expects($this->never())->method('markAccepted');
        $this->visitManager->expects($this->never())->method('upsertLanding');
        $this->visitManager->expects($this->never())->method('recordFunnelEvent');

        $this->consumer->process($this->event());
    }

    /**
     * A message that throws must NOT be rethrown: the broker would redeliver
     * it for ever, blocking the queue behind a deterministically-failing
     * payload.
     */
    public function testPersistFailureIsContainedNotRethrown(): void
    {
        $this->logWriter->method('logPending')->willReturn(14);
        $this->validator->method('validate')->willReturn(null);
        $this->validator->method('isLanding')->willReturn(true);
        $this->visitManager->method('upsertLanding')->willThrowException(new \RuntimeException('db gone'));

        $this->logWriter->expects($this->once())->method('markRejected')
            ->with(14, $this->stringContains('consumer error'));
        $this->logger->expects($this->once())->method('error');

        $this->consumer->process($this->event());
        $this->addToAssertionCount(1); // reaching here means nothing propagated
    }

    /** Logging being off (logPending -> null) must not stop processing. */
    public function testProcessingContinuesWhenLoggingIsOff(): void
    {
        $this->logWriter->method('logPending')->willReturn(null);
        $this->validator->method('validate')->willReturn(null);
        $this->validator->method('isLanding')->willReturn(true);

        $this->visitManager->expects($this->once())->method('upsertLanding');

        $this->consumer->process($this->event());
    }

    /**
     * The resolver must receive the click-id re-keyed onto the query array by
     * its param NAME — that is the shape TrafficResolver expects, and the
     * wire format carries name and value as separate fields.
     */
    public function testClickIdIsRekeyedOntoQueryParamsForResolver(): void
    {
        $this->logWriter->method('logPending')->willReturn(null);
        $this->validator->method('validate')->willReturn(null);
        $this->validator->method('isLanding')->willReturn(true);

        $resolver = $this->createMock(TrafficResolver::class);
        $resolver->expects($this->once())->method('resolve')
            ->with(
                $this->callback(static fn (array $q): bool => ($q['gclid'] ?? null) === 'G1'),
                'https://ref.example/'
            )
            ->willReturn([
                'traffic_type' => 'paid', 'platform_code' => 'google', 'click_id_param' => 'gclid',
                'click_id_value' => 'G1', 'source' => 'google', 'medium' => 'cpc', 'campaign' => null,
            ]);

        $event = $this->event();
        $event->setClickIdParam('gclid')->setClickIdValue('G1')->setReferrer('https://ref.example/');

        (new EventConsumer($this->logWriter, $this->validator, $resolver, $this->visitManager, $this->logger))
            ->process($event);
    }
}

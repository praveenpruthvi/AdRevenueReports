<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Integration\Model;

use Aavirbhava\AdsAnalytics\Model\Data\Event;
use Aavirbhava\AdsAnalytics\Model\Queue\EventConsumer;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * docs/TESTING.md §2 — the consumer against a real database, with real DI
 * and the real config (config.xml defaults, since no core_config_data rows
 * exist in a fresh integration install).
 *
 * Unit tests mock TrafficClassificationConfig; these prove the shipped
 * defaults actually classify correctly once Magento loads them for real.
 */
class EventConsumerTest extends TestCase
{
    /**
     * Untyped on purpose: Magento's integration framework nulls out test
     * properties between tests to reclaim memory, which is a fatal error on a
     * non-nullable typed property.
     *
     * @var EventConsumer
     */
    private $consumer;

    /** @var ResourceConnection */
    private $resource;

    protected function setUp(): void
    {
        $om = Bootstrap::getObjectManager();
        $this->consumer = $om->create(EventConsumer::class);
        $this->resource = $om->get(ResourceConnection::class);
        $this->truncate();
    }

    protected function tearDown(): void
    {
        $this->truncate();
    }

    private function truncate(): void
    {
        $c = $this->resource->getConnection();
        // funnel_event first: it has an FK onto visit.
        foreach (['ads_analytics_funnel_event', 'ads_analytics_visit', 'ads_analytics_request_log'] as $t) {
            $c->delete($this->resource->getTableName($t));
        }
    }

    private function event(array $data): Event
    {
        $event = Bootstrap::getObjectManager()->create(Event::class);
        $event->setVisitorUuid($data['uuid'])->setEventType($data['type']);
        foreach (['click_id_param' => 'setClickIdParam', 'click_id_value' => 'setClickIdValue',
                  'utm_source' => 'setUtmSource', 'utm_medium' => 'setUtmMedium',
                  'utm_campaign' => 'setUtmCampaign', 'referrer' => 'setReferrer',
                  'entity_id' => 'setEntityId'] as $k => $setter) {
            if (array_key_exists($k, $data)) {
                $event->{$setter}($data[$k]);
            }
        }
        return $event;
    }

    private function visit(string $uuid): ?array
    {
        $c = $this->resource->getConnection();
        $row = $c->fetchRow(
            $c->select()->from($this->resource->getTableName('ads_analytics_visit'))
                ->where('visitor_uuid = ?', $uuid)
        );
        return $row ?: null;
    }

    private function countRows(string $table): int
    {
        $c = $this->resource->getConnection();
        return (int)$c->fetchOne($c->select()->from($this->resource->getTableName($table), 'COUNT(*)'));
    }

    /** Shipped config.xml defaults must classify a real gclid as paid google. */
    public function testPaidClickIdIsClassifiedFromShippedDefaults(): void
    {
        $this->consumer->process($this->event([
            'uuid' => 'it-paid', 'type' => 'landing',
            'click_id_param' => 'gclid', 'click_id_value' => 'G1', 'utm_campaign' => 'spring',
        ]));

        $visit = $this->visit('it-paid');
        $this->assertSame('paid', $visit['traffic_type']);
        $this->assertSame('google', $visit['platform_code']);
        $this->assertSame('cpc', $visit['last_touch_medium']);
        $this->assertSame('spring', $visit['last_touch_campaign']);
    }

    /** The alt_sources column, end to end through real config. */
    public function testInstagramDisambiguationKeepsPlatformCodeStable(): void
    {
        $this->consumer->process($this->event([
            'uuid' => 'it-ig', 'type' => 'landing',
            'click_id_param' => 'fbclid', 'click_id_value' => 'F1', 'utm_source' => 'instagram',
        ]));

        $visit = $this->visit('it-ig');
        $this->assertSame('meta', $visit['platform_code'], 'platform_code must stay the map key');
        $this->assertSame('instagram', $visit['last_touch_source']);
    }

    public function testOrganicReferrerIsClassifiedFromShippedDefaults(): void
    {
        $this->consumer->process($this->event([
            'uuid' => 'it-organic', 'type' => 'landing',
            'referrer' => 'https://www.google.com/search?q=shoes',
        ]));

        $visit = $this->visit('it-organic');
        $this->assertSame('organic', $visit['traffic_type']);
        $this->assertNull($visit['platform_code']);
        $this->assertSame('google', $visit['last_touch_source']);
    }

    public function testDirectTrafficIsClassified(): void
    {
        $this->consumer->process($this->event(['uuid' => 'it-direct', 'type' => 'landing']));

        $visit = $this->visit('it-direct');
        $this->assertSame('direct', $visit['traffic_type']);
        $this->assertSame('none', $visit['last_touch_medium']);
    }

    /**
     * The out-of-order branch: a funnel event with no prior landing must
     * create a placeholder rather than fail the NOT NULL FK.
     */
    public function testFunnelEventWithoutLandingCreatesPlaceholderVisit(): void
    {
        $this->consumer->process($this->event([
            'uuid' => 'it-orphan', 'type' => 'order_placed', 'entity_id' => 7,
        ]));

        $visit = $this->visit('it-orphan');
        $this->assertNotNull($visit, 'a placeholder visit must be created');
        $this->assertSame('unknown', $visit['traffic_type']);
        $this->assertSame(1, $this->countRows('ads_analytics_funnel_event'));
    }

    /** A later landing must BACKFILL that placeholder, not duplicate it. */
    public function testLateLandingBackfillsPlaceholderWithoutDuplicating(): void
    {
        $this->consumer->process($this->event(['uuid' => 'it-back', 'type' => 'add_to_cart', 'entity_id' => 1]));
        $this->consumer->process($this->event([
            'uuid' => 'it-back', 'type' => 'landing',
            'click_id_param' => 'gclid', 'click_id_value' => 'G9', 'utm_campaign' => 'late',
        ]));

        $this->assertSame(1, $this->countRows('ads_analytics_visit'), 'must backfill, not insert a second visit');
        $visit = $this->visit('it-back');
        $this->assertSame('paid', $visit['traffic_type']);
        $this->assertSame('google', $visit['first_touch_source'], 'first_touch must be backfilled');
    }

    /** first_touch is written once and never overwritten; last_touch always moves. */
    public function testFirstTouchIsPreservedAcrossASecondAdClick(): void
    {
        $this->consumer->process($this->event([
            'uuid' => 'it-touch', 'type' => 'landing',
            'click_id_param' => 'gclid', 'click_id_value' => 'G1',
        ]));
        $this->consumer->process($this->event([
            'uuid' => 'it-touch', 'type' => 'landing',
            'click_id_param' => 'fbclid', 'click_id_value' => 'F1',
        ]));

        $visit = $this->visit('it-touch');
        $this->assertSame('google', $visit['first_touch_source'], 'first touch must never be overwritten');
        $this->assertSame('facebook', $visit['last_touch_source']);
        $this->assertSame('meta', $visit['platform_code']);
    }

    /**
     * @dataProvider rejectionProvider
     */
    public function testInvalidEventsAreRejectedAndPersistNothing(array $data, string $expectedReason): void
    {
        $this->consumer->process($this->event($data));

        $this->assertSame(0, $this->countRows('ads_analytics_visit'), 'nothing may reach the analytics tables');
        $this->assertSame(0, $this->countRows('ads_analytics_funnel_event'));

        $c = $this->resource->getConnection();
        $row = $c->fetchRow($c->select()->from($this->resource->getTableName('ads_analytics_request_log')));
        $this->assertSame('rejected', $row['validation_status']);
        $this->assertSame($expectedReason, $row['rejection_reason']);
    }

    public function rejectionProvider(): array
    {
        return [
            'unknown event type' => [['uuid' => 'r1', 'type' => 'bogus_type'], 'unknown event_type'],
            'unknown click id' => [
                ['uuid' => 'r2', 'type' => 'landing', 'click_id_param' => 'evilparam', 'click_id_value' => 'x'],
                'unknown click_id_param',
            ],
            'missing visitor uuid' => [['uuid' => '', 'type' => 'landing'], 'missing visitor_uuid'],
            'oversized campaign' => [
                ['uuid' => 'r4', 'type' => 'landing', 'utm_campaign' => str_repeat('x', 200)],
                'oversized field: utm_campaign',
            ],
        ];
    }

    /**
     * At the default logging level (rejected_only) an ACCEPTED event must
     * leave no log row, or the table grows at the rate of all traffic.
     */
    public function testAcceptedEventLeavesNoRequestLogRowAtDefaultLoggingLevel(): void
    {
        $this->consumer->process($this->event(['uuid' => 'it-clean', 'type' => 'landing']));

        $this->assertSame(1, $this->countRows('ads_analytics_visit'));
        $this->assertSame(0, $this->countRows('ads_analytics_request_log'));
    }
}

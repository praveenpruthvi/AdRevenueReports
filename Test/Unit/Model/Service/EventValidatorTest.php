<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Service;

use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Aavirbhava\AdsAnalytics\Model\Data\Event;
use Aavirbhava\AdsAnalytics\Model\Service\EventValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** docs/SECURITY.md §2, P1-T9. */
class EventValidatorTest extends TestCase
{
    /** @var TrafficClassificationConfig&MockObject */
    private $config;
    private EventValidator $validator;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TrafficClassificationConfig::class);
        $this->config->method('getPlatformMap')->willReturn([
            'google' => ['click_id_param' => 'gclid', 'default_source' => 'google', 'alt_sources' => []],
            'meta' => ['click_id_param' => 'fbclid', 'default_source' => 'facebook', 'alt_sources' => ['instagram']],
        ]);
        $this->validator = new EventValidator($this->config);
    }

    private function event(array $data = []): Event
    {
        $event = new Event();
        $event->setVisitorUuid($data['uuid'] ?? 'visitor-1');
        $event->setEventType($data['type'] ?? 'landing');
        foreach (['click_id_param' => 'setClickIdParam', 'click_id_value' => 'setClickIdValue',
                  'utm_source' => 'setUtmSource', 'utm_medium' => 'setUtmMedium',
                  'utm_campaign' => 'setUtmCampaign', 'landing_page' => 'setLandingPage'] as $k => $setter) {
            if (array_key_exists($k, $data)) {
                $event->{$setter}($data[$k]);
            }
        }

        return $event;
    }

    public function testValidEventPasses(): void
    {
        $this->assertNull($this->validator->validate($this->event([
            'type' => 'landing', 'click_id_param' => 'gclid', 'click_id_value' => 'abc',
        ])));
    }

    /**
     * Found during live security testing: a payload omitting visitor_uuid
     * reached the queue as '' and would have keyed a visit row on the empty
     * string, colliding every anonymous visitor onto one row.
     */
    public function testMissingVisitorUuidIsRejected(): void
    {
        $this->assertSame('missing visitor_uuid', $this->validator->validate($this->event(['uuid' => ''])));
        $this->assertSame('missing visitor_uuid', $this->validator->validate($this->event(['uuid' => '   '])));
    }

    public function testUnknownEventTypeIsRejected(): void
    {
        $this->assertSame('unknown event_type', $this->validator->validate($this->event(['type' => 'totally_bogus'])));
    }

    public function testMissingEventTypeIsRejected(): void
    {
        $this->assertSame('missing event_type', $this->validator->validate($this->event(['type' => ''])));
    }

    /**
     * @dataProvider validEventTypeProvider
     */
    public function testAllSpecifiedEventTypesAreAccepted(string $type): void
    {
        $this->assertNull($this->validator->validate($this->event(['type' => $type])));
    }

    public function validEventTypeProvider(): array
    {
        return array_map(static fn ($t) => [$t], [
            'landing', 'product_view', 'add_to_cart', 'checkout_start',
            'checkout_step_shipping', 'checkout_step_payment', 'checkout_step_review', 'order_placed',
        ]);
    }

    /**
     * click_id_param is the single client-supplied signal that drives paid
     * classification — an unchecked value lets a caller book arbitrary
     * traffic as paid Google.
     */
    public function testUnknownClickIdParamIsRejected(): void
    {
        $this->assertSame(
            'unknown click_id_param',
            $this->validator->validate($this->event(['click_id_param' => 'evilparam', 'click_id_value' => 'x']))
        );
    }

    public function testKnownClickIdParamIsAcceptedCaseInsensitively(): void
    {
        $this->assertNull($this->validator->validate($this->event(['click_id_param' => 'GCLID'])));
    }

    public function testAbsentClickIdParamIsFine(): void
    {
        $this->assertNull($this->validator->validate($this->event(['click_id_param' => null])));
        $this->assertNull($this->validator->validate($this->event(['click_id_param' => ''])));
    }

    /**
     * platform_code is deliberately NOT validated: it is discarded and
     * recomputed, so rejecting over it would lose real analytics data for no
     * security benefit (docs/SECURITY.md §2).
     */
    public function testBogusPlatformCodeDoesNotCauseRejection(): void
    {
        $event = $this->event();
        $event->setPlatformCode('not-a-real-platform');

        $this->assertNull($this->validator->validate($event));
    }

    /**
     * @dataProvider oversizedProvider
     */
    public function testOversizedFieldsAreRejected(string $field, string $setter, int $length): void
    {
        $event = $this->event();
        $event->{$setter}(str_repeat('x', $length));

        $this->assertSame('oversized field: ' . $field, $this->validator->validate($event));
    }

    public function oversizedProvider(): array
    {
        return [
            'click_id_value' => ['click_id_value', 'setClickIdValue', 129],
            'utm_source' => ['utm_source', 'setUtmSource', 65],
            'utm_medium' => ['utm_medium', 'setUtmMedium', 65],
            'utm_campaign' => ['utm_campaign', 'setUtmCampaign', 129],
            'landing_page' => ['landing_page', 'setLandingPage', 256],
        ];
    }

    /** Length limits are in characters, not bytes — multi-byte must not falsely trip them. */
    public function testMultibyteFieldAtExactLimitIsAccepted(): void
    {
        $event = $this->event();
        $event->setUtmCampaign(str_repeat('🎯', 128));

        $this->assertNull($this->validator->validate($event));
    }

    public function testOversizedVisitorUuidIsRejected(): void
    {
        $this->assertSame(
            'oversized field: visitor_uuid',
            $this->validator->validate($this->event(['uuid' => str_repeat('u', 65)]))
        );
    }

    public function testIsLanding(): void
    {
        $this->assertTrue($this->validator->isLanding($this->event(['type' => 'landing'])));
        $this->assertFalse($this->validator->isLanding($this->event(['type' => 'add_to_cart'])));
    }
}

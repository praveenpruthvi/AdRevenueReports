<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Roas;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\CsvAdSpendProvider;
use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Aavirbhava\AdsAnalytics\Model\Roas\RoasReportBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * P4-T5. The behaviour worth pinning is the distinction between the three
 * states a platform's spend can be in — known and positive, known and zero,
 * not known — because collapsing any two of them produces a number that
 * misleads: an unknown spend shown as 0.00 says the ads earned nothing, when
 * the truth is that nobody recorded what they cost.
 */
class RoasReportBuilderTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'group'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $this->connection->method('select')->willReturn($select);
    }

    /**
     * @param array<string, AdSpendProviderInterface> $providers
     */
    private function builder(array $providers): RoasReportBuilder
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-19');

        // Empty platform map: this test class is only exercising which
        // PROVIDERS resolve, not AdSpendProviderPool's separate "fall back
        // to CSV for any admin-configured platform" behaviour, which has its
        // own dedicated test class (AdSpendProviderPoolTest).
        $classificationConfig = $this->createMock(TrafficClassificationConfig::class);
        $classificationConfig->method('getPlatformMap')->willReturn([]);
        $pool = new AdSpendProviderPool($classificationConfig, $this->createMock(CsvAdSpendProvider::class), $providers);

        return new RoasReportBuilder($resource, $pool, $dateTime, $this->logger);
    }

    /** @param array<int, array{date?: string, campaign?: string, spend: float}> $entries */
    private function provider(array $entries): AdSpendProviderInterface
    {
        $provider = $this->createMock(AdSpendProviderInterface::class);
        $provider->method('getSpend')->willReturn(
            array_map(static fn (array $e) => $e + ['date' => '2026-09-19', 'campaign' => 'c'], $entries)
        );

        return $provider;
    }

    /** @param array<string, float> $revenueByPlatform */
    private function revenue(array $revenueByPlatform): void
    {
        $rows = [];
        foreach ($revenueByPlatform as $platform => $revenue) {
            $rows[] = ['platform_code' => $platform, 'revenue' => (string)$revenue];
        }
        $this->connection->method('fetchAll')->willReturn($rows);
    }

    /** @return array<string, array> rows keyed by platform_code */
    private function rowsByPlatform(array $report): array
    {
        $result = [];
        foreach ($report['rows'] as $row) {
            $result[$row['platform_code']] = $row;
        }

        return $result;
    }

    public function testComputesRoasAsRevenueOverSpend(): void
    {
        $this->revenue(['google' => 300.0]);
        $report = $this->builder(['google' => $this->provider([['spend' => 100.0]])])->build('2026-09-01', '2026-09-19');

        $row = $this->rowsByPlatform($report)['google'];
        $this->assertSame(3.0, $row['roas']);
        $this->assertSame(RoasReportBuilder::STATUS_OK, $row['status']);
    }

    public function testSpendIsSummedAcrossEveryProviderRow(): void
    {
        $this->revenue(['google' => 300.0]);
        $provider = $this->provider([['spend' => 40.0], ['spend' => 35.5], ['spend' => 24.5]]);

        $row = $this->rowsByPlatform($this->builder(['google' => $provider])->build('2026-09-01', '2026-09-19'))['google'];

        $this->assertSame(100.0, $row['spend'], 'a platform has many campaigns and days; its spend is all of them');
    }

    /**
     * The whole reason for the three-state design. A platform with revenue
     * but no registered provider has UNKNOWN spend, and showing that as a
     * ROAS of 0.00 would claim its ads earned nothing.
     */
    public function testAPlatformWithNoProviderIsNoDataNotZero(): void
    {
        $this->revenue(['bing' => 117.0]);

        $row = $this->rowsByPlatform($this->builder([])->build('2026-09-01', '2026-09-19'))['bing'];

        $this->assertNull($row['spend']);
        $this->assertNull($row['roas']);
        $this->assertSame(RoasReportBuilder::STATUS_NO_SPEND_DATA, $row['status']);
    }

    public function testAProviderThatReturnsNothingIsNoDataNotZeroSpend(): void
    {
        // A registered provider with no file, or no rows in range, answers
        // with an empty array. That means "we were not told", which is a
        // different fact from "it cost nothing".
        $this->revenue(['google' => 50.0]);

        $row = $this->rowsByPlatform($this->builder(['google' => $this->provider([])])->build('2026-09-01', '2026-09-19'))['google'];

        $this->assertNull($row['spend']);
        $this->assertSame(RoasReportBuilder::STATUS_NO_SPEND_DATA, $row['status']);
    }

    public function testExplicitZeroSpendHasNoRoasRatherThanDividingByZero(): void
    {
        $this->revenue(['google' => 50.0]);

        $row = $this->rowsByPlatform(
            $this->builder(['google' => $this->provider([['spend' => 0.0]])])->build('2026-09-01', '2026-09-19')
        )['google'];

        $this->assertSame(0.0, $row['spend']);
        $this->assertNull($row['roas']);
        $this->assertSame(RoasReportBuilder::STATUS_ZERO_SPEND, $row['status']);
    }

    /**
     * Money spent and none earned is a REAL ROAS of 0.00 — the one result a
     * merchant most needs to see — and it must be distinguishable from the
     * "no data" case above, which is what the whole state model is for.
     */
    public function testSpendWithNoRevenueIsARealZeroRoas(): void
    {
        $this->revenue(['google' => 0.0]);

        $row = $this->rowsByPlatform(
            $this->builder(['google' => $this->provider([['spend' => 80.0]])])->build('2026-09-01', '2026-09-19')
        )['google'];

        $this->assertSame(0.0, $row['roas']);
        $this->assertSame(RoasReportBuilder::STATUS_OK, $row['status']);
    }

    public function testAPlatformThatSpentButDrewNoTrafficStillAppears(): void
    {
        // No summary row at all for meta — only the provider knows about it.
        // Starting from the summary alone would omit exactly this platform.
        $this->revenue(['google' => 100.0]);

        $report = $this->builder([
            'google' => $this->provider([['spend' => 50.0]]),
            'meta' => $this->provider([['spend' => 200.0]]),
        ])->build('2026-09-01', '2026-09-19');

        $rows = $this->rowsByPlatform($report);
        $this->assertArrayHasKey('meta', $rows);
        $this->assertSame(200.0, $rows['meta']['spend']);
        $this->assertSame(0.0, $rows['meta']['roas']);
    }

    public function testAFailingProviderIsLoggedAndTreatedAsNoDataWithoutBlankingTheOthers(): void
    {
        $this->revenue(['google' => 100.0, 'meta' => 60.0]);
        $broken = $this->createMock(AdSpendProviderInterface::class);
        $broken->method('getSpend')->willThrowException(new \RuntimeException('OAuth token expired'));

        $this->logger->expects($this->once())->method('error')->with($this->stringContains('OAuth token expired'));

        $rows = $this->rowsByPlatform($this->builder([
            'google' => $broken,
            'meta' => $this->provider([['spend' => 20.0]]),
        ])->build('2026-09-01', '2026-09-19'));

        $this->assertSame(RoasReportBuilder::STATUS_NO_SPEND_DATA, $rows['google']['status']);
        $this->assertSame(3.0, $rows['meta']['roas'], 'one platform failing must not take the others down with it');
    }

    /**
     * Revenue from a platform whose cost is unknown must stay out of the
     * blended figure. Counting it would divide all attributable revenue by
     * only part of the cost, inflating the headline exactly when the data is
     * least complete.
     */
    public function testBlendedTotalsExcludeRevenueFromPlatformsWithNoSpendData(): void
    {
        $this->revenue(['google' => 300.0, 'bing' => 1000.0]);

        $report = $this->builder(['google' => $this->provider([['spend' => 100.0]])])->build('2026-09-01', '2026-09-19');

        $this->assertSame(100.0, $report['totals']['spend']);
        $this->assertSame(300.0, $report['totals']['revenue'], "bing's 1000 must not be counted against google's spend");
        $this->assertSame(3.0, $report['totals']['roas']);
        $this->assertSame(1, $report['totals']['rows_without_spend']);
    }

    public function testBlendedRoasIsNullWhenNoPlatformHasSpendData(): void
    {
        $this->revenue(['bing' => 117.0]);

        $report = $this->builder([])->build('2026-09-01', '2026-09-19');

        $this->assertNull($report['totals']['roas']);
    }

    public function testUnrecognisedPlatformSentinelGetsAReadableLabel(): void
    {
        $this->revenue(['null_source' => 25.0]);

        $row = $this->rowsByPlatform($this->builder([])->build('2026-09-01', '2026-09-19'))['null_source'];

        $this->assertSame('(no platform recognised)', $row['label']);
    }

    public function testPlatformCodesAreMatchedCaseInsensitively(): void
    {
        $this->revenue(['GOOGLE' => 300.0]);

        $report = $this->builder(['google' => $this->provider([['spend' => 100.0]])])->build('2026-09-01', '2026-09-19');

        $this->assertCount(1, $report['rows'], 'GOOGLE and google are one platform, not two rows');
        $this->assertSame(3.0, $report['rows'][0]['roas']);
    }

    public function testRowsWithSpendComeFirstBiggestSpenderFirst(): void
    {
        $this->revenue(['bing' => 900.0, 'google' => 10.0, 'meta' => 10.0]);

        $report = $this->builder([
            'google' => $this->provider([['spend' => 50.0]]),
            'meta' => $this->provider([['spend' => 200.0]]),
        ])->build('2026-09-01', '2026-09-19');

        $this->assertSame(['meta', 'google', 'bing'], array_column($report['rows'], 'platform_code'));
    }

    public function testDatesDefaultToTodayAndTheEarliestDateWithData(): void
    {
        $this->revenue([]);
        $this->connection->method('fetchOne')->willReturn('2026-08-01');

        $report = $this->builder([])->build();

        $this->assertSame('2026-08-01', $report['from']);
        $this->assertSame('2026-09-19', $report['to']);
    }

    public function testAnEmptySummaryDefaultsFromToToday(): void
    {
        $this->revenue([]);
        $this->connection->method('fetchOne')->willReturn(false);

        $report = $this->builder([])->build();

        $this->assertSame('2026-09-19', $report['from']);
        $this->assertSame([], $report['rows']);
    }
}

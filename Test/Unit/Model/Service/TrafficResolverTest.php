<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Service;

use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Aavirbhava\AdsAnalytics\Model\Service\TrafficResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * docs/TESTING.md §1.
 *
 * Several of these cases exist because they were real bugs found in review,
 * not because they are obvious. Where that is so, the test says which bug it
 * pins down — if one of them starts failing, the regression is a known one.
 */
class TrafficResolverTest extends TestCase
{
    /** @var TrafficClassificationConfig&MockObject */
    private $config;

    private TrafficResolver $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TrafficClassificationConfig::class);

        $this->config->method('getPlatformMap')->willReturn([
            'google' => ['click_id_param' => 'gclid', 'default_source' => 'google', 'alt_sources' => []],
            'meta' => ['click_id_param' => 'fbclid', 'default_source' => 'facebook', 'alt_sources' => ['instagram']],
            'bing' => ['click_id_param' => 'msclkid', 'default_source' => 'bing', 'alt_sources' => []],
        ]);
        $this->config->method('getPaidMediums')->willReturn(['cpc', 'ppc', 'paid', 'paidsocial']);
        $this->config->method('getSearchEngineDomains')->willReturn([
            'google.com' => 'google',
            'bing.com' => 'bing',
            'yandex.ru' => 'yandex',
        ]);

        $this->resolver = new TrafficResolver($this->config);
    }

    /**
     * @dataProvider precedenceProvider
     */
    public function testPrecedence(
        array $queryParams,
        ?string $referrer,
        string $expectedType,
        ?string $expectedPlatform,
        ?string $expectedSource,
        ?string $expectedMedium
    ): void {
        $result = $this->resolver->resolve($queryParams, $referrer);

        $this->assertSame($expectedType, $result['traffic_type']);
        $this->assertSame($expectedPlatform, $result['platform_code']);
        $this->assertSame($expectedSource, $result['source']);
        $this->assertSame($expectedMedium, $result['medium']);
    }

    public function precedenceProvider(): array
    {
        return [
            'paid: click id' => [
                ['gclid' => 'abc'], null, 'paid', 'google', 'google', 'cpc',
            ],
            'paid: click id beats referrer' => [
                ['gclid' => 'abc'], 'https://bing.com/search', 'paid', 'google', 'google', 'cpc',
            ],
            'paid: utm_medium in paid list, no click id' => [
                ['utm_medium' => 'cpc', 'utm_source' => 'newsletter'], null, 'paid', null, 'newsletter', 'cpc',
            ],
            'tagged: utm_source present, not paid' => [
                ['utm_source' => 'newsletter', 'utm_medium' => 'email'], null, 'referral', null, 'newsletter', 'email',
            ],
            'organic: search engine referrer' => [
                [], 'https://www.google.com/search?q=x', 'organic', null, 'google', 'organic',
            ],
            'referral: non-search referrer' => [
                [], 'https://blog.example.com/post', 'referral', null, 'blog.example.com', 'referral',
            ],
            'direct: nothing at all' => [
                [], null, 'direct', null, 'direct', 'none',
            ],
        ];
    }

    /**
     * Regression: resolvePaid() used to hardcode `$platformCode === 'meta'`
     * and `'instagram'`, violating CLAUDE.md #2. Disambiguation is now driven
     * by the platform's alt_sources config column.
     */
    public function testAltSourceOverridesDefaultSourceButNeverPlatformCode(): void
    {
        $result = $this->resolver->resolve(['fbclid' => 'z', 'utm_source' => 'instagram'], null);

        $this->assertSame('instagram', $result['source']);
        $this->assertSame(
            'meta',
            $result['platform_code'],
            'platform_code must stay the config map key so the allow-list stays a closed set'
        );
    }

    public function testAltSourceMatchIsCaseInsensitive(): void
    {
        $result = $this->resolver->resolve(['fbclid' => 'z', 'utm_source' => 'InStaGram'], null);

        $this->assertSame('instagram', $result['source']);
    }

    /**
     * A client must not be able to rewrite `source` to anything it likes just
     * by sending a utm_source alongside a click id — only values the merchant
     * configured as alternatives for that platform may override.
     */
    public function testUnlistedUtmSourceDoesNotOverrideDefaultSource(): void
    {
        $result = $this->resolver->resolve(['fbclid' => 'z', 'utm_source' => 'whatsapp'], null);

        $this->assertSame('facebook', $result['source']);
        $this->assertSame('meta', $result['platform_code']);
    }

    /**
     * Regression: `in_array($medium, $paidMediums, true)` was case-sensitive,
     * so a link tagged utm_medium=CPC was classified referral, not paid.
     */
    public function testPaidMediumMatchIsCaseInsensitive(): void
    {
        $result = $this->resolver->resolve(['utm_medium' => 'CPC'], null);

        $this->assertSame('paid', $result['traffic_type']);
    }

    /**
     * Regression: medium was matched case-insensitively but stored raw, so
     * 'CPC' and 'cpc' became two grouping keys in the daily summary.
     */
    public function testMediumIsLowercasedOnOutput(): void
    {
        $this->assertSame('cpc', $this->resolver->resolve(['utm_medium' => 'CpC'], null)['medium']);
        $this->assertSame('email', $this->resolver->resolve(['utm_medium' => 'EMAIL'], null)['medium']);
    }

    /**
     * Regression: the tagged branch gated on utm_source alone, so a link with
     * only utm_medium fell through to referrer guessing and lost its tagging
     * AND its campaign.
     */
    public function testUtmMediumOnlyStillCapturesTaggingAndCampaign(): void
    {
        $result = $this->resolver->resolve(
            ['utm_medium' => 'email', 'utm_campaign' => 'spring'],
            'https://blog.example.com/post'
        );

        $this->assertSame('referral', $result['traffic_type']);
        $this->assertSame('email', $result['medium']);
        $this->assertSame('spring', $result['campaign']);
        $this->assertSame(
            TrafficResolver::SOURCE_NOT_SET,
            $result['source'],
            "must be 'not_set', never 'unknown' — 'unknown' is reserved for visit.traffic_type as a bug indicator"
        );
    }

    /**
     * Regression: matchSearchEngine() used str_contains, so notgoogle.com and
     * evil-bing.com.attacker.net both classified as organic.
     */
    public function testSearchEngineMatchingIsLabelBoundaryNotSubstring(): void
    {
        $this->assertSame('referral', $this->resolver->resolve([], 'https://notgoogle.com/x')['traffic_type']);
        $this->assertSame('referral', $this->resolver->resolve([], 'https://evil-bing.com.attacker.net/x')['traffic_type']);
    }

    /**
     * Documents CURRENT behaviour, which is still an open decision per
     * docs/TESTING.md §1: a genuine subdomain of a configured engine counts
     * as organic, so Gmail and Google Sites referrals land in organic search.
     * If that decision changes, this test should change with it — it is here
     * so the behaviour cannot drift silently.
     */
    public function testSubdomainOfSearchEngineCountsAsOrganic(): void
    {
        $result = $this->resolver->resolve([], 'https://mail.google.com/mail/u/0/');

        $this->assertSame('organic', $result['traffic_type']);
        $this->assertSame('google', $result['source']);
    }

    public function testMultipleDomainsCanMapToOneEngine(): void
    {
        $this->assertSame('yandex', $this->resolver->resolve([], 'https://yandex.ru/search')['source']);
    }

    /**
     * Regression: clamp() used byte-wise substr(), which split multi-byte
     * characters and produced invalid UTF-8 that MySQL rejects outright in
     * strict mode — and over-truncated, because the column widths are
     * character counts, not byte counts.
     *
     * @dataProvider multibyteCampaignProvider
     */
    public function testCampaignTruncationIsCharacterSafe(string $char): void
    {
        $campaign = str_repeat($char, 400);

        $result = $this->resolver->resolve(['gclid' => 'a', 'utm_campaign' => $campaign], null);

        $this->assertTrue(
            mb_check_encoding($result['campaign'], 'UTF-8'),
            'truncation must not split a multi-byte character'
        );
        $this->assertSame(128, mb_strlen($result['campaign']), 'must keep 128 CHARACTERS, not 128 bytes');
    }

    public function multibyteCampaignProvider(): array
    {
        return [
            'ascii' => ['a'],
            '2-byte' => ['é'],
            '3-byte' => ['✓'],
            '4-byte emoji' => ['🎯'],
        ];
    }

    /**
     * Regression: click_id_value was not clamped at all, though
     * ads_analytics_visit.click_id_value is varchar(128) and the value is
     * entirely client-supplied.
     */
    public function testClickIdValueIsClamped(): void
    {
        $result = $this->resolver->resolve(['gclid' => str_repeat('x', 500)], null);

        $this->assertSame(128, mb_strlen($result['click_id_value']));
    }

    public function testLongReferrerHostIsClampedToSourceColumnWidth(): void
    {
        $host = str_repeat('a', 200) . '.example.com';

        $result = $this->resolver->resolve([], 'https://' . $host . '/page');

        $this->assertSame(64, mb_strlen($result['source']));
    }

    /**
     * A referrer with no scheme yields no host, so it must fall through to
     * direct rather than being treated as a hostname.
     */
    public function testSchemelessReferrerFallsThroughToDirect(): void
    {
        $this->assertSame('direct', $this->resolver->resolve([], 'mail.google.com')['traffic_type']);
    }

    public function testEmptyClickIdValueDoesNotCountAsPaid(): void
    {
        $this->assertSame('direct', $this->resolver->resolve(['gclid' => ''], null)['traffic_type']);
    }

    public function testCampaignIsNullWhenNotSupplied(): void
    {
        $this->assertNull($this->resolver->resolve(['gclid' => 'a'], null)['campaign']);
    }

    /**
     * With no platforms configured at all (or a malformed config that decoded
     * to nothing), classification must degrade to direct rather than error.
     */
    public function testEmptyPlatformConfigDegradesToDirect(): void
    {
        $emptyConfig = $this->createMock(TrafficClassificationConfig::class);
        $emptyConfig->method('getPlatformMap')->willReturn([]);
        $emptyConfig->method('getPaidMediums')->willReturn([]);
        $emptyConfig->method('getSearchEngineDomains')->willReturn([]);

        $resolver = new TrafficResolver($emptyConfig);

        $this->assertSame('direct', $resolver->resolve(['gclid' => 'abc'], null)['traffic_type']);
    }
}

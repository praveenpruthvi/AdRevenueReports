<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\IngestConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IngestConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;
    private IngestConfig $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new IngestConfig($this->scopeConfig);
    }

    public function testIsEnabledDelegatesToSetFlag(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->assertTrue($this->config->isEnabled());
    }

    /**
     * @dataProvider samplingProvider
     */
    public function testSamplingRateIsClamped($configured, int $expected): void
    {
        $this->scopeConfig->method('getValue')->willReturn($configured);
        $this->assertSame($expected, $this->config->getSamplingRate());
    }

    public function samplingProvider(): array
    {
        return [
            'normal' => ['50', 50],
            'full' => ['100', 100],
            // A stray 0 must not silently disable tracking store-wide; that is
            // what the kill switch is for, and it should be explicit.
            'zero falls open to 100' => ['0', 100],
            'negative falls open to 100' => ['-5', 100],
            'null falls open to 100' => [null, 100],
            'above 100 is capped' => ['500', 100],
        ];
    }

    /**
     * @dataProvider rateLimitProvider
     */
    public function testRateLimitFallsBackOnNonsenseValues($max, $window, int $expectedMax, int $expectedWindow): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path) => str_contains($path, 'max_requests') ? $max : $window
        );

        $this->assertSame($expectedMax, $this->config->getRateLimitMaxRequests());
        $this->assertSame($expectedWindow, $this->config->getRateLimitWindowSeconds());
    }

    public function rateLimitProvider(): array
    {
        return [
            'configured' => ['30', '15', 30, 15],
            // 0 would mean "reject everything" / "divide by zero window" —
            // tracking must fail open, not closed.
            'zero falls back' => ['0', '0', 120, 60],
            'null falls back' => [null, null, 120, 60],
            'negative falls back' => ['-1', '-1', 120, 60],
        ];
    }
}

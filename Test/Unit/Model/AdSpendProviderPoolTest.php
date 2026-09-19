<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

/** docs/TESTING.md §1 — resolves by platform_code, handles a missing provider gracefully. */
class AdSpendProviderPoolTest extends TestCase
{
    public function testResolvesProviderByPlatformCode(): void
    {
        $provider = $this->createMock(AdSpendProviderInterface::class);
        $pool = new AdSpendProviderPool(['google' => $provider]);

        $this->assertSame($provider, $pool->getProvider('google'));
        $this->assertTrue($pool->hasProvider('google'));
    }

    public function testHasProviderIsFalseForUnregisteredPlatform(): void
    {
        $this->assertFalse((new AdSpendProviderPool([]))->hasProvider('pinterest'));
    }

    public function testGetProviderThrowsForUnregisteredPlatform(): void
    {
        $this->expectException(NoSuchEntityException::class);
        (new AdSpendProviderPool([]))->getProvider('pinterest');
    }

    /** The v1 array is empty by design — a bare pool must still construct. */
    public function testEmptyPoolConstructs(): void
    {
        $this->assertFalse((new AdSpendProviderPool())->hasProvider('anything'));
    }
}

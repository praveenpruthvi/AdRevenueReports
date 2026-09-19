<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Api;

/**
 * Extensibility contract (docs/SPECS.md §5, P4-T3/P4-T4).
 * One implementation per ad platform, registered via a di.xml virtual-type
 * array entry on Model\AdSpendProviderPool — never hardcoded in core classes.
 */
interface AdSpendProviderInterface
{
    /**
     * @return array<array{date: string, campaign: string, spend: float}>
     */
    public function getSpend(string $platformCode, \DateTimeInterface $from, \DateTimeInterface $to): array;
}

<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Extensibility point (docs/SPECS.md §5). $providers is populated entirely
 * via di.xml virtual-type array entries — see etc/di.xml in this module for
 * the (empty, by design) v1 array, and add new platforms in a SEPARATE
 * module's di.xml rather than editing this module's core.
 */
class AdSpendProviderPool
{
    /** @var AdSpendProviderInterface[] */
    private array $providers;

    /**
     * @param AdSpendProviderInterface[] $providers keyed by platform_code
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    public function getProvider(string $platformCode): AdSpendProviderInterface
    {
        if (!isset($this->providers[$platformCode])) {
            throw new NoSuchEntityException(
                __('No ad-spend provider registered for platform "%1".', $platformCode)
            );
        }

        return $this->providers[$platformCode];
    }

    public function hasProvider(string $platformCode): bool
    {
        return isset($this->providers[$platformCode]);
    }

    /**
     * Every platform_code that has a registered provider.
     *
     * Added for P4-T5's ROAS report. Iterating only the platforms that appear
     * in the summary would hide a campaign that SPENT money but drew no
     * traffic — which is exactly the case a merchant most needs to see — so
     * the report has to be able to ask "which platforms can report spend?"
     * independently of "which platforms sent visitors?".
     *
     * @return string[]
     */
    public function getPlatformCodes(): array
    {
        return array_map('strval', array_keys($this->providers));
    }
}

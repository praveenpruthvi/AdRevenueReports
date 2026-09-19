<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Single owner of the first-party visitor cookie's name and server-side read
 * (P2-T2).
 *
 * The name lives here rather than being repeated as a literal, because it is
 * needed in three places that would otherwise drift: the beacon config that
 * tells the JS what to set, and both server-side observers that have to read
 * back whatever the JS set.
 *
 * The server only ever READS this cookie. It is set by the beacon, after the
 * consent gate — so its presence is itself evidence that tracking was allowed
 * for this visitor. An observer finding no cookie must treat the event as
 * untrackable and drop it, NOT mint a new id: a server-minted id would have
 * no landing event behind it and would manufacture an unattributable visit
 * on every add-to-cart from a visitor who declined cookies.
 */
class VisitorCookie
{
    public const NAME = 'aavirbhava_visitor_uuid';

    /** Matches ads_analytics_visit.visitor_uuid (etc/db_schema.xml). */
    private const MAX_LENGTH = 64;

    private CookieManagerInterface $cookieManager;

    public function __construct(CookieManagerInterface $cookieManager)
    {
        $this->cookieManager = $cookieManager;
    }

    /**
     * @return string|null the visitor id, or null when tracking is not active
     *                     for this visitor
     */
    public function getVisitorUuid(): ?string
    {
        $value = $this->cookieManager->getCookie(self::NAME);
        if ($value === null) {
            return null;
        }

        // Client-supplied, so treat it as untrusted input: trim, bound it to
        // the column width, and reject anything that is not a plausible id.
        // The consumer validates it again, but rejecting obvious junk here
        // avoids publishing a message that can only ever be rejected.
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return $value;
    }
}

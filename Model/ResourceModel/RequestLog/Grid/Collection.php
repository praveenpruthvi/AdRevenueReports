<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog\Grid;

use Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog as RequestLogResource;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid data source for the read-only Request Log listing (P3-T5b,
 * docs/SPECS.md §9).
 *
 * This is the one admin grid that legitimately reads a raw table rather than
 * ads_analytics_daily_summary. CLAUDE.md #6 ("aggregation, not live joins")
 * is about the ANALYTICS reports, which must never scan raw event tables at
 * request time. The request log is a bounded debugging surface with its own
 * short retention (docs/SECURITY.md §8), not an analytics one — aggregating
 * it would defeat its entire purpose, which is to show individual malformed
 * requests verbatim.
 */
class Collection extends SearchResult implements SearchResultInterface
{
    /** The only column filtered as a regular expression. */
    private const REGEX_FILTER_FIELD = 'page_url';


    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'ads_analytics_request_log',
        $resourceModel = RequestLogResource::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    /**
     * Filters page_url with MySQL REGEXP instead of LIKE.
     *
     * The point of this column is finding traffic the module does not handle,
     * and that means asking questions LIKE cannot express — "any utm_source
     * that is not one of the ones we know", "a click-id parameter ending in
     * clid that is not gclid or fbclid", "utm_campaign with a trailing
     * space". A single substring match cannot do it.
     *
     * A plain keyword still behaves as you would expect, because a regex with
     * no metacharacters is a substring match: typing `gclid` finds every URL
     * containing it. So this costs the ordinary case nothing.
     *
     * Only page_url is treated this way. Every other column keeps stock LIKE
     * behaviour, so a visitor uuid or a rejection reason containing a
     * character that happens to be a regex metacharacter still matches
     * literally.
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field !== self::REGEX_FILTER_FIELD) {
            return parent::addFieldToFilter($field, $condition);
        }

        $pattern = $this->extractPattern($condition);

        if ($pattern === null || $pattern === '') {
            return parent::addFieldToFilter($field, $condition);
        }

        if ($this->isValidRegex($pattern)) {
            $this->getSelect()->where(
                $this->getConnection()->quoteIdentifier(self::REGEX_FILTER_FIELD) . ' REGEXP ?',
                $pattern
            );

            return $this;
        }

        // An incomplete pattern is the normal state of a filter box being
        // typed into — "utm_(" is invalid until the bracket is closed.
        // Falling back to a literal match keeps the grid responding instead
        // of erroring; MySQL would otherwise raise 1139 and the grid would
        // render as a failed request.
        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * Recovers the value the admin actually typed.
     *
     * Magento\Ui\Component\Filters\Type\Input escapes % and _ in the
     * input and then wraps the result in %...% for a LIKE. Both steps have to
     * be undone, and in that order — stripping every leading and trailing %
     * instead would corrupt a pattern beginning with a percent-encoded
     * character, turning a search for "%20" into a search for "20".
     */
    private function extractPattern($condition): ?string
    {
        if (is_array($condition)) {
            $value = $condition['like'] ?? $condition['eq'] ?? null;
        } else {
            $value = $condition;
        }

        if (!is_string($value)) {
            return null;
        }

        if (strncmp($value, '%', 1) === 0) {
            $value = substr($value, 1);
        }
        if ($value !== '' && substr($value, -1) === '%') {
            $value = substr($value, 0, -1);
        }

        return str_replace(['\\%', '\\_'], ['%', '_'], $value);
    }

    /**
     * Asks MySQL itself whether the pattern compiles, rather than validating
     * with preg_match. They are different engines — MySQL 8 uses ICU, PCRE
     * has its own syntax — so a pattern PHP accepts can still be rejected by
     * the database, which is the only opinion that matters here.
     */
    private function isValidRegex(string $pattern): bool
    {
        try {
            $this->getConnection()->fetchOne('SELECT ? REGEXP ?', ['', $pattern]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

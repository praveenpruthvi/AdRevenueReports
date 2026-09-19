<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\ResourceModel\RequestLog\Grid;

use Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog\Grid\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Covers how the request-log grid recovers the regex an admin typed from the
 * LIKE condition Magento wraps it in.
 *
 * The collection itself cannot be constructed without a database, so the
 * private method is exercised directly through reflection. That is worth it
 * because the unwrapping is the one piece of this feature with a subtle
 * failure mode: strip too eagerly and a pattern starting with a
 * percent-encoded character silently searches for something else, with no
 * error to notice.
 */
class PatternExtractionTest extends TestCase
{
    /**
     * Reproduces Magento\Ui\Component\Filters\Type\Input::applyFilter — it
     * escapes % and _ in the typed value, then wraps the result in %...%.
     */
    private function asTyped(string $typed): array
    {
        return ['like' => '%' . str_replace(['%', '_'], ['\%', '\_'], $typed) . '%'];
    }

    private function extract($condition): ?string
    {
        $method = new \ReflectionMethod(Collection::class, 'extractPattern');
        $method->setAccessible(true);

        return $method->invoke(
            (new \ReflectionClass(Collection::class))->newInstanceWithoutConstructor(),
            $condition
        );
    }

    /**
     * @dataProvider typedValues
     */
    public function testRoundTripsWhatTheAdminTyped(string $typed, string $why): void
    {
        $this->assertSame($typed, $this->extract($this->asTyped($typed)), $why);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function typedValues(): array
    {
        return [
            'plain keyword' => ['gclid', 'an ordinary substring search must survive untouched'],
            'regex with alternation' => ['twclid|epik|li_fat_id', 'metacharacters must not be altered'],
            'regex with char class' => ['[?&][a-z_]*clid=', 'underscores inside a class must not stay escaped'],
            'percent-encoded space' => [
                '%20',
                'stripping every leading % would turn this into "20" and match the wrong URLs',
            ],
            'percent-encoded phrase' => ['blue%20jacket', 'an embedded encoded character must survive'],
            'trailing percent' => ['utm_campaign=sale%', 'a literal trailing % is data, not the LIKE wrapper'],
            'underscore' => ['utm_source', 'the escaped underscore must be restored'],
        ];
    }

    public function testHandlesAnEqCondition(): void
    {
        $this->assertSame('gclid', $this->extract(['eq' => 'gclid']));
    }

    public function testHandlesABareString(): void
    {
        $this->assertSame('gclid', $this->extract('gclid'));
    }

    public function testReturnsNullForANonStringCondition(): void
    {
        $this->assertNull($this->extract(['in' => ['a', 'b']]));
        $this->assertNull($this->extract(null));
    }
}

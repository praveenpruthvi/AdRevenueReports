<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Console\Command;

use Aavirbhava\AdsAnalytics\Model\Aggregation\DailySummaryAggregator;
use Aavirbhava\AdsAnalytics\Model\Config\RetentionConfig;
use Aavirbhava\AdsAnalytics\Model\Service\DataPurger;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the daily-summary rollup on demand (P3-T2).
 *
 * Exists because the nightly cron is otherwise the only way to populate the
 * reports, which makes the dashboard untestable and backfilling impossible
 * after importing historical data. The aggregation is idempotent, so running
 * this by hand can only ever converge on the same numbers as the cron.
 *
 * ONE EXCEPTION TO THAT, added with P3-T7. The aggregator's upsert REPLACES a
 * slice's values rather than incrementing them, and Cron\PurgeOldData deletes
 * raw rows past the retention window while ads_analytics_daily_summary is
 * kept for ever. Re-aggregating a date older than that window therefore
 * recomputes it from a raw table that no longer holds those rows and
 * overwrites good history with zeros. The nightly cron sweeps only a short
 * recent lookback so it can never reach back that far, but --from/--to can,
 * so this command refuses pre-retention dates unless --force is passed.
 */
class AggregateSummaryCommand extends Command
{
    private const OPTION_DAYS = 'days';
    private const OPTION_FROM = 'from';
    private const OPTION_TO = 'to';
    private const OPTION_FORCE = 'force';

    private DailySummaryAggregator $aggregator;
    private State $appState;
    private RetentionConfig $retentionConfig;
    private DataPurger $purger;

    public function __construct(
        DailySummaryAggregator $aggregator,
        State $appState,
        RetentionConfig $retentionConfig,
        DataPurger $purger
    ) {
        $this->aggregator = $aggregator;
        $this->appState = $appState;
        $this->retentionConfig = $retentionConfig;
        $this->purger = $purger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('aavirbhava:adsanalytics:aggregate')
            ->setDescription('Rebuild ads_analytics_daily_summary from raw visit/funnel/order data')
            ->addOption(
                self::OPTION_DAYS,
                'd',
                InputOption::VALUE_REQUIRED,
                'Number of days back from today to rebuild',
                '7'
            )
            ->addOption(
                self::OPTION_FROM,
                null,
                InputOption::VALUE_REQUIRED,
                'Start date (Y-m-d). Overrides --days.'
            )
            ->addOption(
                self::OPTION_TO,
                null,
                InputOption::VALUE_REQUIRED,
                'End date (Y-m-d), inclusive. Defaults to today.'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Aggregate dates older than the retention window anyway. This can overwrite '
                . 'existing summary rows with zeros, because the raw data behind them has been purged.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('crontab');
        } catch (\Throwable $e) {
            // Area already set — harmless.
        }

        $to = $input->getOption(self::OPTION_TO) ?: gmdate('Y-m-d');
        $from = $input->getOption(self::OPTION_FROM)
            ?: gmdate('Y-m-d', strtotime('-' . max(0, (int)$input->getOption(self::OPTION_DAYS)) . ' days'));

        if (strtotime($from) === false || strtotime($to) === false) {
            $output->writeln('<error>Dates must be Y-m-d.</error>');
            return Command::FAILURE;
        }
        if (strtotime($from) > strtotime($to)) {
            $output->writeln('<error>--from must not be after --to.</error>');
            return Command::FAILURE;
        }

        if (!$input->getOption(self::OPTION_FORCE) && ($reason = $this->retentionWarning($from)) !== null) {
            $output->writeln('<error>' . $reason . '</error>');
            $output->writeln(
                'Re-run with --force if you are certain, or raise the retention window first. '
                . 'Summary rows for those dates are currently correct; forcing would replace them with '
                . 'whatever the purged raw tables still contain, which is likely zero.'
            );

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Aggregating %s .. %s (UTC) ...', $from, $to));

        try {
            $rows = $this->aggregator->aggregate($from, $to);
        } catch (\Throwable $e) {
            $output->writeln('<error>Aggregation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Done. %d summary row operations.</info>', $rows));

        return Command::SUCCESS;
    }

    /**
     * Returns an explanation when $from reaches back past the raw-event
     * retention window, or null when the range is safe to recompute.
     *
     * Deliberately compares against the SAME cutoff DataPurger uses, rather
     * than recomputing one here, so that raising or lowering the retention
     * setting moves both in step and this guard can never disagree with what
     * the purge actually deleted.
     */
    private function retentionWarning(string $from): ?string
    {
        $retentionDays = $this->retentionConfig->getEventRetentionDays();

        if ($retentionDays <= 0) {
            // Purging is disabled, so no raw data has been deleted and any
            // date can safely be recomputed.
            return null;
        }

        $cutoffDate = substr($this->purger->cutoff($retentionDays), 0, 10);

        if ($from >= $cutoffDate) {
            return null;
        }

        return sprintf(
            'Refusing to aggregate from %s: raw event data is only retained for %d days (back to %s), '
            . 'so earlier dates would be recomputed from purged tables and their summary rows overwritten with zeros.',
            $from,
            $retentionDays,
            $cutoffDate
        );
    }
}

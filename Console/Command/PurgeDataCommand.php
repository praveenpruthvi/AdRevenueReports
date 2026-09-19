<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Console\Command;

use Aavirbhava\AdsAnalytics\Model\Config\RetentionConfig;
use Aavirbhava\AdsAnalytics\Model\Service\DataPurger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento aavirbhava:adsanalytics:purge` — runs the P3-T7 retention
 * purge on demand, for operators who need to enforce a shortened window
 * immediately (a data-subject request, say) rather than waiting for 02:30.
 *
 * Defaults to --dry-run being absent, i.e. it really deletes; but it prints
 * the windows and the row counts it is about to act on first, and --dry-run
 * makes it report without deleting. The override options exist because the
 * common reason to run this by hand is that the configured window is not the
 * one you want applied right now.
 */
class PurgeDataCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_EVENT_DAYS = 'event-days';
    private const OPTION_LOG_DAYS = 'log-days';

    private DataPurger $purger;
    private RetentionConfig $config;

    public function __construct(DataPurger $purger, RetentionConfig $config, ?string $name = null)
    {
        $this->purger = $purger;
        $this->config = $config;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('aavirbhava:adsanalytics:purge')
            ->setDescription('Purge Ads Analytics raw event data and request log past their retention windows')
            ->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would be deleted, delete nothing')
            ->addOption(self::OPTION_EVENT_DAYS, null, InputOption::VALUE_REQUIRED, 'Override the raw-event retention window, in days')
            ->addOption(self::OPTION_LOG_DAYS, null, InputOption::VALUE_REQUIRED, 'Override the request-log retention window, in days');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $eventDays = $this->resolveDays($input->getOption(self::OPTION_EVENT_DAYS), $this->config->getEventRetentionDays());
        $logDays = $this->resolveDays($input->getOption(self::OPTION_LOG_DAYS), $this->config->getRequestLogRetentionDays());

        $output->writeln(sprintf(
            '%sRaw events: %s. Request log: %s.',
            $dryRun ? '<comment>DRY RUN</comment> — ' : '',
            $this->describeWindow($eventDays),
            $this->describeWindow($logDays)
        ));

        try {
            $events = $this->purger->purgeEvents($eventDays, $dryRun);
            $log = $this->purger->purgeRequestLog($logDays, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Purge failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '%s %d visits, %d funnel events, %d request-log rows.',
            $dryRun ? 'Would delete' : 'Deleted',
            $events['visits'],
            $events['funnel_events'],
            $log
        ));
        $output->writeln('<info>ads_analytics_daily_summary is never purged and was not touched.</info>');

        return Command::SUCCESS;
    }

    /**
     * An explicitly passed 0 means "disable this purge for this run", and is
     * honoured; an absent option falls back to configuration.
     */
    private function resolveDays($option, int $configured): int
    {
        if ($option === null || $option === '') {
            return $configured;
        }

        return max(0, (int)$option);
    }

    private function describeWindow(int $days): string
    {
        return $days > 0
            ? sprintf('deleting rows older than %d days (before %s UTC)', $days, $this->purger->cutoff($days))
            : 'purge disabled (window is 0)';
    }
}

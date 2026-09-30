<?php

namespace WCC\Events\Console;

use Curl\Curl;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCC\Events\Storage\AlertmanagerRulesStorage;
use WCC\Events\Storage\EventsStorage;

/**
 * Reconciles open events in DB with currently active alerts in Alertmanager.
 *
 * Intended to fix the case when Alertmanager restarts / is interrupted and never
 * delivers a `resolved` webhook: the event stays open forever in WCA. The command
 * fetches all currently active alerts from Alertmanager and resolves any DB event
 * whose fingerprint is not in that set (scoped to alerts that originated from
 * Alertmanager rules).
 */
class SyncActiveAlertsCommand extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventsStorage;

    /**
     * @Inject
     * @var AlertmanagerRulesStorage
     */
    protected $alertmanagerRulesStorage;

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $observer;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    function config()
    {
        $this->setName("sync-active-alerts")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Do not resolve events, only report what would be resolved")
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, "HTTP timeout for alertmanager request in seconds", 15)
            ->setDescription("Resolve events orphaned after alertmanager restart (no resolved webhook was delivered)");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $timeout = (int)$input->getOption('timeout');

        $activeFingerprints = $this->fetchActiveFingerprints($timeout);
        $output->writeln("Fetched " . count($activeFingerprints) . " active alerts from alertmanager");

        $ruleNames = [];
        foreach ($this->alertmanagerRulesStorage->fetchAll() as $rule) {
            $ruleNames[$rule->getAlertName()] = true;
        }
        if (!$ruleNames) {
            $output->writeln("<comment>No alertmanager rules in storage, nothing to sync</comment>");
            return self::SUCCESS;
        }

        $unresolved = $this->eventsStorage->getNotResolvedBy();
        $output->writeln("Loaded " . count($unresolved) . " unresolved events from storage");

        $resolved = 0;
        $skipped = 0;
        $sysUser = $this->app->getSysUser();

        foreach ($unresolved as $event) {
            if (!isset($ruleNames[$event->getName()])) {
                $skipped++;
                continue;
            }
            $key = $event->getKey();
            if ($key === '' || isset($activeFingerprints[$key])) {
                $skipped++;
                continue;
            }

            $output->writeln(sprintf(
                "%s event id=%d name=%s key=%s",
                $dryRun ? "[dry-run] would resolve" : "Resolving",
                $event->getId(),
                $event->getName(),
                $key
            ));

            if ($dryRun) {
                $resolved++;
                continue;
            }

            $now = date("Y-m-d H:i:s");
            $closed = $this->eventsStorage->update(
                $event
                    ->setUpdatedAt($now)
                    ->setResolvedAt($now)
                    ->setResolvedBy($sysUser)
            );
            $this->observer->notify("event:resolved", $closed);
            $resolved++;
        }

        $output->writeln(sprintf(
            "<info>Done. %s=%d, skipped=%d</info>",
            $dryRun ? "would_resolve" : "resolved",
            $resolved,
            $skipped
        ));
        return self::SUCCESS;
    }

    /**
     * @param int $timeout
     * @return array<string,true> map fingerprint => true
     * @throws \Exception
     */
    protected function fetchActiveFingerprints(int $timeout): array
    {
        $base = rtrim($this->app->conf('prometheus.alertmanager_url'), '/');
        if (!$base) {
            throw new \RuntimeException("ALERTMANAGER_URL is not configured");
        }
        $url = $base . '/api/v2/alerts?active=true&silenced=true&inhibited=true&unprocessed=false';

        $curl = new Curl();
        $curl->setTimeout($timeout);
        $curl->setHeader('Accept', 'application/json');
        $curl->setJsonDecoder(function ($response) {
            return json_decode($response, true);
        });
        $response = $curl->get($url);

        if ($curl->error) {
            throw new \RuntimeException("Alertmanager request failed ({$curl->errorCode}): {$curl->errorMessage}");
        }
        if (!is_array($response)) {
            throw new \RuntimeException("Unexpected alertmanager response (not an array): " . substr((string)$curl->rawResponse, 0, 200));
        }

        $fingerprints = [];
        foreach ($response as $alert) {
            $state = $alert['status']['state'] ?? null;
            if ($state === 'unprocessed') {
                continue;
            }
            $fp = $alert['fingerprint'] ?? null;
            if (is_string($fp) && $fp !== '') {
                $fingerprints[$fp] = true;
            }
        }
        return $fingerprints;
    }
}

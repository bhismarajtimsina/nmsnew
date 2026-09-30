<?php

namespace WCC\Analytics\Api;

use DI\Annotation\Inject;
use Meklis\PromClient\Client;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\App;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * The connections getting worse, worst first.
 *
 * There is already an alarm for a receive level crossing a threshold, which is
 * the moment it is too late — the subscriber is already calling. This ranks by
 * how fast each ONT's level is falling instead, which is a work queue for a
 * splice crew rather than a list of today's failures.
 *
 * The window is short by necessity: Prometheus here keeps about eight days, so
 * a month-long trend cannot be computed no matter how it is asked for. The
 * window actually used is always returned alongside the results, and the
 * plan's retention item is what would widen it.
 */
class OpticalDriftAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
        $params = $this->request->getQueryParams();
        $days = max(1, min(8, (int)($params['days'] ?? 5)));
        $limit = max(1, min(200, (int)($params['limit'] ?? 25)));
        // Only count a real decline. Optical readings wobble, and a connection
        // drifting by hundredths of a decibel a day is not a fault.
        $minPerDay = (float)($params['min_db_per_day'] ?? 0.2);

        // Below this a receiver stops working. Used to say how long a
        // connection has left rather than extrapolating the slope, which
        // produces figures like minus two hundred decibels — arithmetically
        // fine and physically meaningless, since the whole optical budget is
        // around thirty.
        $floor = (float)($params['floor_dbm'] ?? -28);

        $client = new Client(App::getInstance()->conf('prometheus.url'));
        // deriv() gives change per second over the window; times a day's
        // seconds it reads as decibels per day, which is the unit a fibre
        // engineer thinks in.
        // Asked one at a time on purpose. The client does not return results
        // in the order the queries were submitted, so pairing them by index
        // silently swaps the slope and the level — which reads as an ONT
        // losing thirty decibels a day and is simply the wrong number in the
        // wrong column.
        $slopes = $client->queries(["bottomk({$limit}, deriv(optical_rx[{$days}d]) * 86400)"])[0] ?? [];
        $currentLevels = $client->queries(['optical_rx'])[0] ?? [];

        // Current level per interface, so the slope can be turned into time.
        $levels = [];
        foreach ($currentLevels as $item) {
            $key = ($item['metric']['ip'] ?? '') . '|' . ($item['metric']['iface_name'] ?? '');
            $levels[$key] = isset($item['value'][1]) ? (float)$item['value'][1] : null;
        }

        $rows = [];
        foreach ($slopes as $item) {
            $metric = $item['metric'] ?? [];
            $value = isset($item['value'][1]) ? (float)$item['value'][1] : null;
            if ($value === null || $value > -$minPerDay) {
                continue;
            }
            $key = ($metric['ip'] ?? '') . '|' . ($metric['iface_name'] ?? '');
            $level = $levels[$key] ?? null;
            $daysLeft = null;
            if ($level !== null && $level > $floor) {
                $daysLeft = (int)floor(($level - $floor) / abs($value));
            }
            $rows[] = [
                'device' => ['id' => (int)($metric['dev_id'] ?? 0), 'ip' => $metric['ip'] ?? null],
                'interface' => $metric['iface_name'] ?? null,
                'db_per_day' => round($value, 3),
                'rx_dbm' => $level === null ? null : round($level, 2),
                // How long before it reaches the receiver limit at this rate.
                // Null when the level is unknown or already past it.
                'days_to_floor' => $daysLeft,
            ];
        }
        // Soonest to fail first, then steepest.
        usort($rows, function ($a, $b) {
            $ad = $a['days_to_floor'] ?? PHP_INT_MAX;
            $bd = $b['days_to_floor'] ?? PHP_INT_MAX;
            if ($ad !== $bd) return $ad <=> $bd;
            return $a['db_per_day'] <=> $b['db_per_day'];
        });

        return $this->respondWithData($rows, [
            'window_days' => $days,
            'min_db_per_day' => $minPerDay,
            'floor_dbm' => $floor,
            'note' => 'Prometheus retention here is about eight days, so longer trends are not available.',
        ]);
    }
}

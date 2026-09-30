<?php

namespace WCAA\Storage\Devices;

use DI\Annotation\Inject;
use WCAA\Models\User\User;
use WCAA\Storage\AbstractStorage;

/**
 * The queries behind the tiles each console opens on.
 *
 * Kept together because they share one rule: every one of them is scoped to
 * the caller's device groups unless their role may see everything, the same
 * way the event, device and interface listings already scope themselves. A
 * reseller asking what is offline gets their own subscribers and nobody
 * else's, without the caller having to remember to ask for that.
 */
class ConsoleWidgetsStorage extends AbstractStorage
{
    protected $tableName = 'device_interfaces';

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * The device-group clause, or an always-true one for a role that sees all.
     *
     * Mirrors the check the events storage makes: a system or owner account
     * (role id at or below zero) and any role holding events_see_all are
     * unscoped, everyone else is limited to the groups they were given.
     */
    private function scope(?User $user, string $deviceAlias = 'd'): string
    {
        if (!$user || !$user->getRole()) {
            return '1 = 1';
        }
        $role = $user->getRole();
        if ($role->getId() <= 0 || in_array('events_see_all', $role->getPermissions())) {
            return '1 = 1';
        }
        $ids = [-10];
        foreach ($user->getDeviceGroups() as $group) {
            $ids[] = (int)$group->getId();
        }
        return "{$deviceAlias}.group_id in (" . join(',', $ids) . ')';
    }

    /**
     * Why subscribers are offline, not just how many.
     *
     * The poller already records the difference between a customer's own power
     * being off and the fibre losing signal, and nothing has ever shown it.
     * Three quarters of recorded drops are somebody switching off a router,
     * so a single offline number reads as a disaster every evening.
     */
    public function ontOfflineSplit(?User $user): array
    {
        $sql = "
            SELECT i.status, count(*) c
            FROM device_interfaces i
            JOIN devices d ON d.id = i.device_id
            WHERE i.type = 'ONU' AND {$this->scope($user)}
            GROUP BY i.status
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $counts = ['online' => 0, 'lost_signal' => 0, 'power_off' => 0, 'offline' => 0, 'other' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $status = (string)$row['status'];
            $n = (int)$row['c'];
            if (in_array($status, ['Online', 'Up'])) {
                $counts['online'] += $n;
            } elseif ($status === 'LOS') {
                $counts['lost_signal'] += $n;
            } elseif ($status === 'PowerOff') {
                $counts['power_off'] += $n;
            } elseif (in_array($status, ['Offline', 'Down'])) {
                $counts['offline'] += $n;
            } else {
                $counts['other'] += $n;
            }
        }
        $counts['total'] = array_sum($counts);
        // The number worth acting on: signal lost, which is the fibre, as
        // opposed to a customer's own supply.
        $counts['needs_attention'] = $counts['lost_signal'];
        return $counts;
    }

    /**
     * Ports down right now, longest first, with how long they have been down.
     *
     * ONTs are excluded on purpose. This is the ISP's list, and a subscriber
     * being dark belongs on the reseller's.
     */
    public function portsDown(?User $user, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "
            SELECT d.id device_id, d.name device_name, d.ip,
                   i.id interface_id, i.name interface_name, i.type, i.description,
                   i.status, i.status_changed,
                   TIMESTAMPDIFF(MINUTE, i.status_changed, NOW()) down_for_minutes
            FROM device_interfaces i
            JOIN devices d ON d.id = i.device_id
            WHERE i.type NOT IN ('ONU', 'PON', 'EPON')
              AND i.status IN ('Down', 'LOS', 'Offline')
              AND i.poll_enabled = 1
              AND d.enabled = 1
              AND {$this->scope($user)}
            ORDER BY i.status_changed IS NULL, i.status_changed ASC
            LIMIT {$limit}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Every PON port with what is on it and what is left.
     *
     * Answers two questions from one query: where can a reseller still sell,
     * and which ports are in trouble. A port carrying a hundred subscribers
     * with none of them online is not a capacity question, and reading both
     * numbers side by side is what makes that obvious.
     *
     * Capacity is not something the devices report consistently, so it is
     * taken as a parameter — 128 is the usual GPON figure — and the free count
     * is only reported when it is meaningful.
     */
    public function ponPorts(?User $user, int $capacity = 128): array
    {
        $capacity = max(1, min(1024, $capacity));
        $sql = "
            SELECT d.id device_id, d.name device_name, d.ip,
                   p.id port_id, p.name port_name, p.status port_status,
                   count(o.id) onts,
                   sum(o.status in ('Online','Up')) online,
                   sum(o.status = 'LOS') lost_signal,
                   sum(o.status = 'PowerOff') power_off
            FROM device_interfaces p
            JOIN devices d ON d.id = p.device_id
            LEFT JOIN device_interfaces o
                   ON o.device_id = p.device_id
                  AND o.parent_bind_key = p.bind_key
                  AND o.type = 'ONU'
            WHERE p.type = 'PON' AND d.enabled = 1 AND {$this->scope($user)}
            GROUP BY d.id, d.name, d.ip, p.id, p.name, p.status
            ORDER BY onts DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $ports = [];
        foreach ($stmt->fetchAll() as $row) {
            $onts = (int)$row['onts'];
            $online = (int)$row['online'];
            $los = (int)$row['lost_signal'];
            $ports[] = [
                'device' => ['id' => (int)$row['device_id'], 'name' => $row['device_name'], 'ip' => $row['ip']],
                'port' => ['id' => (int)$row['port_id'], 'name' => $row['port_name'], 'status' => $row['port_status']],
                'onts' => $onts,
                'online' => $online,
                'lost_signal' => $los,
                'power_off' => (int)$row['power_off'],
                'free_slots' => max(0, $capacity - $onts),
                'capacity' => $capacity,
                // A port carrying subscribers with none of them online is dark,
                // whatever its capacity says.
                'dark' => $onts > 0 && $online === 0,
            ];
        }
        return ['capacity_assumed' => $capacity, 'ports' => $ports];
    }

    /**
     * How often one subscriber has dropped, and whose fault each time was.
     *
     * The raw history is already available per interface. What it does not
     * answer is the question support is actually asked: "why does my internet
     * keep going off". Eleven drops all of them the customer's own power ends
     * that conversation; four losses of signal in a week books a crew.
     */
    public function dropSummary(int $interfaceId, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $sql = "
            SELECT down_reason, count(*) drops,
                   sum(TIMESTAMPDIFF(MINUTE, up, COALESCE(down, NOW()))) minutes_up,
                   max(down) last_drop
            FROM device_interfaces_history
            WHERE interface_id = ? AND up > NOW() - INTERVAL {$days} DAY
            GROUP BY down_reason
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$interfaceId]);

        $byCause = ['own_power' => 0, 'lost_signal' => 0, 'offline' => 0, 'other' => 0];
        $total = 0;
        $lastDrop = null;
        foreach ($stmt->fetchAll() as $row) {
            $reason = (string)$row['down_reason'];
            $n = (int)$row['drops'];
            $total += $n;
            if ($reason === 'PowerOff') {
                $byCause['own_power'] += $n;
            } elseif (in_array($reason, ['LOS', 'pon-los', 'LOSi', 'LOFi'])) {
                $byCause['lost_signal'] += $n;
            } elseif (in_array($reason, ['Offline', 'Down'])) {
                $byCause['offline'] += $n;
            } elseif ($reason !== '') {
                $byCause['other'] += $n;
            }
            if ($row['last_drop'] && (!$lastDrop || $row['last_drop'] > $lastDrop)) {
                $lastDrop = $row['last_drop'];
            }
        }
        return [
            'interface_id' => $interfaceId,
            'window_days' => $days,
            'drops' => $total,
            'by_cause' => $byCause,
            'last_drop' => $lastDrop,
            // The half that is worth acting on, as opposed to the half that is
            // somebody switching off a router.
            'ours' => $byCause['lost_signal'] + $byCause['offline'],
            'theirs' => $byCause['own_power'],
        ];
    }

    /**
     * Which pollers are failing, and on what.
     *
     * Monitoring that has quietly stopped monitoring is worse than none, and
     * these failures currently sit in a table nobody opens.
     */
    public function pollerHealth(?User $user, int $hours = 24): array
    {
        $hours = max(1, min(720, $hours));
        $sql = "
            SELECT p.poller, p.status, d.id device_id, d.name device_name, d.ip,
                   count(*) runs, max(p.start_at) last_run
            FROM poller_processing p
            JOIN devices d ON d.id = p.device_id
            WHERE p.start_at > NOW() - INTERVAL {$hours} HOUR
              AND {$this->scope($user)}
            GROUP BY p.poller, p.status, d.id, d.name, d.ip
            ORDER BY p.status DESC, runs DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $failing = [];
        $ok = 0;
        foreach ($stmt->fetchAll() as $row) {
            if ($row['status'] === 'FAILED') {
                $failing[] = [
                    'poller' => $row['poller'],
                    'device' => ['id' => (int)$row['device_id'], 'name' => $row['device_name'], 'ip' => $row['ip']],
                    'failed_runs' => (int)$row['runs'],
                    'last_run' => $row['last_run'],
                ];
            } else {
                $ok += (int)$row['runs'];
            }
        }
        return [
            'window_hours' => $hours,
            'successful_runs' => $ok,
            'failing' => $failing,
            'failing_count' => count($failing),
        ];
    }
}

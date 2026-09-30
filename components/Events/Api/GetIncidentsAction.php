<?php

namespace WCC\Events\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Models\EventFilter;
use WCC\Events\Storage\EventsStorage;

/**
 * Open events collapsed into the thing that actually broke.
 *
 * A list of alarms is not a list of problems. Forty ONTs dark on one PON port
 * is one fault and forty rows, and with hundreds of optical alarms open the
 * genuine outages are impossible to see. This groups by cause instead:
 *
 *   device      the device is unreachable, so everything else on it is a
 *               symptom and is folded in rather than reported separately
 *   pon_port    several subscribers on the same PON port
 *   interface   several alarms on one port
 *   alarm       anything else, grouped by device and alarm name
 *
 * Takes the same focus_for and focus parameters as the event list, so each
 * console groups its own alarms.
 */
class GetIncidentsAction extends PrivateAction
{
    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventsStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    private const SEVERITY_ORDER = ['info' => 1, 'warning' => 2, 'critical' => 3];

    protected function action(): Response
    {
        try {
            $form = $this->getFormData();
        } catch (\Throwable $e) {
            $form = [];
        }
        if (!$form) {
            $form = $this->request->getQueryParams();
        }

        $filter = new EventFilter();
        $filter->setUser($this->user);
        if (isset($form['device_id']) && $form['device_id']) {
            $filter->setDevice($this->deviceStorage->getById($form['device_id']));
        }
        if (isset($form['focus_for']) && $form['focus_for']) {
            $tiers = $form['focus'] ?? 'primary';
            if (!is_array($tiers)) {
                $tiers = array_filter(array_map('trim', explode(',', (string)$tiers)));
            }
            $filter->setFocus((string)$form['focus_for'], $tiers);
        }

        $rows = $this->eventsStorage->getOpenForGrouping($filter);

        // A device that is not answering explains everything else on it, so its
        // own alarm becomes the incident and the rest are folded in as
        // symptoms. Without this an OLT going dark raises one useful alarm and
        // several hundred that merely repeat it.
        $unreachable = [];
        foreach ($rows as $row) {
            if ($row['name'] === 'pinger_host_down') {
                $unreachable[(int)$row['device_id']] = true;
            }
        }

        $incidents = [];
        foreach ($rows as $row) {
            $labels = json_decode((string)$row['labels'], true) ?: [];
            $deviceId = (int)$row['device_id'];
            $ifaceName = (string)($labels['iface_name'] ?? '');
            $ifaceType = (string)($labels['iface_type'] ?? '');

            if (isset($unreachable[$deviceId])) {
                $kind = 'device';
                $where = $labels['ip'] ?? (string)$deviceId;
                $key = "device:{$deviceId}";
            } elseif ($ifaceType === 'ONU' && strpos($ifaceName, ':') !== false) {
                $kind = 'pon_port';
                $where = trim(substr($ifaceName, 0, strpos($ifaceName, ':')));
                $key = "pon:{$deviceId}:{$where}";
            } elseif ($ifaceName !== '') {
                $kind = 'interface';
                $where = $ifaceName;
                $key = "iface:{$deviceId}:{$ifaceName}";
            } else {
                $kind = 'alarm';
                $where = '';
                $key = "alarm:{$deviceId}:{$row['name']}";
            }

            if (!isset($incidents[$key])) {
                $incidents[$key] = [
                    'key' => $key,
                    'kind' => $kind,
                    'device' => ['id' => $deviceId, 'ip' => $labels['ip'] ?? null],
                    'where' => $where,
                    'severity' => (string)$row['severity'],
                    'events' => 0,
                    'alarms' => [],
                    'started_at' => $row['created_at'],
                    'latest_at' => $row['created_at'],
                    'event_ids' => [],
                ];
            }
            $incident = &$incidents[$key];
            $incident['events']++;
            $incident['alarms'][$row['name']] = ($incident['alarms'][$row['name']] ?? 0) + 1;
            if ($row['created_at'] < $incident['started_at']) $incident['started_at'] = $row['created_at'];
            if ($row['created_at'] > $incident['latest_at']) $incident['latest_at'] = $row['created_at'];
            // The worst severity in the group is the one that matters.
            $current = self::SEVERITY_ORDER[$incident['severity']] ?? 0;
            $candidate = self::SEVERITY_ORDER[(string)$row['severity']] ?? 0;
            if ($candidate > $current) $incident['severity'] = (string)$row['severity'];
            // Enough ids to open the group, not the whole thousand.
            if (count($incident['event_ids']) < 200) $incident['event_ids'][] = (int)$row['id'];
            unset($incident);
        }

        $out = array_values($incidents);
        usort($out, function ($a, $b) {
            $sev = (self::SEVERITY_ORDER[$b['severity']] ?? 0) <=> (self::SEVERITY_ORDER[$a['severity']] ?? 0);
            if ($sev !== 0) return $sev;
            return $b['events'] <=> $a['events'];
        });

        return $this->respondWithData($out, [
            'incidents' => count($out),
            'events' => count($rows),
        ]);
    }
}

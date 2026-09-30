<?php
declare(strict_types=1);

namespace WCAA\Api\Actions\System;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;

class MonitoringTargetsAction extends PrivateAction
{
    private const TARGET_DIR = '/www/var/prometheus/targets';

    private const JOBS = [
        'snmp' => [
            'title' => 'SNMP Exporter',
            'file' => 'snmp.yml',
            'default_module' => 'if_mib',
            'placeholder' => '192.168.1.1',
        ],
        'blackbox-http' => [
            'title' => 'Blackbox HTTP',
            'file' => 'blackbox-http.yml',
            'default_module' => 'http_2xx',
            'placeholder' => 'https://example.com',
        ],
        'blackbox-tcp' => [
            'title' => 'Blackbox TCP',
            'file' => 'blackbox-tcp.yml',
            'default_module' => 'tcp_connect',
            'placeholder' => '192.168.1.1:443',
        ],
        'blackbox-icmp' => [
            'title' => 'Blackbox ICMP',
            'file' => 'blackbox-icmp.yml',
            'default_module' => 'icmp',
            'placeholder' => '192.168.1.1',
        ],
    ];

    protected function action(): Response
    {
        switch (strtoupper($this->request->getMethod())) {
            case 'GET':
                return $this->respondWithData($this->listTargets());
            case 'POST':
                return $this->saveTarget();
            case 'DELETE':
                return $this->deleteTarget();
        }

        throw new HttpBadRequestException($this->request, 'Unsupported method.');
    }

    private function saveTarget(): Response
    {
        $form = $this->getFormData();
        $kind = $this->requireKind($form['kind'] ?? '');
        $target = $this->cleanString($form['target'] ?? '');
        if ($target === '') {
            throw new HttpBadRequestException($this->request, 'Target is required.');
        }

        $groups = $this->readGroups($kind);
        $originalTarget = $this->cleanString($form['original_target'] ?? $target);
        $groups = array_values(array_filter($groups, function (array $group) use ($originalTarget): bool {
            return !in_array($originalTarget, $group['targets'] ?? [], true);
        }));

        $labels = $this->normalizeLabels($form['labels'] ?? []);
        foreach (['name', 'group', 'module', 'notes'] as $label) {
            $value = $this->cleanString($form[$label] ?? '');
            if ($value !== '') {
                $labels[$label] = $value;
            }
        }
        if (!isset($labels['module']) || $labels['module'] === '') {
            $labels['module'] = self::JOBS[$kind]['default_module'];
        }
        $labels['managed_by'] = 'support-ui';

        $groups[] = [
            'targets' => [$target],
            'labels' => $labels,
        ];

        $this->writeGroups($kind, $groups);
        return $this->respondWithData([
            'saved' => true,
            'reload' => $this->reloadPrometheus(),
            'targets' => $this->listTargets(),
        ]);
    }

    private function deleteTarget(): Response
    {
        $form = $this->getFormData();
        $kind = $this->requireKind($form['kind'] ?? ($this->request->getQueryParams()['kind'] ?? ''));
        $target = $this->cleanString($form['target'] ?? ($this->request->getQueryParams()['target'] ?? ''));
        if ($target === '') {
            throw new HttpBadRequestException($this->request, 'Target is required.');
        }

        $groups = array_values(array_filter($this->readGroups($kind), function (array $group) use ($target): bool {
            return !in_array($target, $group['targets'] ?? [], true);
        }));

        $this->writeGroups($kind, $groups);
        return $this->respondWithData([
            'deleted' => true,
            'reload' => $this->reloadPrometheus(),
            'targets' => $this->listTargets(),
        ]);
    }

    private function listTargets(): array
    {
        $targets = [];
        foreach (self::JOBS as $kind => $job) {
            $targets[$kind] = [];
            foreach ($this->readGroups($kind) as $group) {
                foreach (($group['targets'] ?? []) as $target) {
                    $labels = $group['labels'] ?? [];
                    $targets[$kind][] = [
                        'id' => sha1($kind . '|' . $target . '|' . json_encode($labels)),
                        'kind' => $kind,
                        'target' => (string)$target,
                        'name' => (string)($labels['name'] ?? ''),
                        'group' => (string)($labels['group'] ?? ''),
                        'module' => (string)($labels['module'] ?? $job['default_module']),
                        'notes' => (string)($labels['notes'] ?? ''),
                        'labels' => $labels,
                    ];
                }
            }
        }

        return [
            'jobs' => self::JOBS,
            'targets' => $targets,
        ];
    }

    private function requireKind(string $kind): string
    {
        $kind = $this->cleanString($kind);
        if (!isset(self::JOBS[$kind])) {
            throw new HttpBadRequestException($this->request, 'Unknown target type.');
        }
        return $kind;
    }

    private function targetFile(string $kind): string
    {
        return self::TARGET_DIR . '/' . self::JOBS[$kind]['file'];
    }

    private function readGroups(string $kind): array
    {
        $file = $this->targetFile($kind);
        if (!is_file($file) || filesize($file) === 0) {
            return [];
        }

        $data = yaml_parse_file($file);
        if (!is_array($data)) {
            return [];
        }

        $groups = [];
        foreach ($data as $group) {
            if (!is_array($group)) {
                continue;
            }
            $targets = array_values(array_filter(array_map('strval', $group['targets'] ?? [])));
            if (!$targets) {
                continue;
            }
            $groups[] = [
                'targets' => $targets,
                'labels' => $this->normalizeLabels($group['labels'] ?? []),
            ];
        }
        return $groups;
    }

    private function writeGroups(string $kind, array $groups): void
    {
        if (!is_dir(self::TARGET_DIR) && !mkdir(self::TARGET_DIR, 0775, true) && !is_dir(self::TARGET_DIR)) {
            throw new HttpBadRequestException($this->request, 'Target directory is not writable.');
        }

        $file = $this->targetFile($kind);
        $tmp = $file . '.tmp';
        $yaml = yaml_emit(array_values($groups), YAML_UTF8_ENCODING, YAML_LN_BREAK);
        if (file_put_contents($tmp, $yaml) === false || !rename($tmp, $file)) {
            throw new HttpBadRequestException($this->request, 'Could not write target file.');
        }
        @chmod($file, 0664);
    }

    private function normalizeLabels($labels): array
    {
        if (!is_array($labels)) {
            return [];
        }

        $normalized = [];
        foreach ($labels as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$key);
            $value = $this->cleanString($value);
            if ($key !== '' && $value !== '') {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    private function cleanString($value): string
    {
        return trim((string)$value);
    }

    private function reloadPrometheus(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents('http://wca-prometheus:9090/-/reload', false, $context);
        $status = $http_response_header[0] ?? '';

        return [
            'ok' => strpos($status, '200') !== false,
            'status' => $status,
            'body' => is_string($response) ? trim($response) : '',
        ];
    }
}

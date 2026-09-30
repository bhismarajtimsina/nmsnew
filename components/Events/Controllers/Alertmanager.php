<?php

namespace WCC\Events\Controllers;

use Curl\Curl;
use Meklis\PromClient\Client;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCC\Events\Models\AlertmanagerRule;
use WCC\Events\Storage\AlertmanagerRulesStorage;

class Alertmanager
{
    /**
     * @Inject
     * @var Curl
     */
    protected $curl;

    /**
     * @Inject
     * @var AlertmanagerRulesStorage
     */
    protected $alertmanagerRulesStorage;

    /**
     * @var App
     */
    protected $app;


    protected $moduleConfig;

    function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return mixed
     */
    public function getModuleConfig()
    {
        return $this->moduleConfig;
    }

    /**
     * @param mixed $moduleConfig
     * @return Alertmanager
     */
    public function setModuleConfig($moduleConfig)
    {
        $this->moduleConfig = $moduleConfig;
        return $this;
    }

    /**
     * @return \WCC\Events\Models\AlertmanagerRule[]
     * @throws \Exception
     */
    function listRules()
    {
        return $this->alertmanagerRulesStorage->fetchAll();
    }

    /**
     * @param AlertmanagerRule[] $rules
     * @return AlertmanagerRule[]
     */
    function updateRules(array $rules)
    {
        $filtered = array_filter($rules, function ($r) {
            return $r->isEnabled();
        });
        foreach ($filtered as $rule) {
            $this->validateRule($rule);
        }

        $existedRules = [];
        foreach ($this->alertmanagerRulesStorage->fetchAll() as $rule) {
            $existedRules[$rule->getAlertName()] = $rule;
        };

        foreach ($rules as $rule) {
            if(isset($existedRules[$rule->getAlertName()]) && $existedRules[$rule->getAlertName()]->isInternal()) {
                $rule->setExpression($existedRules[$rule->getAlertName()]->getExpression());
                $rule->setFor($existedRules[$rule->getAlertName()]->getFor());
                $rule->setInternal(true);
                $rule->setAlertName($existedRules[$rule->getAlertName()]->getAlertName());
                $rule->setGroupName($existedRules[$rule->getAlertName()]->getGroupName());
            }
        }

        $rules = $this->alertmanagerRulesStorage->updateAll($rules);
        $this->applyRules($filtered);
        return $rules;
    }

    /**
     * @param AlertmanagerRule[] $rules
     * @return self
     */
    function applyRules(array $rules)
    {
        $path = $this->moduleConfig['rules_path'];
        $txt = $this->generateYamlText($rules);
        if (file_exists($path)) {
            $old = file_get_contents($path);
        } else {
            $old = '';
        }
        try {
            file_put_contents($path, $txt);
            $this->reloadConfiguration();
        } catch (\Throwable $e) {
            file_put_contents($path, $old);
            throw $e;
        }
        return $this;
    }

    /**
     * @param AlertmanagerRule $rule
     * @return bool
     */
    function validateRule(AlertmanagerRule $rule)
    {
        if (!preg_match('/^[a-zA-Z_]*$/', $rule->getAlertName())) {
            throw new \InvalidArgumentException("Rule name must be matched with regex ^[a-zA-Z_]*$. Only A-z and _ allowed");
        }
        if (!preg_match('/^[a-zA-Z_]*$/', $rule->getGroupName())) {
            throw new \InvalidArgumentException("Rule group must be matched with regex ^[a-zA-Z_]*$. Only A-z and _ allowed");
        }
        if (!preg_match('/^[0-9]{1,4}[smhd]$/', $rule->getFor())) {
            throw new \InvalidArgumentException("For must be in duration format (example - 5m or 120s)");
        }
        if (!preg_match('/^(info|warning|critical)$/', $rule->getSeverity())) {
            throw new \InvalidArgumentException("For severity allowed only info|warning|critical");
        }

        $path = $this->moduleConfig['testing_rules_path'];
        $txt = $this->generateYamlText([$rule]);
        if (!$txt) {
            throw new \Exception("Error generate config for rule with name {$rule->getAlertName()}");
        }
        file_put_contents($path, $txt);
        $cmd = __DIR__ . "/../bin/promtool check rules $path";
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput();
            $message = substr($message, strpos($message, "rule 1, "));
            throw new \InvalidArgumentException("Error validate rule: {$message}");
        }
        if ($err = $process->getErrorOutput()) {
            throw new SupportException("Error validate rule with name '{$rule->getAlertName()}': $err");
        }
        return true;
    }

    /**
     * @param string $expression
     * @return bool
     */
    function validateExpr(string $expression)
    {
        $curl = new Curl();
        $curl->setTimeout(10);
        $curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->setJsonDecoder(function ($response) {
            return json_decode($response, true);
        });
        $request = [
            'query' => $expression,
            'start' => time() - (3600),
            'end' => time(),
            'step' => "5m",
        ];
        $response = $curl->post($this->app->conf('prometheus.url') . '/api/v1/query_range', $request);
        if (!$response) {
            throw new \Exception("Unknown response from prometheus");
        }
        if (isset($response['error'])) {
            throw new \Exception($response['error']);
        }
        return true;
    }

    /**
     * @param AlertmanagerRule[] $rules
     * @return string
     */
    protected function generateYamlText(array $rules)
    {
        $prepared = [];
        foreach ($rules as $rule) {
            if (!$rule->isEnabled()) continue;
            if (!isset($prepared[$rule->getGroupName()])) {
                $prepared[$rule->getGroupName()] = [
                    'name' => $rule->getGroupName(),
                    'rules' => [],
                ];
            }
            $prepared[$rule->getGroupName()]['rules'][] = [
                'alert' => $rule->getAlertName(),
                'expr' => $rule->getExpression(),
                'for' => $rule->getFor(),
                'labels' => [
                    'severity' => $rule->getSeverity(),
                ],
                'annotations' => [
                    'summary' => $rule->getAnnotationSummary(),
                    'description' => $rule->getAnnotationDescription(),
                ]
            ];
        }
        $prepared = ['groups' => array_values($prepared)];
        return yaml_emit($prepared);
    }

    /**
     * @return $this
     * @throws \Exception
     */
    function reloadConfiguration()
    {
        $resp = $this->curl->post($this->app->conf('prometheus.url') . '/-/reload');
        if (strpos($resp, 'failed') !== false) {
            throw new \Exception("Failed reload prometheus configuration: {$resp}");
        }
        $resp = $this->curl->post($this->app->conf('prometheus.alertmanager_url') . '/-/reload');
        if (strpos($resp, 'failed') !== false) {
            throw new \Exception("Failed reload alertmanager configuration: {$resp}");
        }
        return $this;
    }
    /**
     * @param AlertmanagerRule $rule
     * @return AlertmanagerRule
     */
    function addOrUpdateInternalAlertRule(AlertmanagerRule $rule)
    {
        try {
            $existedRule = $this->alertmanagerRulesStorage->getAlertByAlertName($rule->getAlertName());
            $rule->setId($existedRule->getId());
            $rule->setEnabled($existedRule->isEnabled());
            $rule->setSeverity($existedRule->getSeverity());
            $rule->setAnnotationDescription($existedRule->getAnnotationDescription());
            $rule->setAnnotationSummary($existedRule->getAnnotationSummary());
        } catch (\Exception $e) {}
        if(!$rule->getId()) {
            $updatedRule = $this->alertmanagerRulesStorage->add($rule);
        } else {
            $updatedRule = $this->alertmanagerRulesStorage->update($rule);
        }
        return $updatedRule;
    }

    function applyStoredRules()
    {
        $rules = array_filter($this->alertmanagerRulesStorage->fetchAll(), function ($rule) {
            return $rule->isEnabled();
        });
        $this->applyRules($rules);
        return $this;
    }
}
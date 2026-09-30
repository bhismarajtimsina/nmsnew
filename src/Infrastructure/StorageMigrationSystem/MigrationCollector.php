<?php


namespace WCAA\Infrastructure\StorageMigrationSystem;


use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Models\DbMigration;
use WCAA\Storage\DbMigrationsStorage;

class MigrationCollector
{
    /**
     * @var ComponentInjector
     */
    protected $moduleInjector;

    /**
     * @var DbMigrationsStorage
     */
    protected $migrationStorage;

    /**
     * @var App
     */
    protected $app;

    protected $migrations = [];

    function __construct(ComponentInjector $moduleInjector, DbMigrationsStorage $storage, App $app)
    {
        $this->moduleInjector = $moduleInjector;
        $this->migrationStorage = $storage;
        $this->app = $app;
    }

    /**
     * @return DbMigration[]
     */
    public function storageMigrations()
    {
        $migrations = [];
        try {
            foreach ($this->migrationStorage->fetchAll() as $migration) {
                $migrations[$migration->getName()] = $migration;
            }
        } catch (\Exception $e) {

        }
        return $migrations;
    }

    public function getMigrationsList()
    {
        $migrations = [];
        foreach ($this->getSystemMigrations() as $migration) {
            $migrations[$migration['name']]['paths'] = [
                'up' => $migration['up'],
                'down' => $migration['down'],
            ];
            $migrations[$migration['name']]['name'] = $migration['name'];
            $migrations[$migration['name']]['is_up'] = false;
        }
        if ($this->app->conf('migrations.scan_modules')) {
            foreach ($this->getModulesMigrations() as $migration) {
                $migrations[$migration['name']]['paths'] = [
                    'up' => $migration['up'],
                    'down' => $migration['down'],
                ];
                $migrations[$migration['name']]['name'] = $migration['name'];
                $migrations[$migration['name']]['is_up'] = false;
            }
        }
        foreach ($this->storageMigrations() as $migration) {
            if(!isset($migrations[$migration->getName()])) continue;
            $migrations[$migration->getName()]['is_up'] = true;
        }
        return $migrations;
    }

    /**
     * Migrations are applied in name order, so two directories sharing a numeric
     * prefix (e.g. 061_a and 061_b) no longer express a defined sequence - the tie
     * is broken by the text after the number. Both still run exactly once, but the
     * numbering has stopped meaning anything, which is worth surfacing.
     *
     * @return array<string, string[]> prefix => migration names
     */
    public function getDuplicatePrefixes()
    {
        $byPrefix = [];
        foreach (array_keys($this->getMigrationsList()) as $name) {
            //Names are "<source>:<NNN>_<description>"; group on the numeric part.
            $bare = strpos($name, ':') !== false ? substr($name, strpos($name, ':') + 1) : $name;
            if (!preg_match('/^(\d+)_/', $bare, $matches)) {
                continue;
            }
            $scope = str_replace($bare, '', $name) . $matches[1];
            $byPrefix[$scope][] = $name;
        }
        return array_filter($byPrefix, function ($names) {
            return count($names) > 1;
        });
    }

    private function getModulesMigrations()
    {
        $components = $this->moduleInjector->getComponentsPath();
        $migrations = [];
        foreach ($components as $component) {
            if (is_dir($component['path'] . '/migrations')) {
                $migrations = array_merge($migrations, $this->readMigrationDirectory($component['path'] . '/migrations', $component['component']));

            }
        }
        return $migrations;
    }

    private function getSystemMigrations()
    {
        $systemPath = $this->app->conf('migrations.system_path');
        return $this->readMigrationDirectory($systemPath, "sys");
    }

    private function readMigrationDirectory($path, $prefix = "")
    {
        if (!is_dir($path)) {
            throw new \Exception("Directory for system migrations not exist or incorrect");
        }
        $migrationNames = array_filter(scandir($path), function ($e) use ($path) {
            return is_dir($path . '/' . $e) && !in_array($e, ['.', '..']);
        });
        sort($migrationNames);
        $migrations = [];
        foreach ($migrationNames as $migrationName) {
            if ($prefix) $prefix = trim($prefix, ":") .":";
            $migrations[$prefix . $migrationName] = [
                'name' => $prefix . $migrationName,
                'up' => null,
                'down' => null,
            ];
            if (is_file($path . '/' . $migrationName . '/up.sql')) {
                $migrations[$prefix . $migrationName]['up'] = realpath($path . '/' . $migrationName . '/up.sql');
            }
            if (is_file($path . '/' . $migrationName . '/down.sql')) {
                $migrations[$prefix . $migrationName]['down'] = realpath($path . '/' . $migrationName . '/down.sql');
            }
        }
        return $migrations;
    }

}
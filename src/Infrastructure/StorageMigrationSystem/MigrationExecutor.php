<?php


namespace WCAA\Infrastructure\StorageMigrationSystem;


use WCAA\Storage\DbMigrationsStorage;

class MigrationExecutor
{
    protected $storage;
    protected $collector;
    protected $pdo;

    function __construct(DbMigrationsStorage $storage, MigrationCollector $collector, \PDO $pdo)
    {
        $this->storage = $storage;
        $this->collector = $collector;
        $this->pdo = $pdo;
    }

    function readSQLMigration(string $migrationName, $type = 'up')
    {
        $migrations = $this->collector->getMigrationsList();
        if (!isset($migrations[$migrationName])) {
            throw new \InvalidArgumentException("Migration with name $migrationName not found");
        }
        if ($type !== 'up' && $type !== 'down') throw new \InvalidArgumentException("Type of migration is incorrect. You can use up|down types only");
        $migration = $migrations[$migrationName];
        $path = $migration['paths'][$type];
        if(!$path) {
            throw new \Exception("Not found $type migration for migration $migrationName");
        }
        $queries = $this->readFile($path);
        return $queries;
    }

    /**
     * @return array
     */
    function getMigrationsList() {
        return $this->collector->getMigrationsList();
    }

    /**
     * @param $migrationName
     * @param string $type
     * @param bool $breakIfError
     * @param null $callBack
     * @return \Exception|null
     * @throws \Exception
     */
    function executeMigration($migrationName, $type = 'up', $breakIfError = true, $callBack = null)
    {
        $queries = $this->readSQLMigration($migrationName, $type);
        $error = null;
        foreach ($queries as $query) {
            if(!trim($query)) continue;
            try {
                $start = microtime(true);
                $this->pdo->exec($query);
                $tookTime = microtime(true) - $start;
                if ($callBack !== null && is_callable($callBack)) {
                    $callBack((new MigrationExecutorCallbackResult())
                        ->setQuery($query)
                        ->setTookTime($tookTime)
                    );
                }
            } catch (\Exception $e) {
                $tookTime = microtime(true) - $start;
                if ($callBack !== null && is_callable($callBack)) {
                    $callBack((new MigrationExecutorCallbackResult())->setError($e)
                        ->setQuery($query)
                        ->setTookTime($tookTime)
                    );
                }
                $error = null;
                if ($breakIfError) return $e;
            }
        }
        if($error) {
            return  $error;
        }
        if($type === 'up') {
            $this->setMigrationSuccessRollUp($migrationName);
        } else if ($type === 'down') {
            $this->setMigrationSuccessRollDown($migrationName);
        }
        return null;
    }

    function getAllQueryMigrations($type = 'up')
    {
        $migrations = $this->collector->getMigrationsList();
        foreach ($migrations as $name => $migration) {
            $migrations[$name]['queries'] = $this->readSQLMigration($name, $type);
            unset($migrations[$name]['paths']);
        }
        return $migrations;
    }

    function setMigrationSuccessRollUp($migrationName)
    {
        $psth = $this->pdo->prepare("INSERT IGNORE INTO wca_migrations (created_at, name) VALUES (NOW(), ?)");
        $psth->execute([$migrationName]);
        return $this;
    }

    function setMigrationSuccessRollDown($migrationName)
    {
        $psth = $this->pdo->prepare("DELETE FROM wca_migrations WHERE name = ?");
        $psth->execute([$migrationName]);
        return $this;
    }

    protected function readFile($file)
    {
        if (!@is_file($file)) {
            throw new \Exception("File $file not found");
        }
        $content = file_get_contents($file);
        if(strpos($content, '#QUERY') !== false) {
            $queries = explode("#QUERY", $content);
        } else {
            $queries = explode(";", $content);
        }
        return array_filter(array_map(function ($e) {
            return trim($e);
        }, $queries), function ($e) {
            return trim($e) != '';
        });
    }

}
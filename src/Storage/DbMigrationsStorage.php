<?php


namespace WCAA\Storage;


use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Models\DbMigration;
use WCAA\Models\SystemAction;
use WCAA\Models\User\User;

class DbMigrationsStorage extends AbstractStorage
{
    protected $tableName = 'wca_migrations';


    /**
     * @param $name
     * @return DbMigration|null
     */
    function getByName($name) {
        $psth = $this->pdo->prepare("SELECT * FROM wca_migrations WHERE name = ?");
        $psth->execute([$name]);
        if(!$psth->rowCount()) {
            return null;
        }
        $arr = $psth->fetchAll();
        return  $this->fillByArr(new DbMigration(), $arr[0] );
    }

    /**
     * @return DbMigration[]
     */
    function fetchAll() {
        $psth = $this->pdo->prepare("SELECT * FROM wca_migrations order by 2");
        $psth->execute();
        $migrations = [];
        foreach ($psth->fetchAll() as $migration) {
            $migrations[] = $this->fillByArr(new DbMigration(), $migration);
        }
        return $migrations;
    }
}
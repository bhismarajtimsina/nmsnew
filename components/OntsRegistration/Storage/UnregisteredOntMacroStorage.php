<?php

namespace WCC\OntsRegistration\Storage;

use WCAA\Models\Devices\DeviceModel;
use WCAA\Storage\AbstractStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;

class UnregisteredOntMacroStorage extends AbstractStorage
{

    protected $tableName = 'c_onts_registration_macros';

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $deviceModelsStorage;


    /**
     * @return UnregisteredOntMacro[]
     * @throws \Exception
     */
    function getAll()
    {
        $psth = $this->pdo->query("
            SELECT id,
                   created_at,
                   updated_at,
                   enabled,
                   name,
                   device_model_ids,
                   parameters,
                   template
            FROM c_onts_registration_macros
        ");
        $psth->execute();
        $objects = [];
        foreach ($psth->fetchAll() as $raw) {
            $objects[] = $this->fillFromRaw(new UnregisteredOntMacro($raw['id']), $raw);
        }
        return $objects;
    }

    /**
     * @param DeviceModel $model
     * @return UnregisteredOntMacro
     * @throws \Exception
     */
    function getByDeviceModel(DeviceModel $model)
    {
        $objects = $this->getAll();
        foreach ($objects as $object) {
            if(!$object->isEnabled()) continue;
            if (array_filter($object->getModels(), function ($md) use ($model) {
                return $model->getId() == $md->getId();
            })) {
                return $object;
            }

        }
        throw new RecordNotFoundException("Macros not found by device model - {$model->getName()}");
    }


    /**
     * @param UnregisteredOntMacro $object
     * @param bool $fillChildObjects
     * @return mixed
     * @throws \Exception
     */
    public function fill($object, $fillChildObjects = true)
    {
        if ($this->isCacheExist($object)) {
            $cache = $this->getCache($object);
            if ($cache) {
                return $cache;
            }
        }
        $psth = $this->pdo->prepare("
        SELECT id,  
               created_at,
               updated_at,
               enabled,
               name,  
               device_model_ids,   
               parameters, 
               template 
            FROM c_onts_registration_macros WHERE id = ?");
        $psth->execute([$object->getId()]);
        if ($psth->rowCount() == 0) {
            throw new \Exception("UnregisteredOnt with ID={$object->getId()} not found");
        }
        $raw = $psth->fetchAll()[0];
        return $this->fillFromRaw($object, $raw);
    }

    protected function fillFromRaw(UnregisteredOntMacro $object, array $raw)
    {
        $object->enabled = $raw['enabled'] == 1;
        $object->created_at = $raw['created_at'];
        $object->updated_at = $raw['updated_at'];
        $object->name = $raw['name'];
        $object->template = $raw['template'];
        $object->parameters = json_decode($raw['parameters'], true);

        $models = [];
        foreach (json_decode($raw['device_model_ids'], true) as $modelId) {
            $models[] = $this->deviceModelsStorage->getById($modelId);
        }
        $object->setModels($models);

        $this->setCache($object);
        return $object;
    }

    /**
     * @param UnregisteredOntMacro $object
     * @return UnregisteredOntMacro
     */
    public function add($object)
    {
        $psth = $this->pdo->prepare("INSERT INTO c_onts_registration_macros (
                                 created_at, 
                                 updated_at, 
                                 enabled, 
                                 name,
                                 device_model_ids,  
                                 parameters, 
                                 template 
                      ) 
                VALUES (?,?,?,?,?,?,?)");
        $modelIds = array_map(function (DeviceModel $model) {
            return $model->getId();
        }, $object->getModels());

        if($object->isEnabled()) {
            foreach ($modelIds as $modelId) {
                $regIds = $this->getRegistrationIdsByModelId($modelId);
                if (count($regIds) > 0) {
                    throw new \Exception("Model with ID {$modelId} already configured in another registration object");
                }
            }
        }

        $psth->execute([
            $object->getCreatedAt(),
            $object->getUpdatedAt(),
            $object->isEnabled() ? 1 : 0,
            $object->getName(),
            json_encode($modelIds),
            json_encode($object->getParameters()),
            $object->getTemplate(),
        ]);
        $object->setId($this->pdo->lastInsertId());
        $this->_ids = [];
        return $this->fill($object);
    }

    /**
     * @param UnregisteredOntMacro $object
     * @return mixed|UnregisteredOntMacro
     * @throws \Exception
     */
    public function update($object)
    {
        $modelIds = array_map(function (DeviceModel $model) {
            return $model->getId();
        }, $object->getModels());

        foreach ($modelIds as $modelId) {
            $regIds = array_filter($this->getRegistrationIdsByModelId($modelId), function ($m) use ($object) {
                return $m != $object->getId();
            });
            if(count($regIds) > 0) {
                throw new \Exception("Model with ID {$modelId} already configured in another registration object");
            }
        }

        $psth = $this->pdo->prepare("UPDATE c_onts_registration_macros SET 
                               updated_at = ?,
                               enabled = ?,
                  name = ?,  
                  device_model_ids = ?,  
                  parameters = ?, 
                  template = ? 
                  WHERE id = ? ");

        $psth->execute([
            date("Y-m-d H:i:s"),
            $object->isEnabled() ? 1 : 0,
            $object->getName(),
            json_encode($modelIds),
            json_encode($object->getParameters()),
            $object->getTemplate(),
            $object->getId()
        ]);
        $this->clearCache($object);
        return $this->fill($object);
    }

    public function delete($object)
    {
        return parent::delete($object);
    }

    protected function getRegistrationIdsByModelId($deviceModel)
    {
        $ids = [];
        foreach ($this->getAll() as $object) {
            if(!$object->isEnabled()) continue;
            $modelIds = array_map(function ($m) { return $m->getId(); }, $object->getModels());
            if (in_array($deviceModel, $modelIds)) {
                $ids[] = $object->getId();
            }
        }
        return $ids;
    }
}

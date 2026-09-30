<?php

namespace WCC\Macros\Storage;

use WCAA\Models\Devices\DeviceModel;
use WCAA\Models\User\UserRole;
use WCAA\Storage\AbstractStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\UserRoleStorage;
use WCC\Macros\Models\Macros;

class MacrosStorage extends AbstractStorage
{

    protected $tableName = 'c_macros';

    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $userRolesStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $deviceModelsStorage;


    /**
     * @return Macros[]
     * @throws \Exception
     */
    function getAll()
    {
        $psth = $this->pdo->query("SELECT id FROM c_macros");
        $psth->execute();
        $ids = array_map(function ($i) {
            return $i['id'];
        }, $psth->fetchAll());
        $objects = [];
        foreach ($ids as $id) {
            $objects[] = $this->fill(new Macros($id));
        }
        return $objects;
    }

    function getByDeviceModel(DeviceModel $model)
    {
        $objects = $this->getAll();
        $filtered = [];
        foreach ($objects as $object) {
            $allowed = false;
            if (!array_filter($object->getModels(), function ($md) use ($model) {
                return $model->getId() == $md->getId();
            })) {
                $allowed = true;
            }

            if ($allowed) {
                $filtered[] = $object;
            }
        }
        return $filtered;
    }

    function getByUserRole(UserRole $userRole)
    {
        $objects = $this->getAll();
        $filtered = [];
        foreach ($objects as $object) {
            $allowed = false;
            if (!array_filter($object->getAllowedRoles(), function ($md) use ($userRole) {
                return $md->getId() == $userRole->getId();
            })) {
                $allowed = true;
            }

            if ($allowed) {
                $filtered[] = $object;
            }
        }
        return $filtered;
    }

    /**
     * @param DeviceModel $deviceModel
     * @param UserRole $role
     * @param $display_for
     * @return Macros[]
     * @throws \Exception
     */
    function getByParameters(DeviceModel $deviceModel, UserRole $role, $display_for = '')
    {
        $objects = $this->getAll();
        $filtered = [];
        foreach ($objects as $object) {
            $allowed = true;
            if (!array_filter($object->getAllowedRoles(), function ($d) use ($role) {
                return $d->getId() == $role->getId();
            })) {
                $allowed = false;
            }
            if (!array_filter($object->getModels(), function ($d) use ($deviceModel) {
                return $d->getId() == $deviceModel->getId();
            })) {
                $allowed = false;
            }
            if (!array_filter($object->getDisplayFor(), function ($d) use ($display_for) {
                return $d == $display_for;
            })) {
                $allowed = false;
            }

            if ($allowed) {
                $filtered[] = $object;
            }
        }
        return $filtered;
    }

    /**
     * @param Macros $object
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
               name, 
               description,
               device_model_ids, 
               allowed_role_ids, 
               display_for, 
               parameters, 
               template, 
               display_output 
            FROM c_macros WHERE id = ?");
        $psth->execute([$object->getId()]);
        if ($psth->rowCount() == 0) {
            throw new \Exception("Macros with ID={$object->getId()} not found");
        }
        $raw = $psth->fetchAll()[0];
        $object->name = $raw['name'];
        $object->description = $raw['description'];
        $object->display_for = json_decode($raw['display_for'], true);
        $object->template = $raw['template'];
        $object->parameters = json_decode($raw['parameters'], true);
        $object->display_output = $raw['display_output'];

        $models = [];
        foreach (json_decode($raw['device_model_ids'], true) as $modelId) {
            $models[] = $this->deviceModelsStorage->getById($modelId);
        }
        $object->setModels($models);

        $roles = [];
        $roleIds = json_decode($raw['allowed_role_ids'], true);
        if(!in_array(-1, $roleIds)) {
            $roleIds[] = -1;
        }
        if(!in_array(-2, $roleIds)) {
            $roleIds[] = -2;
        }
        foreach ($roleIds as $roleId) {
            $roles[] = $this->userRolesStorage->getById($roleId);
        }
        $object->setAllowedRoles($roles);

        $this->setCache($object);
        return $object;
    }

    /**
     * @param Macros $object
     * @return Macros
     */
    public function add($object)
    {
        $psth = $this->pdo->prepare("INSERT INTO c_macros (name, 
                      description, 
                      device_model_ids, 
                      allowed_role_ids, 
                      display_for, 
                      parameters, 
                      template,
                      display_output
                      ) 
                VALUES (?,?,?,?,?,?,?,?)");
        $modelIds = array_map(function (DeviceModel $model) {
            return $model->getId();
        }, $object->getModels());
        $roleIds = array_map(function (UserRole $role) {
            return $role->getId();
        }, $object->getAllowedRoles());

        $psth->execute([
            $object->getName(),
            $object->getDescription(),
            json_encode($modelIds),
            json_encode($roleIds),
            json_encode($object->getDisplayFor()),
            json_encode($object->getParameters()),
            $object->getTemplate(),
            $object->getDisplayOutput(),
        ]);
        $object->setId($this->pdo->lastInsertId());
        $this->_ids = [];
        $this->clearCache($object);
        $this->flushIds();
        // This override replaces AbstractStorage::add() entirely (custom
        // SQL), so the generic "storage:{table}:*" real-time signal added
        // there never fires here unless repeated explicitly.
        $this->observerStorage->notify("storage:{$this->tableName}:added", $object);
        return $this->fill($object);
    }

    /**
     * @param Macros $object
     * @return mixed|Macros
     * @throws \Exception
     */
    public function update($object)
    {
        $modelIds = array_map(function (DeviceModel $model) {
            return $model->getId();
        }, $object->getModels());
        $roleIds = array_map(function (UserRole $role) {
            return $role->getId();
        }, $object->getAllowedRoles());

        $psth = $this->pdo->prepare("UPDATE c_macros SET 
                  name = ?, 
                  description = ?, 
                  device_model_ids = ?, 
                  allowed_role_ids = ?, 
                  display_for = ?, 
                  parameters = ?, 
                  template = ?,
                  display_output = ?
                  WHERE id = ? ");

        $psth->execute([
            $object->getName(),
            $object->getDescription(),
            json_encode($modelIds),
            json_encode($roleIds),
            json_encode($object->getDisplayFor()),
            json_encode($object->getParameters()),
            $object->getTemplate(),
            $object->getDisplayOutput(),
            $object->getId()
        ]);
        $this->clearCache($object);
        $this->flushIds();
        // See add()'s comment — same generic real-time signal, "updated".
        $this->observerStorage->notify("storage:{$this->tableName}:updated", $object);
        return $this->fill($object);
    }

    public function delete($object)
    {
        return parent::delete($object);
    }


}
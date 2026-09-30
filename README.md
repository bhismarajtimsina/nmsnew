# Support API 

### Возможности    
- Interfaces 
    - RestAPI
    - Console    
- User management
    - Sessions
    - Groups
    - Control group permissions
- Logging
    - Switcher-core actions
    - User actions
    - System actions
- Modules
- Switcher-core as way of communication with devices


### Установка

#### [Инструкция по запуску с помощью docker](docs/USE_DOCKER.md)

Пример установки приводится для Ubuntu 20.04 x64     
* Установить необходимые компоненты системы 
```shell
apt update
apt -y install php7.4-{pdo,cli,fpm,curl,json,mbstring,mysql,snmp,xml,readline} \
  php-{memcached,yaml,zip} \
  mysql-server \
  nginx \
  memcached \
  git \
  composer
```
* Создать необходимые каталоги и файлы, установить support: 
```shell
mkdir -p /opt/support
mkdir -p /opt/support
mkdir -p /var/log/support
touch /var/log/support/system.log
chmod -R 777 /var/log/support
chmod -R 777 /opt/support

cd /opt/support
git clone https://github.com/meklis/support-api.git .
composer install
chmod +x /opt/support/console
cp .env-example .env
ln -s /opt/support/console /usr/bin/wca
```

* Далее, вам необходимо настроить nginx, mysql и php   
  Пример оптимальной настройки для ubuntu можно получить с папки install/ubuntu_configs.
  Используйте команду
```shell
  sudp cp -R /opt/support/install/ubuntu_configs/* /  
```

* Создайте базу данных и пользователя для support.     
  Пользователь должен иметь права DDL и DML, так как выполняются миграции (создаются и изменяются таблицы)

Следуйте инструкциям по команде.   
Эта команда перезапишет файл .env на основе шаблонного .env-template

* Выполнить миграции  
```
wca migration:migrate
```


* Добавьте необходимые задачи в cron    
  на данный момент реализованы следующие:
```shell
#Обновляет информацию о незарегистрированных ОНУ на устройствах ZTE 
*/5	8-21	*	*	*	 wca zte_c320_interfaces:get-unregistered-onts

#Обновляет информацию о интерфейсах на устройствах ZTE 
*/10	*	*	*	*	 wca zte_c320_interfaces:update-interfaces-cache
```  

rm -Rf /tmp/hsperfdata_meklis# nmsnew

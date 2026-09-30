CREATE TEMPORARY TABLE uniq_models_for_save
SELECT max(id) id, `key` FROM device_models GROUP BY `key`;
CREATE index tmp_model_index ON  uniq_models_for_save (id);
DELETE FROM device_models WHERE id not in (SELECT id FROM uniq_models_for_save);
create unique index device_models_key_uindex
    on device_models (`key`);
DROP TABLE uniq_models_for_save;

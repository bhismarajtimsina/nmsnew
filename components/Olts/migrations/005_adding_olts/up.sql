
INSERT IGNORE INTO device_models (`key`,name, params,vendor,model,icon,controller,pollers, type)  VALUES
('huawei_smart_ax', 'Huawei SmartAX OLT',
'{"show_optical_info_from_history": true}',
'Huawei',
'SmartAX OLT',
'/upload/icons/huawei.png',
'\\WCC\\Olts\\Controllers\\Controller',
'{"system": 300, "counters": 180, "fdb_table": 900, "ont_ident": 600,   "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}',
'OLT'
);
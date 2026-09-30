UPDATE device_models SET pollers = '
{"system": 300, "fdb_table": 900, "ont_ident": 600, "sys_resources": 180, "interfaces_list": 300, "optical_strength": 1800, "pon_port_loading": 300, "interfaces_status": 180, "ont_vendor_info": 600}
'
WHERE `key` in ('c_data_fd1208s',
                'c_data_fd1216s_r1',
               'c_data_fd1108s'
    );
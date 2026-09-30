# Prometheus API client

Realized by doc - https://prometheus.io/docs/prometheus/latest/querying/api/


### Example of usage
```php 
<?php
require  __DIR__ . '/vendor/autoload.php';


$client = new \Meklis\PromClient\Client('http://10.0.30.2:9090');

// Return results of queries
$client->queriesRange(
    ['optical_temperature{iface_name="pon0/0/2:14", ip="10.15.1.2"}'],
);

```
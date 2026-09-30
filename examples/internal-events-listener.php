<?php

$command = './bin/wca-no-pty.sh events:subscribe internal-events'; // Замените на вашу команду

// Открываем процесс для выполнения команды и связываем его с потоком
$process = popen($command, 'r');

if ($process === false) {
    die('Unable to open process.');
}

// Читаем строки из потока вывода команды в цикле по одной
while ($line = fgets($process)) {
    $data = json_decode($line, true);
    echo "--------------------------------------------------------------\n";
    echo "Event name: {$data['name']}\n";
    echo "Data: \n";
    echo json_encode($data['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// Закрываем поток
pclose($process);

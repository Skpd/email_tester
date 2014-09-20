<?php
$objects = [];
$all = [];
for ($i = 0; $i < 30000; $i++) {
    $objects[] = ['id' => mt_rand(0, $i), 'key' => mt_rand(0, 30000), 'a'];
    $all[] = ['id' => mt_rand(0, $i), 'key' => mt_rand(0, 30000), 'b'];
}

$counter = 0;
$time = microtime(1);
//$dup = array_uintersect($objects, $all, function ($objectsElement, $allElement) use (&$counter) {
//    $counter++;
//    return strcmp($objectsElement['key'], $allElement['key']);
//});

$dup = array_map('unserialize', array_intersect(array_map('serialize', $objects), array_map('serialize', $all)) );


var_dump($counter, microtime(1) - $time, count($dup));
exit;
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require_once 'vendor/autoload.php';

$input  = new SplFileObject($argv[1], 'r');
$output = new SplFileObject($argv[2], 'w');

$checked = 0;
$valid   = 0;
$invalid = 0;
$started = microtime(1);

$client = new GearmanClient();
$client->addServer();

$client->setCompleteCallback(function (GearmanTask $task) use (&$output, &$checked, $started, &$valid, &$invalid) {
    $data = json_decode($task->data(), true);

    if ($data['status']) {
        $output->fwrite($data['email'] . PHP_EOL);
        $valid++;
    } else {
        $invalid++;
    }

    $checked++;

    echo "\r" . date(DATE_ATOM) . " Speed: " . ($checked / (microtime(1) - $started)) . " emails / sec. Valid: $valid, Invalid: $invalid.                    ";
});

$client->setExceptionCallback(function (GearmanTask $task) {
    echo "Exception!\n";
});

while (!$input->eof()) {
//    for ($i = 0; $i < 2048; $i++) {
        $client->doBackground('check_email', trim($input->fgets()));
//    }
//    break;
}

//$client->runTasks();
//
//$client->wait();

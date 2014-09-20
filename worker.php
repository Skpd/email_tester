<?php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require_once 'vendor/autoload.php';

use EmailTester\SyncClient as Client;

$output = new SplFileObject('/home/dmskpd/valid_emails_2.dat', 'a');

$worker = new GearmanWorker();

$worker->addServer();
$worker->addFunction('check_email', 'testEmail');

$servers = [
    'tcp://gmail-smtp-in.l.google.com:25',
    'tcp://alt1.gmail-smtp-in.l.google.com:25',
    'tcp://alt2.gmail-smtp-in.l.google.com:25',
    'tcp://alt3.gmail-smtp-in.l.google.com:25',
    'tcp://alt4.gmail-smtp-in.l.google.com:25',
];

$client = new Client();

$client->connect($servers[mt_rand(0, count($servers) - 1)]);

for ($i = 0; $i < 256; $i++) {
    $worker->work();
}

$client->disconnect();

function testEmail(GearmanJob $job) {
    global $client, $output;

    $email  = $job->workload();
    $result = $client->checkEmail($email);

    if ($result) {
        if ($output->flock(LOCK_EX)) {
            $output->fwrite($email . PHP_EOL);
            $output->flock(LOCK_UN);
        }
    }

    return json_encode(['email' => $email, 'status' => $result]);
}
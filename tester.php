<?php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require_once 'vendor/autoload.php';

$input  = new SplFileObject($argv[1], 'r');
$output = new SplFileObject($argv[2], 'w');

define('DEBUG', 1);

$servers = [
    'tcp://gmail-smtp-in.l.google.com:25',
    'tcp://alt1.gmail-smtp-in.l.google.com:25',
    'tcp://alt2.gmail-smtp-in.l.google.com:25',
    'tcp://alt3.gmail-smtp-in.l.google.com:25',
    'tcp://alt4.gmail-smtp-in.l.google.com:25',
];

/** @var \EmailTester\Client[] $clients */
$clients = [];

$loop   = React\EventLoop\Factory::create();

$logger = new \Zend\Log\Logger();
$writer = new \Zend\Log\Writer\Stream('php://output');
$logger->addWriter($writer);

$logger->info('Creating clients.');

$start   = microtime(1);
$checked = 0;

for ($i = 0; $i < 300; $i++) {
    $client = new \EmailTester\Client(
        $loop,
        function ($email) use ($output, &$checked, $start, $logger) {
            $output->fwrite($email . PHP_EOL);
            $checked++;

            if ($checked % 1000 == 0) {
                $logger->info("Checked: $checked. Speed: " . ($checked / (microtime(1) - $start)) . " emails/sec.");
            }
        },
        function ($email, $reason) use (&$checked, $start, $logger) {
            $logger->warn("Email <$email> failed check: $reason");
            $checked++;

            if ($checked % 1000 == 0) {
                $logger->info("Checked: $checked. Speed: " . ($checked / (microtime(1) - $start)) . " emails/sec.");
            }
        }
    );

    $clients[] = $client;
}

$logger->info('Done.');

$loop->addPeriodicTimer(.001, function () use ($clients, $input, $servers, $logger) {
    foreach ($clients as $c) {
        if ($c->getState() === \EmailTester\Client::STATE_DISCONNECTED) {
            $logger->info(spl_object_hash($c) . ": connecting...");
            $c->connect($servers[mt_rand(0, count($servers) - 1)]);
            return;
        }

        if ($c->getState() === \EmailTester\Client::STATE_BUSY) {
            continue;
        }

        if ($c->getState() === \EmailTester\Client::STATE_IDLE) {
            $c->checkEmail($input->fgets());
            continue;
        }
    }
});

$loop->run();
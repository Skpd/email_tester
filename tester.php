<?php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require_once 'vendor/autoload.php';

for ($i = 0; $i < 5; $i++) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die('could not fork');
    } else if ($pid) {

    } else {
        goForIt();
        exit;
    }
}

while (pcntl_waitpid(0, $status) != -1) {
    $status = pcntl_wexitstatus($status);
}

function goForIt() {
    $mongo  = new MongoClient();
    $emails = $mongo->selectDB('emails_tester')->selectCollection('emails');

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

    for ($i = 0; $i < 100; $i++) {
        $client = new \EmailTester\Client(
            $loop,
            function ($record) use (&$checked, $emails, $start, $logger) {
                $record['state'] = 'valid';
                $emails->save($record);
                $checked++;

                if ($checked % 1000 == 0) {
                    $logger->info("Checked: $checked. Speed: " . ($checked / (microtime(1) - $start)) . " emails/sec.");
                }
            },
            function ($record, $reason) use (&$checked, $emails, $start, $logger) {
                $record['state'] = 'invalid';
                $emails->save($record);

                if ($reason !== false) {
                    $logger->warn("Email <{$record['email']}> failed check: $reason");
                }

                $checked++;

                if ($checked % 1000 == 0) {
                    $logger->info("Checked: $checked. Speed: " . ($checked / (microtime(1) - $start)) . " emails/sec.");
                }
            }
        );

        $clients[] = $client;
    }

    $logger->info('Done.');

    $loop->addPeriodicTimer(.001, function () use ($clients, $emails, $servers, $logger) {
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
                $record = $emails->findOne(['state' => ['$exists' => false]]);
                if (!isset($record['email'])) {
                    continue;
                }
                $record['state'] = 'in_progress';
                $emails->save($record);

                $c->checkEmail($record);
                continue;
            }
        }
    });

    $loop->run();
}

<?php

declare(strict_types=1);

// Hand Payrudder a user of your app, then check whether that user may go on and what to tell them.

require __DIR__ . '/../vendor/autoload.php';

use Payrudder\Client;

$client = new Client(cache: []);

$session = $client->connect(['id' => 'user_42', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
echo 'customer page: ', $session->url ?? '-', "\n";

$access = $client->access('user_42', 'api_calls');
echo $access->allowed ? 'allowed' : 'denied', ' ', $access->reason instanceof BackedEnum ? $access->reason->value : $access->reason, "\n";
foreach ($access->notices ?? [] as $n) {
    echo '[', $n->severity instanceof BackedEnum ? $n->severity->value : $n->severity, '] ', $n->code instanceof BackedEnum ? $n->code->value : $n->code, ' ', $n->meterCode, "\n";
}

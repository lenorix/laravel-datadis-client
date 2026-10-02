<?php

// A worker process of ConcurrencyTest: waits for the barrier file, then claims one guarded query on a
// file cache store shared with its siblings, and prints whether it would have sent it.
//
// usage: php claim-worker.php <cache dir> <barrier file> <secret>

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;

require __DIR__.'/../../vendor/autoload.php';

[, $directory, $barrier, $secret] = $argv;

$cache = new Repository(new FileStore(new Filesystem, $directory));
$ledger = new RequestLedger($cache, new RequestFingerprinter($secret), atomic: new LaravelAtomicStore($cache));

$deadline = microtime(true) + 10;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(200);
}

echo $ledger->claim('00000000T', ['cups' => 'ES0000000000000000AA0A', 'startDate' => '2026/07']) === null ? 'sent' : 'refused';

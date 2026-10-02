<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;
use Psr\SimpleCache\CacheInterface;

/**
 * A cache whose reads always miss: what two workers see when both read before either one writes. The
 * writes and the atomic add go to the shared store behind it.
 */
function blindCache(Repository $shared): CacheInterface
{
    return new class($shared) implements CacheInterface
    {
        public function __construct(private readonly Repository $shared) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }

        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
        {
            return $this->shared->set($key, $value, $ttl);
        }

        public function delete(string $key): bool
        {
            return $this->shared->delete($key);
        }

        public function clear(): bool
        {
            return $this->shared->clear();
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return false;
        }
    };
}

it('lets one worker send when every worker read before any wrote, only with the atomic add', function (bool $atomic, int $sent) {
    $shared = new Repository(new ArrayStore);
    $sends = 0;

    for ($worker = 0; $worker < 6; $worker++) {
        $ledger = new RequestLedger(
            blindCache($shared),
            new RequestFingerprinter(str_repeat('k', 32)),
            atomic: $atomic ? new LaravelAtomicStore($shared) : null,
        );

        if ($ledger->claim('00000000T', ['startDate' => '2026/07']) === null) {
            $sends++;
        }
    }

    expect($sends)->toBe($sent);
})->with([
    'atomic add: one sends' => [true, 1],
    'plain PSR-16: all of them send' => [false, 6],
]);

it('lets exactly one of several simultaneous processes send a query, on a file cache', function () {
    $worker = __DIR__.'/Support/claim-worker.php';

    for ($round = 0; $round < 3; $round++) {
        $directory = sys_get_temp_dir().'/datadis-claim-'.bin2hex(random_bytes(6));
        $barrier = $directory.'.go';
        mkdir($directory);
        $processes = [];

        for ($i = 0; $i < 8; $i++) {
            $process = proc_open([PHP_BINARY, $worker, $directory, $barrier, str_repeat('k', 32)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $processes[] = [$process, $pipes];
        }

        touch($barrier);   // release them together

        $answers = array_map(function (array $entry) {
            [$process, $pipes] = $entry;
            $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            proc_close($process);

            return $out;
        }, $processes);

        (new Filesystem)->deleteDirectory($directory);
        @unlink($barrier);

        expect(array_count_values($answers))->toEqual(['sent' => 1, 'refused' => 7]);
    }
});

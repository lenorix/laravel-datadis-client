<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use InvalidArgumentException;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Throwable;

/**
 * Runs the import of an earlier attempt into the guard with nobody else importing for the account: the ledger keeps the
 * newest attempt of a query, but two imports that both read the held attempt before either writes would leave the older
 * time, since the overwrite is not a compare and set. One lock for the account, named from a keyed hash, never from the NIF.
 * A store without locks cannot serialise them: then only one process may import.
 *
 * @internal
 */
final class Importer
{
    public function __construct(
        private readonly AccountSettings $accounts,
        private readonly GuardLedgers $guard,
        private readonly ReportLevel $reportLevel,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(DatadisClient): T  $import  what to do with the client of the account while the lock is held
     * @return T
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings or the cache are wrong
     * @throws LedgerUnavailableException when another import of the account does not finish, or the cache fails taking its lock
     */
    public function run(?string $account, Closure $import): mixed
    {
        $client = $this->clientFor($account);
        $store = $this->guard->store();
        $provider = $store instanceof CacheRepository ? $store->getStore() : null;

        if (! $provider instanceof LockProvider) {
            return $import($client);
        }

        // Taking the lock is the part that waits and that can fail: a busy lock, or the cache itself. The import runs once it is held.
        try {
            $lock = $provider->lock($this->lockName($this->accounts->username($account)), 30);
            $lock->block(2);
        } catch (LockTimeoutException $e) {
            throw new LedgerUnavailableException('Another import did not finish: import the history from one process.', previous: $e);
        } catch (ConfigurationException|InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The lock of the import could not be taken: the cache failed.', previous: $e);
        }

        try {
            return $import($client);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The import is done; the lock lets go by itself after thirty seconds, and a cache that fails now says nothing new.
            }
        }
    }

    /** The name of the lock of an account: a keyed hash of its username, so that the NIF is in no key of the cache. */
    public function lockName(#[SensitiveParameter] string $username): string
    {
        return 'datadis_import_'.substr(hash_hmac('sha256', $username, $this->guard->secret()), 0, 40);
    }

    /**
     * The client an import needs: the account's settings and the ledger, and no HTTP stack, since nothing is sent. A wrong
     * `http` setting must not stop an import, and no request can leave from it.
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings are wrong
     */
    private function clientFor(?string $name): DatadisClient
    {
        $this->reportLevel->level();
        $name ??= $this->accounts->defaultName();
        $settings = $this->accounts->find($name)
            ?? throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");

        return DatadisClient::fromArray($settings, http: new MutedTransport, ledger: $this->guard->ledger($name), clock: $this->clock);
    }
}

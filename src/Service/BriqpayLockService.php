<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Lightweight DB-based mutex / idempotency-claim primitive.
 *
 * Mirrors the role WooCommerce's Lock class plays there (built on the atomicity
 * of a unique-keyed INSERT) so Briqpay operations are safe against concurrent
 * webhook deliveries, double-clicked admin actions, and finalize()/webhook()
 * races — without depending on Symfony's Lock component being configured with
 * a real store, which isn't guaranteed on every Shopware installation.
 */
class BriqpayLockService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Attempts to acquire an exclusive, releasable lock for $key.
     *
     * Returns false immediately if another (non-expired) holder already has it.
     * Expired locks are reclaimed transparently.
     */
    public function acquire(string $key, int $ttlSeconds): bool
    {
        $this->reapExpired($key);

        try {
            $this->connection->insert('briqpay_lock', [
                'lock_key' => $key,
                'expires_at' => $this->expiresAt($ttlSeconds),
            ]);

            return true;
        } catch (UniqueConstraintViolationException $e) {
            return false;
        }
    }

    /**
     * Releases a lock previously acquired via acquire().
     */
    public function release(string $key): void
    {
        $this->connection->delete('briqpay_lock', ['lock_key' => $key]);
    }

    /**
     * Runs $callback while holding a lock on $key, guaranteeing release even on failure.
     *
     * @throws \RuntimeException if the lock is already held.
     */
    public function withLock(string $key, int $ttlSeconds, callable $callback): mixed
    {
        if (!$this->acquire($key, $ttlSeconds)) {
            throw new \RuntimeException(sprintf('Briqpay: operation already in progress for "%s"', $key));
        }

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
    }

    /**
     * One-shot idempotency claim: returns true the first time $key is claimed,
     * false on any repeat claim until the TTL expires. Never explicitly released
     * (used for deduping repeated webhook deliveries of the same event, not as a mutex).
     */
    public function claimOnce(string $key, int $ttlSeconds): bool
    {
        return $this->acquire($key, $ttlSeconds);
    }

    private function reapExpired(string $key): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `briqpay_lock` WHERE `lock_key` = :key AND `expires_at` < :now',
            ['key' => $key, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v')]
        );
    }

    private function expiresAt(int $ttlSeconds): string
    {
        return (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.v');
    }
}

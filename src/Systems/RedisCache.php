<?php

namespace Purple\Cache\Systems;

use DateInterval;
use DateTime;
use Predis\Client;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class RedisCache implements CacheInterface
{
    private Client $redis;
    private int $defaultTtl;
    private int $maxSize;
    private string $evictionPolicy;
    private string $prefix = 'purple_cache:';
    private string $metadataKey = 'purple_cache_metadata';

    public function __construct(Client $redis, int $defaultTtl = 3600, int $maxSize = 100, string $evictionPolicy = 'LRU')
    {
        $this->redis = $redis;
        $this->defaultTtl = $defaultTtl;
        $this->maxSize = $maxSize;
        $this->evictionPolicy = $evictionPolicy;

        // Set Redis maxmemory-policy based on the eviction policy
        $redisEvictionPolicy = $this->evictionPolicy === 'LRU' ? 'allkeys-lru' : 'allkeys-lfu';
        $this->redis->config('set', 'maxmemory-policy', $redisEvictionPolicy);
    }

    public function get($key, $default = null)
    {
        $this->validateKey($key);
        $value = $this->redis->get($this->prefix . $key);

        if ($value === null) {
            return $default;
        }

        $this->updateMetadata($key, 'access');
        return unserialize($value);
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);

        if ($this->redis->dbsize() >= $this->maxSize) {
            $this->evict();
        }

        $expiration = $this->getExpirationTime($ttl);
        $success = $this->redis->setex(
            $this->prefix . $key,
            $expiration->getTimestamp() - time(),
            serialize($value)
        );

        if ($success) {
            $this->updateMetadata($key, 'set');
        }

        return (bool)$success;
    }

    public function delete($key): bool
    {
        $this->validateKey($key);
        $result = $this->redis->del($this->prefix . $key);
        $this->removeMetadata($key);
        return $result > 0;
    }

    public function clear(): bool
    {
        $keys = $this->redis->keys($this->prefix . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
        $this->redis->del($this->metadataKey);
        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        if (!is_array($keys) && !($keys instanceof \Traversable)) {
            throw new InvalidArgumentException("Invalid keys type");
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        if (!is_array($values) && !($values instanceof \Traversable)) {
            throw new InvalidArgumentException("Invalid values type");
        }

        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple($keys): bool
    {
        if (!is_array($keys) && !($keys instanceof \Traversable)) {
            throw new InvalidArgumentException("Invalid keys type");
        }

        $prefixedKeys = array_map(fn($key) => $this->prefix . $key, $keys);
        $result = $this->redis->del($prefixedKeys);
        foreach ($keys as $key) {
            $this->removeMetadata($key);
        }
        return $result > 0;
    }

    public function has($key): bool
    {
        $this->validateKey($key);
        return (bool)$this->redis->exists($this->prefix . $key);
    }

    public function getTTL($key): ?int
    {
        $this->validateKey($key);
        $ttl = $this->redis->ttl($this->prefix . $key);
        return $ttl > 0 ? $ttl : null;
    }

    public function updateTTL($key, int $ttl): bool
    {
        $this->validateKey($key);
        $success = $this->redis->expire($this->prefix . $key, $ttl);
        return (bool)$success;
    }

    public function increment($key, int $value = 1): int
    {
        $this->validateKey($key);
        $result = $this->redis->incrby($this->prefix . $key, $value);
        $this->updateMetadata($key, 'access');
        return $result;
    }

    public function decrement($key, int $value = 1): int
    {
        $this->validateKey($key);
        $result = $this->redis->decrby($this->prefix . $key, $value);
        $this->updateMetadata($key, 'access');
        return $result;
    }

    private function getExpirationTime($ttl): DateTime
    {
        $now = new DateTime();
        if ($ttl === null) {
            $ttl = $this->defaultTtl;
        }
        if ($ttl instanceof DateInterval) {
            $expiration = $now->add($ttl);
        } elseif (is_int($ttl)) {
            $expiration = $now->add(new DateInterval("PT{$ttl}S"));
        } else {
            throw new InvalidArgumentException("Invalid TTL");
        }
        return $expiration;
    }

    private function evict(): void
    {
        // Redis will automatically evict keys based on the configured maxmemory-policy
        // We don't need to implement eviction logic here
    }

    private function updateMetadata(string $key, string $operation): void
    {
        $now = time();
        $metadata = [
            'lastAccessed' => $now,
            'frequency' => 1,
        ];

        if ($operation === 'access') {
            $this->redis->hincrby($this->metadataKey, "{$key}:frequency", 1);
        }

        $this->redis->hset($this->metadataKey, "{$key}:lastAccessed", $now);
    }

    private function removeMetadata(string $key): void
    {
        $this->redis->hdel($this->metadataKey, ["{$key}:lastAccessed", "{$key}:frequency"]);
    }

    private function validateKey($key): void
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException("Cache key must be a string");
        }
        if (empty($key)) {
            throw new InvalidArgumentException("Cache key cannot be empty");
        }
    }

    public function getMetrics(): array
    {
        $info = $this->redis->info();
        $keyspace = $info['Keyspace'] ?? [];
        $db = reset($keyspace); // Get the first (and usually only) database stats

        return [
            'hitCount' => $db['keyspace_hits'] ?? 0,
            'missCount' => $db['keyspace_misses'] ?? 0,
            'hitRatio' => $db['keyspace_hits'] / ($db['keyspace_hits'] + $db['keyspace_misses']),
            'itemCount' => $db['keys'] ?? 0,
        ];
    }
}

/*
$redisCache = PurpleCacheFactory::createCache('redis', [
    'redis' => ['host' => 'localhost', 'port' => 6379],
    'defaultTtl' => 3600,
    'maxSize' => 1000,
    'evictionPolicy' => 'LRU'
]);
*/
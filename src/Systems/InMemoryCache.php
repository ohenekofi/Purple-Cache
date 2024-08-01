<?php

namespace Purple\Cache\Systems;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class InMemoryCache implements CacheInterface
{
    private array $cache = [];
    private int $defaultTtl;
    private int $maxSize;
    private string $evictionPolicy;
    private int $hitCount = 0;
    private int $missCount = 0;

    public function __construct(int $defaultTtl = 3600, int $maxSize = 100, string $evictionPolicy = 'LRU')
    {
        $this->defaultTtl = $defaultTtl;
        $this->maxSize = $maxSize;
        $this->evictionPolicy = $evictionPolicy;
    }

    public function get($key, $default = null)
    {
        $this->validateKey($key);

        if ($this->has($key)) {
            $this->hitCount++;
            $this->updateMetadata($key, 'access');
            return $this->cache[$key]['value'];
        }

        $this->missCount++;
        return $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);

        if (count($this->cache) >= $this->maxSize) {
            $this->evict();
        }

        $expiration = $this->getExpirationTime($ttl);
        $this->cache[$key] = [
            'value' => $value,
            'expiration' => $expiration->getTimestamp(),
            'lastAccessed' => time(),
            'frequency' => 1,
        ];

        $this->updateMetadata($key, 'set');
        return true;
    }

    public function delete($key): bool
    {
        $this->validateKey($key);

        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            return true;
        }
        return false;
    }

    public function clear(): bool
    {
        $this->cache = [];
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

        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function has($key): bool
    {
        $this->validateKey($key);

        if (isset($this->cache[$key])) {
            if ($this->cache[$key]['expiration'] > time()) {
                return true;
            } else {
                $this->delete($key);  // Remove expired item
            }
        }
        return false;
    }

    public function getTTL($key): ?int
    {
        $this->validateKey($key);

        if (isset($this->cache[$key])) {
            $ttl = $this->cache[$key]['expiration'] - time();
            return $ttl > 0 ? $ttl : null;
        }
        return null;
    }

    public function updateTTL($key, int $ttl): bool
    {
        $this->validateKey($key);

        if (isset($this->cache[$key])) {
            $expiration = $this->getExpirationTime($ttl);
            $this->cache[$key]['expiration'] = $expiration->getTimestamp();
            return true;
        }
        return false;
    }

    public function increment($key, int $value = 1): int
    {
        $this->validateKey($key);

        if (isset($this->cache[$key]) && is_numeric($this->cache[$key]['value'])) {
            $this->cache[$key]['value'] += $value;
            $this->updateMetadata($key, 'access');
            return $this->cache[$key]['value'];
        }
        throw new InvalidArgumentException("Cannot increment non-numeric value");
    }

    public function decrement($key, int $value = 1): int
    {
        $this->validateKey($key);

        if (isset($this->cache[$key]) && is_numeric($this->cache[$key]['value'])) {
            $this->cache[$key]['value'] -= $value;
            $this->updateMetadata($key, 'access');
            return $this->cache[$key]['value'];
        }
        throw new InvalidArgumentException("Cannot decrement non-numeric value");
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
        if (empty($this->cache)) {
            return;
        }

        if ($this->evictionPolicy === 'LRU') {
            $this->evictLRU();
        } elseif ($this->evictionPolicy === 'LFU') {
            $this->evictLFU();
        }
    }

    private function evictLRU(): void
    {
        $leastRecentlyUsed = PHP_INT_MAX;
        $keyToEvict = null;

        foreach ($this->cache as $key => $data) {
            if ($data['lastAccessed'] < $leastRecentlyUsed) {
                $leastRecentlyUsed = $data['lastAccessed'];
                $keyToEvict = $key;
            }
        }

        if ($keyToEvict) {
            $this->delete($keyToEvict);
        }
    }

    private function evictLFU(): void
    {
        $leastFrequent = PHP_INT_MAX;
        $keyToEvict = null;

        foreach ($this->cache as $key => $data) {
            if ($data['frequency'] < $leastFrequent) {
                $leastFrequent = $data['frequency'];
                $keyToEvict = $key;
            }
        }

        if ($keyToEvict) {
            $this->delete($keyToEvict);
        }
    }

    private function updateMetadata(string $key, string $operation): void
    {
        $now = time();
        $this->cache[$key]['lastAccessed'] = $now;

        if ($operation === 'access' || $operation === 'set') {
            $this->cache[$key]['frequency']++;
        }
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
        $totalRequests = $this->hitCount + $this->missCount;
        return [
            'hitCount' => $this->hitCount,
            'missCount' => $this->missCount,
            'hitRatio' => $totalRequests > 0 ? $this->hitCount / $totalRequests : 0,
            'itemCount' => count($this->cache),
        ];
    }
}
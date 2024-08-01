<?php

namespace Purple\Cache\Systems;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class FileCache implements CacheInterface
{
    private string $cacheDir;
    private int $defaultTtl;
    private int $maxSize;
    private string $evictionPolicy;
    private int $hitCount = 0;
    private int $missCount = 0;
    private array $metadata = [];
    private string $metadataFile;

    public function __construct(string $cacheDir, int $defaultTtl = 3600, int $maxSize = 100, string $evictionPolicy = 'LRU')
    {
        $this->cacheDir = $cacheDir;
        $this->defaultTtl = $defaultTtl;
        $this->maxSize = $maxSize;
        $this->evictionPolicy = $evictionPolicy;
        $this->metadataFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'metadata.php';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->loadMetadata();
    }

    private function getPath(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }

    public function get($key, $default = null)
    {
        $this->validateKey($key);

        if ($this->has($key)) {
            $this->hitCount++;
            $path = $this->getPath($key);
            $data = unserialize(file_get_contents($path));
            $this->updateMetadata($key, 'access');
            return $data['value'];
        }

        $this->missCount++;
        return $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);

        if (count($this->metadata) >= $this->maxSize) {
            $this->evict();
        }

        $expiration = $this->getExpirationTime($ttl);
        $data = [
            'value' => $value,
            'expiration' => $expiration->getTimestamp()
        ];

        $path = $this->getPath($key);
        $result = file_put_contents($path, serialize($data)) !== false;

        if ($result) {
            $this->updateMetadata($key, 'set');
            $this->saveMetadata();
        }

        return $result;
    }

    public function delete($key): bool
    {
        $this->validateKey($key);

        $path = $this->getPath($key);
        if (file_exists($path)) {
            $result = unlink($path);
            if ($result) {
                unset($this->metadata[$key]);
                $this->saveMetadata();
            }
            return $result;
        }
        return false;
    }

    public function clear(): bool
    {
        array_map('unlink', glob($this->cacheDir . '/*.cache'));
        $this->metadata = [];
        $this->saveMetadata();
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

        $path = $this->getPath($key);
        if (file_exists($path)) {
            $data = unserialize(file_get_contents($path));
            return $data['expiration'] > time();
        }
        return false;
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
        if (empty($this->metadata)) {
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
        asort($this->metadata);
        $keyToEvict = key($this->metadata);
        $this->delete($keyToEvict);
    }

    private function evictLFU(): void
    {
        $leastFrequent = PHP_INT_MAX;
        $keyToEvict = null;

        foreach ($this->metadata as $key => $data) {
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
        if (!isset($this->metadata[$key])) {
            $this->metadata[$key] = [
                'lastAccessed' => $now,
                'frequency' => 0,
            ];
        }

        $this->metadata[$key]['lastAccessed'] = $now;

        if ($operation === 'access' || $operation === 'set') {
            $this->metadata[$key]['frequency']++;
        }
    }

    private function loadMetadata(): void
    {
        if (file_exists($this->metadataFile)) {
            $this->metadata = include $this->metadataFile;
        }
    }

    private function saveMetadata(): void
    {
        $data = "<?php\nreturn " . var_export($this->metadata, true) . ";\n";
        file_put_contents($this->metadataFile, $data);
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
            'itemCount' => count($this->metadata),
        ];
    }
}
/*
This creates a cache with a default TTL of 1 hour, a maximum of 1000 items, and using the LRU eviction policy. You can then use the standard PSR-16 methods (get, set, delete, etc.) to interact with the cache.
*/
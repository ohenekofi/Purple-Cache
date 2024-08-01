<?php

namespace Purple\Cache;

use Purple\Cache\Systems\RedisCache;
use Purple\Cache\Systems\FileCache;
use Purple\Cache\Systems\InMemoryCache;
use Psr\SimpleCache\CacheInterface;
use Predis\Client;

class PurpleCacheFactory
{
    public static function createCache(string $type, array $config = []): CacheInterface
    {
        switch ($type) {
            case 'redis':
                return self::createRedisCache($config);
            case 'file':
                return self::createFileCache($config);
            case 'memory':
                return self::createInMemoryCache($config);
            default:
                throw new \InvalidArgumentException('Invalid cache type specified.');
        }
    }

    private static function createRedisCache(array $config): RedisCache
    {
        if (!isset($config['redis'])) {
            throw new \InvalidArgumentException('Redis configuration is required.');
        }
        $redisClient = new Client($config['redis']);
        $defaultTtl = $config['defaultTtl'] ?? 3600;
        $maxSize = $config['maxSize'] ?? 100;
        $evictionPolicy = $config['evictionPolicy'] ?? 'LRU';
        return new RedisCache($redisClient, $defaultTtl, $maxSize, $evictionPolicy);
    }

    private static function createFileCache(array $config): FileCache
    {
        if (!isset($config['cacheDir'])) {
            throw new \InvalidArgumentException('Cache directory is required.');
        }
        $cacheDir = $config['cacheDir'];
        $defaultTtl = $config['defaultTtl'] ?? 3600;
        $maxSize = $config['maxSize'] ?? 100;
        $evictionPolicy = $config['evictionPolicy'] ?? 'LRU';
        return new FileCache($cacheDir, $defaultTtl, $maxSize, $evictionPolicy);
    }

    private static function createInMemoryCache(array $config): InMemoryCache
    {
        $defaultTtl = $config['defaultTtl'] ?? 3600;
        $maxSize = $config['maxSize'] ?? 100;
        $evictionPolicy = $config['evictionPolicy'] ?? 'LRU';
        return new InMemoryCache($defaultTtl, $maxSize, $evictionPolicy);
    }
}

/*
// Create a Redis cache
$redisCache = PurpleCacheFactory::createCache('redis', [
    'redis' => ['host' => 'localhost', 'port' => 6379],
    'defaultTtl' => 7200,
    'maxSize' => 1000,
    'evictionPolicy' => 'LFU'
]);

// Create a File cache
$fileCache = PurpleCacheFactory::createCache('file', [
    'cacheDir' => '/path/to/cache',
    'defaultTtl' => 3600,
    'maxSize' => 500,
    'evictionPolicy' => 'LRU'
]);

// Create an In-Memory cache
$memoryCache = PurpleCacheFactory::createCache('memory', [
    'defaultTtl' => 1800,
    'maxSize' => 200,
    'evictionPolicy' => 'LFU'
]);
*/
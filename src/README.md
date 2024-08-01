# Purple Cache Library

## Overview
The Purple Cache Library provides a flexible caching mechanism for your application with support for various cache types including Redis, file-based, and in-memory caching. This library adheres to the PSR-16 caching interface for standardization and ease of use.

## Installation
To install the Purple Cache Library, use Composer:
```bash
composer require purple/cache
```

## Cache Types

### Redis Cache
A cache implementation using Redis for storage.

### File Cache
A cache implementation using the file system for storage.

### In-Memory Cache
A cache implementation using the application memory for storage.

## Factory Class: `PurpleCacheFactory`

The `PurpleCacheFactory` class is responsible for creating instances of different cache types. It provides a single method, `createCache`, which takes a cache type and an optional configuration array.

### Usage

#### Create a Redis Cache
```php
use Purple\Libs\Cache\Factory\PurpleCacheFactory;

$redisCache = PurpleCacheFactory::createCache('redis', [
    'redis' => ['host' => 'localhost', 'port' => 6379],
    'defaultTtl' => 7200,
    'maxSize' => 1000,
    'evictionPolicy' => 'LFU'
]);
```

#### Create a File Cache
```php
$fileCache = PurpleCacheFactory::createCache('file', [
    'cacheDir' => '/path/to/cache',
    'defaultTtl' => 3600,
    'maxSize' => 500,
    'evictionPolicy' => 'LRU'
]);
```

#### Create an In-Memory Cache
```php
$memoryCache = PurpleCacheFactory::createCache('memory', [
    'defaultTtl' => 1800,
    'maxSize' => 200,
    'evictionPolicy' => 'LFU'
]);
```

## Cache Interface

### PSR-16 Methods

All cache implementations (RedisCache, FileCache, InMemoryCache) adhere to the `Psr\SimpleCache\CacheInterface`. Here are the available methods:

- `get($key, $default = null)`
- `set($key, $value, $ttl = null): bool`
- `delete($key): bool`
- `clear(): bool`
- `getMultiple($keys, $default = null): iterable`
- `setMultiple($values, $ttl = null): bool`
- `deleteMultiple($keys): bool`
- `has($key): bool`

### Example: FileCache

The `FileCache` class provides a file-based caching mechanism.

#### Constructor

```php
public function __construct(string $cacheDir, int $defaultTtl = 3600, int $maxSize = 100, string $evictionPolicy = 'LRU')
```

- **$cacheDir**: Directory where cache files will be stored.
- **$defaultTtl**: Default time-to-live (TTL) for cache items.
- **$maxSize**: Maximum number of items in the cache.
- **$evictionPolicy**: Eviction policy (`LRU` or `LFU`).

#### Methods

##### get($key, $default = null)
Retrieves the value of a cache item.

##### set($key, $value, $ttl = null): bool
Stores a value in the cache.

##### delete($key): bool
Deletes a cache item.

##### clear(): bool
Clears the entire cache.

##### getMultiple($keys, $default = null): iterable
Retrieves multiple cache items.

##### setMultiple($values, $ttl = null): bool
Stores multiple values in the cache.

##### deleteMultiple($keys): bool
Deletes multiple cache items.

##### has($key): bool
Checks if a cache item exists.

##### getMetrics(): array
Returns cache metrics such as hit count, miss count, hit ratio, and item count.

## Configuration

### Redis Cache Configuration

- `redis`: Array containing Redis connection parameters.
- `defaultTtl`: Default time-to-live for cache items.
- `maxSize`: Maximum number of items in the cache.
- `evictionPolicy`: Eviction policy (`LRU` or `LFU`).

### File Cache Configuration

- `cacheDir`: Directory for storing cache files.
- `defaultTtl`: Default time-to-live for cache items.
- `maxSize`: Maximum number of items in the cache.
- `evictionPolicy`: Eviction policy (`LRU` or `LFU`).

### In-Memory Cache Configuration

- `defaultTtl`: Default time-to-live for cache items.
- `maxSize`: Maximum number of items in the cache.
- `evictionPolicy`: Eviction policy (`LRU` or `LFU`).

## Eviction Policies

### LRU (Least Recently Used)
Evicts the least recently accessed items first.

### LFU (Least Frequently Used)
Evicts the least frequently accessed items first.

## Metadata Management

The `FileCache` class manages metadata to track cache item access and frequency. Metadata is stored in a file (`metadata.php`) within the cache directory.

### Methods

- `loadMetadata()`: Loads metadata from the metadata file.
- `saveMetadata()`: Saves metadata to the metadata file.
- `updateMetadata(string $key, string $operation)`: Updates metadata for a cache item.

## Metrics

The `getMetrics()` method in each cache implementation returns an array of cache metrics:

- `hitCount`: Number of cache hits.
- `missCount`: Number of cache misses.
- `hitRatio`: Ratio of hits to total requests.
- `itemCount`: Number of items in the cache.

### Example
```php
$metrics = $fileCache->getMetrics();
echo "Hit Count: " . $metrics['hitCount'] . "\n";
echo "Miss Count: " . $metrics['missCount'] . "\n";
echo "Hit Ratio: " . $metrics['hitRatio'] . "\n";
echo "Item Count: " . $metrics['itemCount'] . "\n";
```

## Error Handling

The library throws `InvalidArgumentException` for invalid cache keys and configurations. Ensure to handle these exceptions in your application code.

## Conclusion

The Purple Cache Library provides a robust caching solution with support for Redis, file-based, and in-memory caching. By adhering to PSR-16 standards, it ensures interoperability and ease of integration with other libraries and frameworks. Configure and use the cache that best suits your application's needs and manage cache efficiently with eviction policies and metrics tracking.
<?php
/**
 * Search w/Elastic plugin for Craft CMS 5.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\services;

use Craft;
use craft\base\Component;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\caching\CacheInterface;
use yii\web\TooManyRequestsHttpException;

/**
 * Rate Limiter Service
 *
 * Implements token bucket algorithm for rate limiting search endpoints
 * to prevent DoS attacks and ensure fair resource usage.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class RateLimiterService extends Component
{
    /**
     * @var int Cache duration for rate limit data (1 hour)
     */
    private const CACHE_DURATION = 3600;

    /**
     * @var string Cache key prefix for rate limit data
     */
    private const CACHE_PREFIX = 'searchwithelastic_ratelimit_';

    /**
     * @var CacheInterface The cache component
     */
    private CacheInterface $cache;

    /**
     * Initializes the service
     */
    public function init(): void
    {
        parent::init();
        $this->cache = Craft::$app->getCache();
    }

    /**
     * Check if a request should be allowed based on rate limiting rules
     *
     * @param string|null $identifier Optional custom identifier, otherwise uses automatic detection
     * @return bool True if request is allowed, false if rate limited
     */
    public function allowRequest(?string $identifier = null): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Skip if rate limiting is disabled
        if (!$settings->rateLimitingEnabled) {
            return true;
        }

        // Get identifier if not provided
        if ($identifier === null) {
            $identifier = $this->getIdentifier();
        }

        // Check if IP is exempt
        if ($this->isExempt($identifier)) {
            return true;
        }

        // Get current bucket state
        $bucket = $this->getBucket($identifier);
        
        // Check if request can be processed
        if ($bucket['tokens'] >= 1) {
            // Consume a token
            $bucket['tokens']--;
            $this->saveBucket($identifier, $bucket);
            return true;
        }

        return false;
    }

    /**
     * Consume tokens from the rate limit bucket
     *
     * @param string|null $identifier Optional custom identifier
     * @param int $tokens Number of tokens to consume (default: 1)
     * @throws TooManyRequestsHttpException if rate limit exceeded
     */
    public function consumeTokens(?string $identifier = null, int $tokens = 1): void
    {
        if (!$this->allowRequest($identifier)) {
            $retryAfter = $this->getRetryAfter($identifier);
            
            // Log the rate limit violation
            Craft::warning(
                sprintf('Rate limit exceeded for identifier: %s', $identifier ?? $this->getIdentifier()),
                __METHOD__
            );

            throw new TooManyRequestsHttpException(
                $retryAfter,
                Craft::t('search-with-elastic', 'Rate limit exceeded. Please try again later.')
            );
        }
    }

    /**
     * Get the number of seconds until the rate limit resets
     *
     * @param string|null $identifier Optional custom identifier
     * @return int Seconds until tokens are replenished
     */
    public function getRetryAfter(?string $identifier = null): int
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        
        // Calculate seconds per token
        $secondsPerToken = 60 / $settings->rateLimitRequestsPerMinute;
        
        // Return the time until at least one token is available
        return (int) ceil($secondsPerToken);
    }

    /**
     * Get remaining tokens for an identifier
     *
     * @param string|null $identifier Optional custom identifier
     * @return int Number of remaining tokens
     */
    public function getRemainingTokens(?string $identifier = null): int
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        if (!$settings->rateLimitingEnabled) {
            return PHP_INT_MAX;
        }

        if ($identifier === null) {
            $identifier = $this->getIdentifier();
        }

        if ($this->isExempt($identifier)) {
            return PHP_INT_MAX;
        }

        $bucket = $this->getBucket($identifier);
        return max(0, (int) floor($bucket['tokens']));
    }

    /**
     * Reset rate limit for a specific identifier
     *
     * @param string|null $identifier Optional custom identifier
     */
    public function reset(?string $identifier = null): void
    {
        if ($identifier === null) {
            $identifier = $this->getIdentifier();
        }

        $cacheKey = $this->getCacheKey($identifier);
        $this->cache->delete($cacheKey);
    }

    /**
     * Clear all rate limit data
     */
    public function clearAll(): void
    {
        // Since we can't enumerate all keys with a prefix in Yii cache,
        // we'll rely on TTL expiration for automatic cleanup
        Craft::info('Rate limit data will expire based on TTL', __METHOD__);
    }

    /**
     * Get the identifier for the current request
     *
     * @return string The identifier (IP or user ID)
     */
    private function getIdentifier(): string
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        $request = Craft::$app->getRequest();

        if ($settings->rateLimitTrackingMethod === 'user') {
            $user = Craft::$app->getUser()->getIdentity();
            if ($user !== null) {
                return 'user_' . $user->id;
            }
        }

        // Default to IP address
        return 'ip_' . $request->getUserIP();
    }

    /**
     * Check if an identifier is exempt from rate limiting
     *
     * @param string $identifier The identifier to check
     * @return bool True if exempt
     */
    private function isExempt(string $identifier): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Extract IP from identifier if it's an IP-based identifier
        if (str_starts_with($identifier, 'ip_')) {
            $ip = substr($identifier, 3);
            
            foreach ($settings->rateLimitExemptIps as $exemptIp) {
                if ($this->ipMatches($ip, $exemptIp)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if an IP matches a pattern (supports CIDR notation)
     *
     * @param string $ip The IP to check
     * @param string $pattern The pattern to match against
     * @return bool True if matches
     */
    private function ipMatches(string $ip, string $pattern): bool
    {
        // Direct IP match
        if ($ip === $pattern) {
            return true;
        }

        // CIDR notation support
        if (str_contains($pattern, '/')) {
            [$subnet, $bits] = explode('/', $pattern);
            $bits = (int) $bits;
            
            // IPv4
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && 
                filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = ip2long($ip);
                $subnet = ip2long($subnet);
                $mask = -1 << (32 - $bits);
                $subnet &= $mask;
                return ($ip & $mask) === $subnet;
            }
            
            // IPv6 support would require additional implementation
        }

        return false;
    }

    /**
     * Get the token bucket for an identifier
     *
     * @param string $identifier The identifier
     * @return array The bucket data with 'tokens' and 'last_refill' keys
     */
    private function getBucket(string $identifier): array
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        $cacheKey = $this->getCacheKey($identifier);
        
        $bucket = $this->cache->get($cacheKey);
        
        if ($bucket === false) {
            // Initialize new bucket with full tokens
            $bucket = [
                'tokens' => (float) $settings->rateLimitRequestsPerMinute + $settings->rateLimitBurstSize,
                'last_refill' => microtime(true),
            ];
        } else {
            // Refill tokens based on elapsed time
            $now = microtime(true);
            $elapsed = $now - $bucket['last_refill'];
            
            // Calculate tokens to add (tokens per second * elapsed seconds)
            $tokensPerSecond = $settings->rateLimitRequestsPerMinute / 60;
            $tokensToAdd = $elapsed * $tokensPerSecond;
            
            // Add tokens up to the maximum (rate limit + burst)
            $maxTokens = $settings->rateLimitRequestsPerMinute + $settings->rateLimitBurstSize;
            $bucket['tokens'] = min($maxTokens, $bucket['tokens'] + $tokensToAdd);
            $bucket['last_refill'] = $now;
        }
        
        return $bucket;
    }

    /**
     * Save the token bucket for an identifier
     *
     * @param string $identifier The identifier
     * @param array $bucket The bucket data
     */
    private function saveBucket(string $identifier, array $bucket): void
    {
        $cacheKey = $this->getCacheKey($identifier);
        $this->cache->set($cacheKey, $bucket, self::CACHE_DURATION);
    }

    /**
     * Get the cache key for an identifier
     *
     * @param string $identifier The identifier
     * @return string The cache key
     */
    private function getCacheKey(string $identifier): string
    {
        return self::CACHE_PREFIX . $identifier;
    }
}

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

namespace pennebaker\searchwithelastic\behaviors;

use Craft;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\ActionEvent;
use yii\base\Behavior;
use yii\web\Controller;
use yii\web\TooManyRequestsHttpException;

/**
 * Rate Limit Behavior
 *
 * Applies rate limiting to controller actions using the plugin's
 * RateLimiterService and configuration settings.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class RateLimitBehavior extends Behavior
{
    /**
     * @var int Maximum number of requests per window (fallback if plugin settings disabled)
     * @since 4.0.0
     */
    public int $maxRequests = 60;

    /**
     * @var int Time window in seconds (fallback if plugin settings disabled)
     * @since 4.0.0
     */
    public int $window = 60;

    /**
     * @var array Actions to apply rate limiting to (empty = all actions)
     * @since 4.0.0
     */
    public array $actions = [];

    /**
     * @var bool Whether to use plugin settings for rate limiting config
     * @since 4.0.0
     */
    public bool $usePluginSettings = true;

    /**
     * @inheritdoc
     * @since 4.0.0
     */
    public function events(): array
    {
        return [
            Controller::EVENT_BEFORE_ACTION => 'beforeAction',
            Controller::EVENT_AFTER_ACTION => 'afterAction',
        ];
    }

    /**
     * Check rate limit before action
     *
     * @param ActionEvent $event
     * @throws TooManyRequestsHttpException if rate limit exceeded
     * @since 4.0.0
     */
    public function beforeAction(ActionEvent $event): void
    {
        // Skip if action not in rate limited list
        if (!empty($this->actions) && !in_array($event->action->id, $this->actions, true)) {
            return;
        }

        $settings = SearchWithElastic::getInstance()->getSettings();

        // Use plugin settings if enabled and configured to do so
        if ($this->usePluginSettings && $settings->rateLimitingEnabled) {
            $rateLimiter = SearchWithElastic::getInstance()->rateLimiter;
            
            try {
                $rateLimiter->consumeTokens();
            } catch (TooManyRequestsHttpException $e) {
                // Get the retry-after value from the rate limiter service
                $retryAfter = $rateLimiter->getRetryAfter();
                
                // Add rate limit headers to response
                $response = Craft::$app->getResponse();
                $response->getHeaders()
                    ->set('X-RateLimit-Limit', (string) $settings->rateLimitRequestsPerMinute)
                    ->set('X-RateLimit-Remaining', '0')
                    ->set('X-RateLimit-Reset', (string) (time() + $retryAfter))
                    ->set('Retry-After', (string) $retryAfter);
                
                throw $e;
            }
        } else if (!$this->usePluginSettings) {
            // Fallback to behavior-specific settings
            $this->applySimpleRateLimit($event);
        }
    }

    /**
     * Add rate limit headers after successful action
     *
     * @param ActionEvent $event
     * @since 4.0.0
     */
    public function afterAction(ActionEvent $event): void
    {
        // Skip if action not in rate limited list
        if (!empty($this->actions) && !in_array($event->action->id, $this->actions, true)) {
            return;
        }

        $settings = SearchWithElastic::getInstance()->getSettings();

        if ($this->usePluginSettings && $settings->rateLimitingEnabled) {
            $rateLimiter = SearchWithElastic::getInstance()->rateLimiter;
            $remaining = $rateLimiter->getRemainingTokens();
            
            $response = Craft::$app->getResponse();
            $response->getHeaders()
                ->set('X-RateLimit-Limit', (string) $settings->rateLimitRequestsPerMinute)
                ->set('X-RateLimit-Remaining', (string) $remaining)
                ->set('X-RateLimit-Reset', (string) (time() + 60));
        }
    }

    /**
     * Apply simple rate limiting using behavior settings
     *
     * @param ActionEvent $event
     * @throws TooManyRequestsHttpException
     * @since 4.0.0
     */
    private function applySimpleRateLimit(ActionEvent $event): void
    {
        $identifier = $this->getIdentifier();
        $cacheKey = 'search_with_elastic_rate_limit_' . $identifier;
        
        $cache = Craft::$app->getCache();
        
        // Get current request count
        $requests = $cache->get($cacheKey);
        if ($requests === false) {
            $requests = 0;
        }
        
        // Check if limit exceeded
        if ($requests >= $this->maxRequests) {
            Craft::warning(
                sprintf('Rate limit exceeded for identifier: %s', $identifier),
                __METHOD__
            );
            
            $response = Craft::$app->getResponse();
            $response->getHeaders()
                ->set('X-RateLimit-Limit', (string) $this->maxRequests)
                ->set('X-RateLimit-Remaining', '0')
                ->set('X-RateLimit-Reset', (string) (time() + $this->window))
                ->set('Retry-After', (string) $this->window);
            
            throw new TooManyRequestsHttpException(
                $this->window,
                Craft::t('search-with-elastic', 'Rate limit exceeded. Please try again later.')
            );
        }
        
        // Increment counter
        $requests++;
        $cache->set($cacheKey, $requests, $this->window);
        
        // Set rate limit headers
        $response = Craft::$app->getResponse();
        $response->getHeaders()
            ->set('X-RateLimit-Limit', (string) $this->maxRequests)
            ->set('X-RateLimit-Remaining', (string) max(0, $this->maxRequests - $requests))
            ->set('X-RateLimit-Reset', (string) (time() + $this->window));
    }

    /**
     * Get identifier for rate limiting
     *
     * @return string
     * @since 4.0.0
     */
    private function getIdentifier(): string
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        $request = Craft::$app->getRequest();

        // Use plugin settings for tracking method if available
        if ($this->usePluginSettings && $settings->rateLimitingEnabled) {
            if ($settings->rateLimitTrackingMethod === 'user') {
                $user = Craft::$app->getUser()->getIdentity();
                if ($user !== null) {
                    return 'user_' . $user->id;
                }
            }
        } else {
            // Fallback: try user first
            $user = Craft::$app->getUser()->getIdentity();
            if ($user !== null) {
                return 'user_' . $user->id;
            }
        }

        // Default to IP address
        return 'ip_' . $request->getUserIP();
    }
}

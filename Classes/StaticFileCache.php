<?php

/**
 * StaticFileCache.
 */

declare(strict_types = 1);

namespace SFC\Staticfilecache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SFC\Staticfilecache\Cache\UriFrontend;
use SFC\Staticfilecache\Service\CacheService;
use SFC\Staticfilecache\Service\ConfigurationService;
use SFC\Staticfilecache\Service\DateTimeService;
use SFC\Staticfilecache\Service\TagService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * StaticFileCache.
 */
class StaticFileCache extends StaticFileCacheObject
{
    /**
     * Cache.
     *
     * @var UriFrontend
     */
    protected $cache;

    /**
     * Cache.
     *
     * @var Dispatcher
     */
    protected $signalDispatcher;

    /**
     * Constructs this object.
     */
    public function __construct()
    {
        try {
            $this->cache = GeneralUtility::makeInstance(CacheService::class)->get();
        } catch (\Exception $exception) {
            $this->logger->error('Problems getting the cache: ' . $exception->getMessage());
        }
        $this->signalDispatcher = GeneralUtility::makeInstance(Dispatcher::class);
    }

    /**
     * Check if the SFC should create the cache.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     */
    public function insertPageInCache(ServerRequestInterface $request, ResponseInterface $response)
    {
        $pObj = $GLOBALS['TSFE'];
        $isStaticCached = false;

        // cache rules
        $ruleArguments = [
            'frontendController' => $pObj,
            'request' => $request,
            'explanation' => [],
            'skipProcessing' => false,
        ];
        $ruleArguments = $this->dispatch('cacheRule', $ruleArguments);
        $explanation = (array)$ruleArguments['explanation'];

        if (!$ruleArguments['skipProcessing']) {
            $uri = (string)$request->getUri();
            $timeOutTime = $this->calculateTimeout($pObj);

            // Don't continue if there is already an existing valid cache entry and we've got an invalid now.
            // Prevents overriding if a logged in user is checking the page in a second call
            // see https://forge.typo3.org/issues/67526
            if (!empty($explanation) && $this->hasValidCacheEntry($uri)) {
                return;
            }

            $tagService = GeneralUtility::makeInstance(TagService::class);

            // The page tag pageId_NN is included in $pObj->pageCacheTags
            $cacheTags = $tagService->getTags();
            $cacheTags[] = 'sfc_pageId_' . $pObj->page['uid'];
            $cacheTags[] = 'sfc_domain_' . \str_replace('.', '_', \parse_url($uri, PHP_URL_HOST));

            // This is supposed to have "&& !$pObj->beUserLogin" in there as well
            // This fsck's up the ctrl-shift-reload hack, so I pulled it out.
            if (empty($explanation)) {
                // $content = (string)$response->getBody()->getContents();
                $content = $pObj->content;

                // Signal: Process content before writing to static cached file
                $contentArguments = [
                    'frontendController' => $pObj,
                    'content' => $content,
                    'timeOutSeconds' => $timeOutTime - (new DateTimeService())->getCurrentTime(),
                ];
                $contentArguments = $this->dispatch(
                    'processContent',
                    $contentArguments
                );
                $content = $contentArguments['content'];
                $timeOutSeconds = $contentArguments['timeOutSeconds'];
                $isStaticCached = true;

                $tagService->send();
            } else {
                $cacheTags[] = 'explanation';
                $content = $explanation;
                $timeOutSeconds = 0;
            }

            // create cache entry
            $this->cache->set($uri, $content, $cacheTags, $timeOutSeconds);
        }

        // Signal: Post process (no matter whether content was cached statically)
        $postProcessArguments = [
            'frontendController' => $pObj,
            'isStaticCached' => $isStaticCached,
        ];
        $this->dispatch('postProcess', $postProcessArguments);
    }

    /**
     * Calculate timeout
     *
     * @param TypoScriptFrontendController $tsfe
     * @return int
     */
    protected function calculateTimeout(TypoScriptFrontendController $tsfe): int
    {
        if (!\is_array($tsfe->page)) {
            $this->logger->warning('TSFE to not contains a valid page record?! Please check: https://github.com/lochmueller/staticfilecache/issues/150');
            return 0;
        }
        $timeOutTime = $tsfe->get_cache_timeout();

        // If page has a endtime before the current timeOutTime, use it instead:
        if ($tsfe->page['endtime'] > 0 && $tsfe->page['endtime'] < $timeOutTime) {
            $timeOutTime = $tsfe->page['endtime'];
        }
        return (int)$timeOutTime;
    }

    /**
     * Format the given timestamp.
     *
     * @param int $timestamp
     *
     * @return string
     */
    protected function formatTimestamp($timestamp): string
    {
        return \strftime('%d-%m-%y %H:%M', $timestamp);
    }

    /**
     * Determines whether the given $uri has a valid cache entry.
     *
     * @param string $uri
     *
     * @return bool is available and valid
     */
    protected function hasValidCacheEntry($uri): bool
    {
        $entry = $this->cache->get($uri);

        return false !== $entry &&
            empty($entry['explanation']) &&
            $entry['expires'] >= (new DateTimeService())->getCurrentTime();
    }

    /**
     * Call Dispatcher.
     *
     * @param string $signalName
     * @param array  $arguments
     *
     * @return mixed
     */
    protected function dispatch(string $signalName, array $arguments)
    {
        try {
            return $this->signalDispatcher->dispatch(__CLASS__, $signalName, $arguments);
        } catch (\Exception $exception) {
            $this->logger->error('Problems by calling signal: ' . $exception->getMessage() . ' / ' . $exception->getFile() . ':' . $exception->getLine());

            return $arguments;
        }
    }
}

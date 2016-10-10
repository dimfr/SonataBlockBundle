<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\BlockBundle\Templating\Helper;

use Doctrine\Common\Util\ClassUtils;
use Sonata\BlockBundle\Block\BlockContextInterface;
use Sonata\BlockBundle\Block\BlockContextManagerInterface;
use Sonata\BlockBundle\Block\BlockRendererInterface;
use Sonata\BlockBundle\Block\BlockServiceManagerInterface;
use Sonata\BlockBundle\Cache\HttpCacheHandlerInterface;
use Sonata\BlockBundle\Event\BlockEvent;
use Sonata\BlockBundle\Model\BlockInterface;
use Sonata\BlockBundle\Util\RecursiveBlockIterator;
use Sonata\Cache\CacheAdapterInterface;
use Sonata\Cache\CacheManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Templating\Helper\Helper;

class BlockHelper extends Helper
{
    /**
     * @var ContainerInterface
     */
    protected $container;
    /**
     * @var BlockServiceManagerInterface
     */
    protected $blockServiceManager;

    /**
     * @var CacheManagerInterface
     */
    protected $cacheManager;

    /**
     * @var array
     */
    protected $cacheBlocks;

    /**
     * @var BlockRendererInterface
     */
    protected $blockRenderer;

    /**
     * @var BlockContextManagerInterface
     */
    protected $blockContextManager;

    /**
     * @var HttpCacheHandlerInterface
     */
    protected $cacheHandler;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * This property is a state variable holdings all assets used by the block for the current PHP request
     * It is used to correctly render the javascripts and stylesheets tags on the main layout.
     *
     * @var array
     */
    protected $assets;

    /**
     * @var array
     */
    protected $traces;

    /**
     * @var Stopwatch
     */
    protected $stopwatch;

    /**
     * BlockHelper constructor.
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->blockServiceManager = $container->get('sonata.block.manager');
        $this->cacheBlocks = $container->getParameter('sonata_block.cache_blocks');
        $this->blockRenderer = $container->get('sonata.block.renderer');
        $this->eventDispatcher = $container->get('event_dispatcher');
        $this->cacheManager = $container->has('sonata.cache.manager') ? $container->get('sonata.cache.manager') : null;
        $this->blockContextManager = $container->get('sonata.block.context_manager');
        $this->cacheHandler = $container->has('sonata.block.cache.handler.default') ? $container->get('sonata.block.cache.handler.default') : null;
        $this->stopwatch = $container->has('debug.stopwatch') ? $container->get('debug.stopwatch') : null;

        $this->container = $container;

        $this->assets = [
            'js'  => [],
            'css' => [],
        ];

        $this->traces = [
            '_events' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'sonata_block';
    }

    /**
     * @param string $media    Unused, only kept to not break existing code
     * @param string $basePath Base path to prepend to the stylesheet urls.
     *
     * @return array|string
     */
    public function includeJavascripts($media, $basePath = '')
    {

        $parts = [];

        foreach ($this->assets['js'] as $js) {
            if ($js[0] === '@') {
                $parts[] = $this->getBaseAssetExtension()->asseticJs(ltrim($js, '@'));
            } else {
                $parts[] = sprintf('<script src="%s%s" type="text/javascript"></script>'.PHP_EOL, $basePath, $js);
            }
        }

        return implode('', $parts);
    }

    /**
     * @param string $media    The css media type to use: all|screen|...
     * @param string $basePath Base path to prepend to the stylesheet urls.
     *
     * @return array|string
     */
    public function includeStylesheets($media, $basePath = '')
    {
        if (0 === count($this->assets['css'])) {
            return '';
        }

        $parts = [];

        foreach ($this->assets['css'] as $stylesheet) {
            if ($stylesheet[0] === '@') {
                $parts[] = $this->getBaseAssetExtension()->asseticCss(ltrim($stylesheet, '@'));
            } else {
                $parts[] = sprintf("<style type='text/css' media='%s'>%s</style>".PHP_EOL, $media, $stylesheet);
            }
        }

        return implode('', $parts);
    }

    /**
     * @param string $name
     * @param array  $options
     *
     * @return string
     */
    public function renderEvent($name, array $options = [])
    {
        $eventName = sprintf('sonata.block.event.%s', $name);

        $event = $this->eventDispatcher->dispatch($eventName, new BlockEvent($options));

        $content = '';

        foreach ($event->getBlocks() as $block) {
            $content .= $this->render($block);
        }

        if ($this->stopwatch) {
            $this->traces['_events'][uniqid()] = [
                'template_code' => $name,
                'event_name'    => $eventName,
                'blocks'        => $this->getEventBlocks($event),
                'listeners'     => $this->getEventListeners($eventName),
            ];
        }

        return $content;
    }

    /**
     * Check if a given block type exists.
     *
     * @param string $type Block type to check for
     *
     * @return bool
     */
    public function exists($type)
    {
        return $this->blockContextManager->exists($type);
    }

    /**
     * @param mixed $block
     * @param array $options
     *
     * @return null|Response
     */
    public function render($block, array $options = [])
    {
        $blockContext = $this->blockContextManager->get($block, $options);

        if (!$blockContext instanceof BlockContextInterface) {
            return '';
        }

        $stats = [];

        if ($this->stopwatch) {
            $stats = $this->startTracing($blockContext->getBlock());
        }

        $service = $this->blockServiceManager->get($blockContext->getBlock());

        $this->computeAssets($blockContext, $stats);

        $useCache = $blockContext->getSetting('use_cache');

        $cacheKeys = $response = false;
        $cacheService = $useCache ? $this->getCacheService($blockContext->getBlock(), $stats) : false;
        if ($cacheService) {
            $cacheKeys = array_merge($service->getCacheKeys($blockContext->getBlock()), $blockContext->getSetting('extra_cache_keys'));

            if ($this->stopwatch) {
                $stats['cache']['keys'] = $cacheKeys;
            }

            // Please note, some cache handler will always return true (js for instance)
            // This will allows to have a non cacheable block, but the global page can still be cached by
            // a reverse proxy, as the generated page will never get the generated Response from the block.
            if ($cacheService->has($cacheKeys)) {
                $cacheElement = $cacheService->get($cacheKeys);

                if ($this->stopwatch) {
                    $stats['cache']['from_cache'] = false;
                }

                if (!$cacheElement->isExpired() && $cacheElement->getData() instanceof Response) {

                    /* @var Response $response */

                    if ($this->stopwatch) {
                        $stats['cache']['from_cache'] = true;
                    }

                    $response = $cacheElement->getData();
                }
            }
        }

        if (!$response) {
            $recorder = null;
            if ($this->cacheManager) {
                $recorder = $this->cacheManager->getRecorder();

                if ($recorder) {
                    $recorder->add($blockContext->getBlock());
                    $recorder->push();
                }
            }

            $response = $this->blockRenderer->render($blockContext);
            $contextualKeys = $recorder ? $recorder->pop() : [];

            if ($this->stopwatch) {
                $stats['cache']['contextual_keys'] = $contextualKeys;
            }

            if ($response->isCacheable() && $cacheKeys && $cacheService) {
                $cacheService->set($cacheKeys, $response, $response->getTtl(), $contextualKeys);
            }
        }

        if ($this->stopwatch) {
            $stats['cache']['created_at'] = $response->getDate();
            $stats['cache']['ttl'] = $response->getTtl() ?: 0;
            $stats['cache']['age'] = $response->getAge();
        }

        // update final ttl for the whole Response
        if ($this->cacheHandler) {
            $this->cacheHandler->updateMetadata($response, $blockContext);
        }

        if ($this->stopwatch) {
            $this->stopTracing($blockContext->getBlock(), $stats);
        }

        return $response->getContent();
    }

    /**
     * Returns the rendering traces.
     *
     * @return array
     */
    public function getTraces()
    {
        return $this->traces;
    }

    /**
     * Traverse the parent block and its children to retrieve the correct list css and javascript only for main block.
     *
     * @param BlockContextInterface $blockContext
     * @param array                 $stats
     */
    protected function computeAssets(BlockContextInterface $blockContext, array &$stats = null)
    {
        if ($blockContext->getBlock()->hasParent()) {
            return;
        }

        $service = $this->blockServiceManager->get($blockContext->getBlock());

        $assets = [
            'js'  => $service->getJavascripts('all'),
            'css' => $service->getStylesheets('all'),
        ];

        if ($blockContext->getBlock()->hasChildren()) {
            $iterator = new \RecursiveIteratorIterator(new RecursiveBlockIterator($blockContext->getBlock()
                ->getChildren()));

            foreach ($iterator as $block) {
                $assets = [
                    'js'  => array_merge($this->blockServiceManager->get($block)->getJavascripts('all'), $assets['js']),
                    'css' => array_merge($this->blockServiceManager->get($block)
                        ->getStylesheets('all'), $assets['css']),
                ];
            }
        }

        if ($this->stopwatch) {
            $stats['assets'] = $assets;
        }

        $this->assets = [
            'js'  => array_unique(array_merge($assets['js'], $this->assets['js'])),
            'css' => array_unique(array_merge($assets['css'], $this->assets['css'])),
        ];
    }

    /**
     * @param BlockInterface $block
     *
     * @return array
     */
    protected function startTracing(BlockInterface $block)
    {
        $this->traces[$block->getId()] = $this->stopwatch->start(sprintf('%s (id: %s, type: %s)', $block->getName(), $block->getId(), $block->getType()));

        return [
            'name'         => $block->getName(),
            'type'         => $block->getType(),
            'duration'     => false,
            'memory_start' => memory_get_usage(true),
            'memory_end'   => false,
            'memory_peak'  => false,
            'cache'        => [
                'keys'            => [],
                'contextual_keys' => [],
                'handler'         => false,
                'from_cache'      => false,
                'ttl'             => 0,
                'created_at'      => false,
                'lifetime'        => 0,
                'age'             => 0,
            ],
            'assets'       => [
                'js'  => [],
                'css' => [],
            ],
        ];
    }

    /**
     * @param BlockInterface $block
     * @param array          $stats
     */
    protected function stopTracing(BlockInterface $block, array $stats)
    {
        $e = $this->traces[$block->getId()]->stop();

        $this->traces[$block->getId()] = array_merge($stats, [
            'duration'    => $e->getDuration(),
            'memory_end'  => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);

        $this->traces[$block->getId()]['cache']['lifetime'] = $this->traces[$block->getId()]['cache']['age'] + $this->traces[$block->getId()]['cache']['ttl'];
    }

    /**
     * @param BlockEvent $event
     *
     * @return array
     */
    protected function getEventBlocks(BlockEvent $event)
    {
        $results = [];

        foreach ($event->getBlocks() as $block) {
            $results[] = [$block->getId(), $block->getType()];
        }

        return $results;
    }

    /**
     * @param string $eventName
     *
     * @return array
     */
    protected function getEventListeners($eventName)
    {
        $results = [];

        foreach ($this->eventDispatcher->getListeners($eventName) as $listener) {
            if (is_object($listener[0])) {
                $results[] = get_class($listener[0]);
            } elseif (is_string($listener[0])) {
                $results[] = $listener[0];
            } elseif ($listener instanceof \Closure) {
                $results[] = '{closure}()';
            } else {
                $results[] = 'Unknown type!';
            }
        }

        return $results;
    }

    /**
     * @param BlockInterface $block
     * @param array          $stats
     *
     * @return CacheAdapterInterface
     */
    protected function getCacheService(BlockInterface $block, array &$stats = null)
    {
        if (!$this->hasCacheManager()) {
            return false;
        }

        // type by block class
        $class = ClassUtils::getClass($block);
        $cacheServiceId = isset($this->cacheBlocks['by_class'][$class]) ? $this->cacheBlocks['by_class'][$class] : false;

        // type by block service
        if (!$cacheServiceId) {
            $cacheServiceId = isset($this->cacheBlocks['by_type'][$block->getType()]) ? $this->cacheBlocks['by_type'][$block->getType()] : false;
        }

        if (!$cacheServiceId) {
            return false;
        }

        if ($this->stopwatch) {
            $stats['cache']['handler'] = $cacheServiceId;
        }

        return $this->cacheManager->getCacheService($cacheServiceId);
    }

    /**
     * @return bool
     */
    protected function hasCacheManager()
    {
        return $this->cacheManager && $this->getCms()->doCache();
    }

    /**
     * @return \Isa\Backend\UtilBundle\Twig\AsseticExtension
     */
    protected function getBaseAssetExtension()
    {
        return $this->container->get('isa.twig.extension.assetic');
    }

    /**
     * @return \Symfio\PageBundle\Cms\Cms
     */
    protected function getCms()
    {
        return $this->container->get('symfio.page.cms');
    }
}


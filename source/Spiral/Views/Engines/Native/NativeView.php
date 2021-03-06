<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Views\Engines\Native;

use Spiral\Core\Component;
use Spiral\Core\ContainerInterface;
use Spiral\Core\Traits\SharedTrait;
use Spiral\Debug\Traits\BenchmarkTrait;
use Spiral\Views\Exceptions\RenderException;
use Spiral\Views\ViewInterface;
use Spiral\Views\ViewSource;

/**
 * Simpliest implement of view model used by native and Stempler engines. Provides ability to
 * perform calls like $this->app in a view file.
 *
 * Attention, this view model depends on container in order to provider proper scope/isolation
 * when view is being rendered.
 */
class NativeView extends Component implements ViewInterface
{
    use BenchmarkTrait, SharedTrait;

    /**
     * @var \Spiral\Views\ViewSource
     */
    protected $sourceContext;

    /**
     * @invisible
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * @param ViewSource         $sourceContext
     * @param ContainerInterface $container For inner view scope.
     */
    public function __construct(
        ViewSource $sourceContext,
        ContainerInterface $container
    ) {
        $this->sourceContext = $sourceContext;
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function render(array $context = []): string
    {
        $__benchmark__ = $this->benchmark(
            'render',
            "{$this->sourceContext->getNamespace()}:{$this->sourceContext->getName()}"
        );

        ob_start();
        $__outputLevel__ = ob_get_level();

        $scope = self::staticContainer($this->container);
        try {
            extract($context, EXTR_OVERWRITE);
            require $this->sourceContext->getFilename();
        } catch (\Throwable $e) {
            //Clear all rendered output (should we save it into exception?)
            ob_end_clean();

            //Wrapping exception
            throw new RenderException($e);
        } finally {
            //Closing all nested buffers
            while (ob_get_level() > $__outputLevel__) {
                ob_end_clean();
            }

            $this->benchmark($__benchmark__);
            self::staticContainer($scope);
        }

        return ob_get_clean();
    }
}
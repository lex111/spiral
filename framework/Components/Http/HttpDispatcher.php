<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http;

use Psr\Http\Message\ResponseInterface;
use Spiral\Components\Debug\Snapshot;
use Spiral\Core\Component;
use Spiral\Core\Container;
use Spiral\Core\Core;
use Spiral\Core\Dispatcher\ClientException;
use Spiral\Core\DispatcherInterface;

class HttpDispatcher extends Component implements DispatcherInterface
{
    /**
     * Required traits.
     */
    use Component\SingletonTrait,
        Component\LoggerTrait,
        Component\EventsTrait,
        Component\ConfigurableTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = 'http';

    /**
     * Core instance.
     *
     * @invisible
     * @var Core
     */
    protected $core = null;

    /**
     * Original server request generated by spiral while starting HttpDispatcher.
     *
     * @var Request
     */
    protected $request = null;

    /**
     * Set of middleware layers built to handle incoming Request and return Response. Middleware
     * can be represented as class, string (DI) or array (callable method). HttpDispatcher layer
     * middlewares will be called in start() method. This set of middleware(s) used to filter
     * http request and response on higher layer.
     *
     * @var array|MiddlewareInterface[]
     */
    protected $filters = array();

    /**
     * Endpoints is a set of middlewares or callback used to handle some application parts separately
     * from application controllers and routes. Such Middlewares can perform their own routing,
     * mapping, render and etc and only have to return ResponseInterface object.
     *
     * You can use add() method to create new endpoint. Every endpoint should be specified as path
     * with / and in lower case.
     *
     * Example (in bootstrap):
     * $this->http->add('/forum', 'Vendor\Forum\Forum');
     *
     * @var array|MiddlewareInterface[]
     */
    protected $endpoints = array();

    /**
     * New HttpDispatcher instance.
     *
     * @param Core $core
     */
    public function __construct(Core $core)
    {
        $this->core = $core;
        $this->config = $core->loadConfig('http');
        $this->filters = $this->config['filters'];
    }

    /**
     * Register new endpoint middleware inside HttpDispatcher.
     *
     * @param string                              $path Http Uri path with / and in lower case.
     * @param string|callable|MiddlewareInterface $middleware
     * @return static
     */
    public function add($path, $middleware)
    {
        $this->endpoints[$path] = $middleware;

        return $this;
    }

    /**
     * Letting dispatcher to control application flow and functionality.
     *
     * @param Core $core
     */
    public function start(Core $core)
    {
        if (empty($this->endpoints[$this->config['basePath']]))
        {
            //Base path wasn't handled, let's attach our router
            $this->endpoints[$this->config['basePath']] = function ()
            {
                return $this->core->callAction('Controllers\HomeController', 'index');
            };
        }

        $this->request = Request::castRequest(array(
            'basePath' => $this->config['basePath']
        ));

        $pipeline = new MiddlewarePipe($this->filters);
        $response = $pipeline->target(array($this, 'perform'))->run($this->request, $this);

        //Use $event->object->getRequest() to access original request
        $this->dispatch($this->event('dispatch', $response));
    }

    /**
     * Get initial request generated by HttpDispatcher. This is untouched request object, all
     * cookies will be encrypted and other values will not be pre-processed.
     *
     * @return Request|null
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Execute given request and return response. Request Uri will be passed thought Http routes
     * to find appropriate endpoint. By default this method will be called at the end of middleware
     * pipeline inside HttpDispatcher->start() method, however method can be called manually with
     * custom or altered request instance.
     *
     * Every request passed to perform method will be registered in Container scope under "request"
     * and class name binding.
     *
     * @param Request $request
     * @return array|Response
     * @throws ClientException
     * @throws \Spiral\Core\CoreException
     */
    public function perform(Request $request)
    {
        if (!$endpoint = $this->findEndpoint(strtolower($request->getUri()->getPath())))
        {
            //This should never happen as request should be handled at least by Router middleware
            throw new ClientException(ClientException::SERVER_ERROR);
        }

        $parentRequest = $this->core->getBinding('request');

        //Creating scope
        $this->core->bind('request', $request);
        $this->core->bind(get_class($request), $request);

        //Yey! Let's go!
        $response = $this->execute($request, $endpoint);

        $this->core->removeBinding(get_class($request));
        $this->core->removeBinding('request');

        if (!empty($parentRequest))
        {
            //Restoring scope
            $this->core->bind('request', $parentRequest);
            $this->core->bind(get_class($parentRequest), $parentRequest);
        }

        return $response;
    }

    /**
     * Locate appropriate middleware endpoint based on Uri part.
     *
     * @param string $uriPath Lowercased Uri Path.
     * @return null|MiddlewareInterface
     */
    protected function findEndpoint($uriPath)
    {
        if (isset($this->endpoints[$uriPath]))
        {
            //Quick jump
            return $this->endpoints[$uriPath];
        }
        else
        {
            foreach ($this->endpoints as $path => $middleware)
            {
                if (strpos($uriPath, $path) === 0)
                {
                    return $middleware;
                }
            }
        }

        return null;
    }

    /**
     * Execute endpoint middleware. Right now this method supports only spiral middlewares,
     * but can be easily changed to support another syntax like handle(request, response).
     *
     * @param Request                             $request
     * @param string|callable|MiddlewareInterface $endpoint
     * @return mixed
     * @throws \Spiral\Core\CoreException
     */
    protected function execute(Request $request, $endpoint)
    {
        /**
         * @var callable $endpoint
         */
        $endpoint = is_string($endpoint) ? Container::get($endpoint) : $endpoint;

        ob_start();
        $response = $endpoint($request, null, $this);
        $plainOutput = ob_get_clean();

        return $this->wrapResponse($response, $plainOutput);
    }

    protected function wrapResponse($response, $plainOutput = '')
    {
        if ($response instanceof ResponseInterface)
        {
            if (!empty($plainOutput))
            {
                $response->getBody()->write($plainOutput);
            }

            return $response;
        }

        //        if (is_array($response) || $response instanceof \JsonSerializable)
        //        {
        //            if (is_array($response) && $plainOutput)
        //            {
        //                $response['plainOutput'] = $plainOutput;
        //            }
        //
        //            return new Response(json_encode($response), 200, array(
        //                'Content-Type' => 'application/json'
        //            ));
        //        }

        return new Response($response . $plainOutput);
    }

    /**
     * Dispatch provided request to client. Application will stop after this method call.
     *
     * @param ResponseInterface $response
     */
    public function dispatch(ResponseInterface $response)
    {
        while (ob_get_level())
        {
            ob_get_clean();
        }

        $statusHeader = "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()}";
        header(rtrim("{$statusHeader} {$response->getReasonPhrase()}"));

        //Receive all headers but not cookies
        foreach ($response->getHeaders() as $header => $values)
        {
            $replace = true;
            foreach ($values as $value)
            {
                header("{$header}: {$value}", $replace);
                $replace = false;
            }
        }

        //Spiral request stores cookies separately with headers to make them easier to send
        if ($response instanceof Response)
        {
            foreach ($response->getCookies() as $cookie)
            {
                //TODO: Default cookie domain!
                setcookie(
                    $cookie->getName(),
                    $cookie->getValue(),
                    $cookie->getExpire(),
                    $cookie->getPath(),
                    $cookie->getDomain(),
                    $cookie->getSecure(),
                    $cookie->getHttpOnly()
                );
            }
        }

        if ($response->getStatusCode() == 204)
        {
            return;
        }

        $stream = $response->getBody();

        // I need self sending requests in future.
        if (!$stream->isSeekable())
        {
            echo (string)$stream;
        }
        else
        {
            //Use stream_copy_to_stream() somehow
            ob_implicit_flush(true);
            $stream->rewind();
            while (!$stream->eof())
            {
                echo $stream->read(1024);
            }
        }
    }

    /**
     * Every dispatcher should know how to handle exception snapshot provided by Debugger.
     *
     * @param Snapshot $snapshot
     * @return mixed
     */
    public function handleException(Snapshot $snapshot)
    {
        if ($snapshot->getException() instanceof ClientException)
        {
            //Simply showing something
            //$this->dispatch(new Response('ERROR VIEW LAYOUT IF PRESENTED', $snapshot->getException()->getCode()));
        }

        //TODO: hide snapshot based on config
        $this->dispatch(new Response($snapshot->renderSnapshot(), 500));
    }
}
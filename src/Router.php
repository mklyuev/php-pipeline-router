<?php

namespace App;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Prophecy\Exception\Doubler\ClassNotFoundException;
use Prophecy\Exception\Doubler\MethodNotFoundException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Router
 * @package App\Core
 */
class Router
{
    /**
     * @var array
     */
    protected $handlers = [];

    /**
     * @var ContainerInterface
     */
    private $container = null;

    /**
     * @var Response|null
     */
    private $response = null;
    /**
     * @var string
     */
    private $groupAlias = "";

    /**
     * @var array
     */
    private $groupPipelines = [];

    public function __construct()
    {
        $this->container = new Container();
        $this->response = new Response();
    }

    /**
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param $attributes
     * @param \Closure $routes
     */
    public function group($attributes, \Closure  $routes)
    {
        $this->addGroupAttributes($attributes);
        $routes($this);
        $this->removeGroupAttributes($attributes);
    }

    /**
     * @param $attributes
     */
    private function addGroupAttributes($attributes)
    {
        if (! empty($attributes['alias'])) {
            $this->groupAlias = $this->groupAlias . '/' . $attributes['alias'];
        }
        if (! empty($attributes['pipelines'])) {
            foreach ($attributes['pipelines'] as $pipeline) {
                array_push($this->groupPipelines, $pipeline);
            }
        }

    }

    private function removeGroupAttributes ($attributes)
    {
        if (! empty($attributes['alias'])) {
            $groupAliases = array_values(array_filter((preg_split('/\//', $this->groupAlias))));
            $this->groupAlias = str_replace($groupAliases[count($groupAliases) - 1], "", $this->groupAlias);
        }
        if (! empty($attributes['pipelines'])) {
            array_pop($this->groupPipelines);
        }
    }

    /**
     * @param $uriPattern
     * @param $handler
     * @param array $pipelines
     */
    public function get ($uriPattern, $handler, $pipelines = []) {
        $this->add(["GET"], $uriPattern, $handler, $pipelines);
    }

    /**
     * @param $uriPattern
     * @param \Closure|string $handler
     * @param array $options
     */
    public function post ($uriPattern, $handler, $options = []) {
        $this->add(["POST"], $uriPattern, $handler, $options);
    }

    /**
     * @param $uriPattern
     * @param \Closure|string $handler
     * @param array $options
     */
    public function head ($uriPattern, $handler, $options = []) {
        $this->add(["HEAD"], $uriPattern, $handler, $options);
    }

    /**
     * @param $uriPattern
     * @param \Closure|string $handler
     * @param array $options
     */
    public function put ($uriPattern, $handler, $options = []) {
        $this->add(["PUT"], $uriPattern, $handler, $options);
    }

    /**
     * @param $uriPattern
     * @param \Closure|string $handler
     * @param array $options
     */
    public function patch ($uriPattern, $handler, $options = []) {
        $this->add(["PATCH"], $uriPattern, $handler, $options);
    }

    /**
     * @param $uriPattern
     * @param \Closure|string $handler
     * @param array $options
     */
    public function delete ($uriPattern, $handler, $options = []) {
        $this->add(["DELETE"], $uriPattern, $handler, $options);
    }

    /**
     * @param $uriPattern
     * @param \Closure|string $handler
     * @param array $options
     */
    public function options ($uriPattern, $handler, $options = []) {
        $this->add(["OPTIONS"], $uriPattern, $handler, $options);
    }


    /**
     * @param $httpMethods
     * @param $uriPattern
     * @param $handler
     * @param $pipelines
     */
    public function add($httpMethods, $uriPattern, $handler, $pipelines)
    {
        preg_match_all("/[{]([a-z]+)[}]/i", $uriPattern, $matches );
        $uriParameters = (isset($matches[1])) ? $matches[1] : [];

        $uriPattern = preg_replace("/[{][a-z]+[}]/i", ":", $uriPattern);

        if (! empty($this->groupAlias)) {
            $uriPattern = $this->groupAlias . '/' . $uriPattern;
        }

        if (! empty($this->groupPipelines)) {
            $pipelines = array_merge($this->groupPipelines, $pipelines);
        }

        array_push($this->handlers, [
            'uriPattern' => $uriPattern,
            'uriParameters' => $uriParameters,
            'httpMethods' => $httpMethods,
            'pipelines' => $pipelines,
            'handler' => $handler
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     * @throws DependencyException
     * @throws \HttpException
     */
    public function handle(Request $request): Response
    {
        $handler = $this->findHandler($request);

        if (! $handler) {
            throw new \HttpException("Handler Not Found");
        }

        if (! empty($handler['pipelines'])) {
            foreach ($handler['pipelines'] as $pipeline) {
                if ($pipeline instanceof \Closure) {
                    $pipeline($request, $this->response);
                } else {
                    try {
                        $pipelineClass = $this->container->get($pipeline);
                    } catch (NotFoundException $e) {
                        throw new ClassNotFoundException("Class Not Found",  $pipeline);
                    }

                    if (method_exists($pipelineClass, 'process')) {
                        call_user_func_array([$pipelineClass, 'process'], [
                            'request' => $request,
                            'response' => $this->response
                        ]);
                    } else {
                        throw new MethodNotFoundException("Method Not Found",  $pipeline, 'process');
                    }
                }
            }
        }

        if ($handler['handler'] instanceof \Closure) {
            return $handler['handler']($request);
        } else {
            $segments = explode('@', $handler['handler']);

            $controllerName =  $segments[0];
            $methodName = $segments[1];

            try {
                $controller = $this->container->get($controllerName);
            } catch (NotFoundException $e) {
                throw new ClassNotFoundException("Class Not Found",  $controllerName);
            }

            if (method_exists($controller, $methodName)) {
                $this->response = call_user_func_array([$controller, $methodName], [
                    'request' => $request,
                    'response' => $this->response
                ]);
            } else {
                throw new MethodNotFoundException("Method Not Found",  $controllerName, $methodName);
            }
        }

        return $this->response;
    }

    /**
     * @param Request $request
     * @return bool
     */
    protected function findHandler(Request $request)
    {
        $handlers = array_filter($this->handlers, function ($item) use ($request) {
            return in_array( $request->getMethod(), $item['httpMethods']) ? true : false;
        });

        $uriParts = array_values(array_filter(explode('/', $request->getPathInfo())));

        foreach ($handlers as $handler) {
            $handlerParts = array_values(array_filter(explode('/', $handler['uriPattern'])));

            if (count($handlerParts) !== count($uriParts)) {
                continue;
            }

            $parameterIndex = 0;
            foreach ($uriParts as $index => $uriPart) {
                if ($uriPart !== $handlerParts[$index] && $handlerParts[$index] !== ':') {
                    continue 2;
                } elseif ($handlerParts[$index] === ':') {
                    $request->attributes->set($handler['uriParameters'][$parameterIndex], $uriPart);
                    $parameterIndex++;
                }
            }

            return $handler;
        }

        return false;
    }
}

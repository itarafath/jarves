<?php

namespace Jarves\ContentTypes;

use Jarves\Model\Content;

use Jarves\Exceptions\PluginException;
use Jarves\Model\ContentInterface;
use Jarves\PageResponse;
use Jarves\PluginResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Jarves\Jarves;
use Jarves\Configuration\Plugin;

class TypePlugin extends AbstractType
{

    /**
     * @var array
     */
    private $plugin;

    /**
     * @var string
     */
    private $bundleName;

    /**
     * @var Plugin
     */
    private $pluginDef;

    /**
     * @var Jarves
     */
    protected $jarves;

    function __construct($jarves)
    {
        $this->jarves = $jarves;
    }

    /**
     * @param Jarves $jarves
     */
    public function setJarves($jarves)
    {
        $this->jarves = $jarves;
    }

    /**
     * @return Jarves
     */
    public function getJarves()
    {
        return $this->jarves;
    }

    public function exceptionHandler(GetResponseForExceptionEvent $event)
    {
        throw new PluginException(
            sprintf(
                'The plugin `%s` from bundle `%s` [%s] returned a wrong result.',
                $this->plugin['plugin'],
                $this->bundleName,
                $this->pluginDef->getClass() . '::' . $this->pluginDef->getMethod()
            ), null, $event->getException()
        );
    }

    public function setContent(ContentInterface $content)
    {
        parent::setContent($content);
        $this->plugin = json_decode($content->getContent(), 1);
        $this->bundleName = $this->plugin['bundle'] ?: $this->plugin['module']; //module for BC
    }

    public function fixResponse(GetResponseForControllerResultEvent $event)
    {
        $data = $event->getControllerResult();

        if ($data instanceof PluginResponse) {
            $response = $data;
        } else {
            $response = new PluginResponse($data);
        }
        $response->setControllerRequest($event->getRequest());
        $event->setResponse($response);
    }

    /**
     * @return bool
     */
    public function isPreview()
    {
        return isset($this->getParameters()['preview']) ? $this->getParameters()['preview'] : false;
    }

    public function render()
    {
        if ($response = $this->getJarves()->getPageResponse()->getPluginResponse($this->getContent())) {
            return $response->getContent();
        } elseif ($this->plugin) {
            $config = $this->getJarves()->getConfig($this->bundleName);

            if (!$config) {
                return sprintf(
                    'Bundle `%s` does not exist. You probably have to install this bundle.',
                    $this->bundleName
                );
            }

            if ($this->pluginDef = $config->getPlugin($this->plugin['plugin'])) {
                $clazz = $this->pluginDef->getClass();
                $method = $this->pluginDef->getMethod();

                if (class_exists($clazz)) {
                    if (method_exists($clazz, $method)) {
                        if ($this->isPreview()) {
                            if (!$this->pluginDef->isPreview()) {
                                //plugin does not allow to have a preview on the actual action method,
                                //so try <method>Preview
                                if (method_exists($clazz, $method . 'Preview')) {
                                    $method = $method . 'Preview';
                                } else {
                                    return $config->getLabel() . ': ' . $this->pluginDef->getLabel();
                                }
                            }
                        }

                        //create a sub request
                        $request = new Request();
                        $request->attributes->add(
                            array(
                                '_controller' => $clazz . '::' . $method,
                                '_content' => $this->getContent(),
                                'options' => isset($this->plugin['options']) ? $this->plugin['options'] : array()
                            )
                        );

                        $dispatcher = $this->getJarves()->getEventDispatcher();

                        $callable = array($this, 'exceptionHandler');
                        $fixResponse = array($this, 'fixResponse');

                        $dispatcher->addListener(
                            KernelEvents::EXCEPTION,
                            $callable,
                            100
                        );

                        $dispatcher->addListener(
                            KernelEvents::VIEW,
                            $fixResponse,
                            100
                        );

                        ob_start();
                        $response = $this->getJarves()->getKernel()->handle($request, HttpKernelInterface::SUB_REQUEST);
                        //EventListener\PluginRequestListener converts all PluginResponse objects to PageResponses
                        if ($response instanceof PageResponse) {
                            $response = $response->getPluginResponse($this->getContent()->getId());
                        }
                        $ob = ob_get_clean();

                        $dispatcher->removeListener(
                            KernelEvents::EXCEPTION,
                            $callable
                        );
                        $dispatcher->removeListener(
                            KernelEvents::VIEW,
                            $fixResponse
                        );

                        return $ob . $response->getContent();
                    } else {
                        return '';
                    }
                } else {
                    return sprintf('Class `%s` does not exist. You should create this class.', $clazz);
                }
            } else {
                return sprintf(
                    'Plugin `%s` in bundle `%s` does not exist. You probably have to install the bundle first.',
                    $this->plugin['plugin'],
                    $this->bundleName
                );
            }
        }
    }

}
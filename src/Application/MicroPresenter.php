<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace NetteModule;

use Latte;
use Nette;
use Nette\Application;
use Nette\Application\Responses;
use Nette\Http;


/**
 * Micro presenter.
 */
class MicroPresenter implements Application\IPresenter
{
	use Nette\SmartObject;

	/** @var Nette\DI\Container|null */
	private $context;

	/** @var Nette\Http\IRequest|null */
	private $httpRequest;

	/** @var Application\IRouter|null */
	private $router;

	/** @var Application\Request|null */
	private $request;


	public function __construct(Nette\DI\Container $context = null, Http\IRequest $httpRequest = null, Application\IRouter $router = null)
	{
		$this->context = $context;
		$this->httpRequest = $httpRequest;
		$this->router = $router;
	}


	/**
	 * Gets the context.
	 * @return Nette\DI\Container|null
	 */
	public function getContext()
	{
		return $this->context;
	}


	/**
	 * @return Nette\Application\IResponse
	 */
	public function run(Application\Request $request)
	{
		$this->request = $request;

		if ($this->httpRequest && $this->router && !$this->httpRequest->isAjax() && ($request->isMethod('get') || $request->isMethod('head'))) {
			$refUrl = clone $this->httpRequest->getUrl();
			$url = $this->router->constructUrl($request, $refUrl->setPath($refUrl->getScriptPath()));
			if ($url !== null && !$this->httpRequest->getUrl()->isEqual($url)) {
				return new Responses\RedirectResponse($url, Http\IResponse::S301_MOVED_PERMANENTLY);
			}
		}

		$params = $request->getParameters();
		$callback = isset($params['callback']) ? $params['callback'] : null;
		if (!is_object($callback) || !is_callable($callback)) {
			throw new Application\BadRequestException('Parameter callback is not a valid closure.');
		}
		$reflection = Nette\Utils\Callback::toReflection($callback);

		if ($this->context) {
			foreach ($reflection->getParameters() as $param) {
				if ($param->getClass()) {
					$params[$param->getName()] = $this->context->getByType($param->getClass()->getName(), false);
				}
			}
		}
		$params['presenter'] = $this;
		$params = Application\UI\ComponentReflection::combineArgs($reflection, $params);

		$response = call_user_func_array($callback, $params);

		if (is_string($response)) {
			$response = [$response, []];
		}
		if (is_array($response)) {
			list($templateSource, $templateParams) = $response;
			$response = $this->createTemplate()->setParameters($templateParams);
			if (!$templateSource instanceof \SplFileInfo) {
				$response->getLatte()->setLoader(new Latte\Loaders\StringLoader);
			}
			$response->setFile($templateSource);
		}
		if ($response instanceof Application\UI\ITemplate) {
			return new Responses\TextResponse($response);
		} else {
			return $response;
		}
	}


	/**
	 * Template factory.
	 * @param  string
	 * @return Application\UI\ITemplate
	 */
	public function createTemplate($class = null, callable $latteFactory = null)
	{
		$latte = $latteFactory ? $latteFactory() : $this->getContext()->getByType(Nette\Bridges\ApplicationLatte\ILatteFactory::class)->create();
		$template = $class ? new $class : new Nette\Bridges\ApplicationLatte\Template($latte);

		$template->setParameters($this->request->getParameters());
		$template->presenter = $this;
		$template->context = $this->context;
		if ($this->httpRequest) {
			$url = $this->httpRequest->getUrl();
			$template->baseUrl = rtrim($url->getBaseUrl(), '/');
			$template->basePath = rtrim($url->getBasePath(), '/');
		}
		return $template;
	}


	/**
	 * Redirects to another URL.
	 * @param  string
	 * @param  int HTTP code
	 * @return Nette\Application\Responses\RedirectResponse
	 */
	public function redirectUrl($url, $httpCode = Http\IResponse::S302_FOUND)
	{
		return new Responses\RedirectResponse($url, $httpCode);
	}


	/**
	 * Throws HTTP error.
	 * @param  string
	 * @param  int HTTP error code
	 * @return void
	 * @throws Nette\Application\BadRequestException
	 */
	public function error($message = null, $httpCode = Http\IResponse::S404_NOT_FOUND)
	{
		throw new Application\BadRequestException($message, $httpCode);
	}


	/**
	 * @return Nette\Application\Request|null
	 */
	public function getRequest()
	{
		return $this->request;
	}
}

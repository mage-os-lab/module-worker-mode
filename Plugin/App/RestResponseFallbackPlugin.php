<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\App;

use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Opengento\Application\App\Http;

/**
/**
 * Fixes opengento/module-application empty REST responses when a service call throws.
 *
 * App\Http::handleHttpResult copies the REST response body to the main HTTP response
 * via $result->getContent(). If the REST controller caught an exception via setException()
 * without calling sendResponse(), the body is never rendered, so getContent() returns ""
 * — resulting in a 200 OK with an empty body.
 *
 * When this condition is detected (empty body + pending exception on the REST response),
 * the plugin returns the RestResponse directly. AppBootstrap::run() then calls
 * sendResponse() on it, which triggers _renderMessages() and produces a proper JSON
 * error response with the correct HTTP status code.
 */
class RestResponseFallbackPlugin
{
    /**
     * @param RestResponse $restResponse
     */
    public function __construct(
        private readonly RestResponse $restResponse
    ) {}

    /**
     * @param Http $subject
     * @param HttpInterface $result
     * @return HttpInterface
     */
    public function afterLaunch(Http $subject, HttpInterface $result): HttpInterface
    {
        if (empty($result->getContent()) && $this->restResponse->isException()) {
            return $this->restResponse;
        }

        return $result;
    }
}

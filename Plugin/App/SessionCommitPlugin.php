<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\App;

use Magento\Framework\App\Response\HttpInterface;
use Opengento\Application\App\Http;
use Opengento\Application\App\Session\SessionRegistry;

/**
/**
 * Commits all active sessions to Redis before the response is sent.
 *
 * Registered in webapi_rest (for checkout order placement) and adminhtml
 * (for admin login). In both cases the race condition is the same:
 * session_write_close() normally runs in the resetState() finally block after
 * sendResponse(). A second worker can start handling the next request before
 * the first write completes, reading Redis with stale/empty session data.
 *
 * Calling closeSessions() here writes to Redis immediately (before sendResponse()),
 * making the session data visible to all workers. The session is left closed; the
 * resetState() finally block's writeClose() is then a no-op.
 */
class SessionCommitPlugin
{
    /**
     * @param SessionRegistry $sessionRegistry
     */
    public function __construct(
        private readonly SessionRegistry $sessionRegistry,
    ) {}

    /**
     * @param Http $subject
     * @param HttpInterface $result
     * @return HttpInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterLaunch(Http $subject, HttpInterface $result): HttpInterface
    {
        $this->sessionRegistry->closeSessions();
        return $result;
    }
}

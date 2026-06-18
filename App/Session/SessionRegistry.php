<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\App\Session;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Opengento\Application\App\Session\SessionRegistry as BaseSessionRegistry;
use WeakMap;

/**
/**
 * Resets the session registry between requests so each request only manages its own sessions.
 *
 * opengento's SessionRegistry accumulates every session started by any previous request on this
 * worker (via RegisterStartedSession::beforeStart). The WeakMap is never cleared because
 * SessionRegistry does not implement ResetAfterRequestInterface. This causes startSessions() at
 * the top of every request to call start() on ALL sessions ever started by the worker — including
 * sessions from a different area.
 *
 * Concretely: a frontend request registers CustomerSession; the next admin request calls
 * startSessions() which calls CustomerSession::start() with the browser's frontend session cookie.
 * The PHP session is now open with frontend config (session.cookie_path='/') before the admin
 * Auth\Session starts. When admin login calls regenerateId(), the new session cookie is sent with
 * Path='/' instead of '/admin/', overwriting the browser's frontend PHPSESSID. The new session ID
 * has the admin data but the customer data migration relies on the snapshot taken at that moment.
 *
 * By clearing the WeakMap in _resetState(), each request starts with an empty registry. Sessions
 * are lazily started when first accessed (the normal Magento behaviour). The closeSessions() call
 * in ReloadProcessor still runs correctly — it only closes sessions registered during THIS request.
 *
 * The parent's private $sessions is a separate property from the child's; all methods are
 * overridden so the parent's WeakMap is never accessed.
 */
class SessionRegistry extends BaseSessionRegistry implements ResetAfterRequestInterface
{
    /** @var WeakMap<SessionManagerInterface, bool> */
    private WeakMap $sessions;

    public function __construct()
    {
        $this->sessions = new WeakMap();
    }

    public function add(SessionManagerInterface $sessionManager): void
    {
        $this->sessions[$sessionManager] = true;
    }

    public function startSessions(): void
    {
        foreach ($this->sessions as $session => $state) {
            $session?->start();
        }
    }

    public function closeSessions(): void
    {
        foreach ($this->sessions as $session => $state) {
            $session?->writeClose();
        }
    }

    public function _resetState(): void
    {
        $this->sessions = new WeakMap();
    }
}

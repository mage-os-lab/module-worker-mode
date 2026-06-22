<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Model\Security;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Security\Model\AdminSessionsManager as BaseAdminSessionsManager;

/**
 * Resets the cached admin session record between requests in FrankenPHP worker mode.
 *
 * Magento\Security\Model\AdminSessionsManager is a singleton. getCurrentSession()
 * lazily loads the admin_user_session DB row into $currentSession and caches it for
 * the lifetime of the instance — the property is only ever populated when null and
 * is never cleared. The base class does not implement ResetAfterRequestInterface and
 * has no reset.json entry, so under worker mode the cached row survives across
 * requests on the same worker.
 *
 * The leak breaks admin re-login after a logout. Auth\Session::prolong() runs on every
 * admin request and is wrapped by Magento\Security\Model\Plugin\AuthSession::aroundProlong(),
 * which calls $session->destroy() whenever getCurrentSession()->isLoggedInStatus() is false.
 * When a worker previously served the logout request, processLogout() set that cached row's
 * status to LOGGED_OUT and the singleton kept it. The next login handled by the same warm
 * worker then reads the stale LOGGED_OUT row and destroys the freshly authenticated session,
 * bouncing the user back to the login screen. Cold workers (currentSession still null) load a
 * fresh row and log in fine, which is why the failure is intermittent per worker.
 *
 * _resetState() clears $currentSession so each request reloads the row that matches the
 * admin session actually in play. The opengento Resetter calls _resetState() as a direct
 * method call, which initialises the lazy-ghost interceptor before writing — the reset lands
 * in the live property slot (where a reset.json ReflectionProperty::setValue() would silently
 * fail on PHP 8.4 lazy ghosts).
 *
 * $currentSession is the only request-scoped state on the parent; every other property is an
 * injected service or static config, so nulling this one field is the complete reset.
 */
class AdminSessionsManager extends BaseAdminSessionsManager implements ResetAfterRequestInterface
{
    public function _resetState(): void
    {
        $this->currentSession = null;
    }
}

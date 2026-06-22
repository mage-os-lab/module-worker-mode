<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Plugin\Message;

use Magento\Framework\Exception\SessionException;
use Magento\Framework\Message\Manager;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Message\Session as MessageSession;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Binds the message session storage to $_SESSION before flash messages are read or
 * written, so admin "You saved the …" messages survive the POST -> 302 -> GET cycle
 * in FrankenPHP worker mode.
 *
 * Message\Session is a SessionManager singleton (namespace 'message'). Its Storage._data
 * is bound to $_SESSION['message'] only by Storage::init(), which runs inside
 * Message\Session::start(). In worker mode the singleton is constructed once per worker,
 * so start() is not called on subsequent requests: Storage::_resetState() clears _data and
 * the next Auth\Session session_start() replaces the $_SESSION['message'] slot, breaking the
 * reference (the same Scenario 1 reference break handled for CustomerSession). The Manager
 * then adds messages to orphaned _data that is never committed to Redis, and reads the same
 * orphaned _data on the redirected page — so the message is silently lost.
 *
 * Calling start() before the first getMessages()/addMessage() does two things: init($_SESSION)
 * re-binds _data to $_SESSION['message'], and RegisterStartedSession adds Message\Session to the
 * SessionRegistry so SessionCommitPlugin::closeSessions() writes it before the 302 is sent.
 *
 * addMessage() internally calls getMessages(), so hooking getMessages() alone would suffice;
 * addMessage() is hooked too for clarity and to cover the write path explicitly. The $started
 * flag keeps it to one start() per request; _resetState() clears it between requests.
 */
class MessageManagerSessionPlugin implements ResetAfterRequestInterface
{
    private bool $started = false;

    /**
     * @param MessageSession $messageSession
     */
    public function __construct(
        private readonly MessageSession $messageSession,
    ) {}

    /**
     * @param Manager $subject
     * @param bool $clear
     * @param string|null $group
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeGetMessages(Manager $subject, $clear = false, $group = null): void
    {
        $this->ensureStarted();
    }

    /**
     * @param Manager $subject
     * @param MessageInterface $message
     * @param string|null $group
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddMessage(Manager $subject, MessageInterface $message, $group = null): void
    {
        $this->ensureStarted();
    }

    /**
     * Re-bind the message session storage to $_SESSION once per request.
     */
    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        try {
            $this->messageSession->start();
        } catch (SessionException) {
            // If the session cannot start, fall through; messages degrade to the
            // current (pre-fix) behaviour rather than breaking the request.
        }
    }

    public function _resetState(): void
    {
        $this->started = false;
    }
}

<?php
declare(strict_types=1);

namespace MageOS\WorkerMode\Session;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Session\Config;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\ValidatorFactory;

/**
/**
 * Session config for the frontend area that stores session.name explicitly in $options.
 *
 * The generic Magento\Framework\Session\Config never calls setName(), so session.name
 * is never stored in $this->options. initIniOptions() iterates $options and sets ini
 * values for each one — but since session.name is absent, it never calls
 * ini_set('session.name', ...). In FrankenPHP worker mode, after a worker handles an
 * admin request, AdminConfig.initIniOptions() sets ini 'session.name' = 'admin'
 * globally for that PHP process. On the next frontend request the session name is still
 * 'admin', so session_start() reads $_COOKIE['admin'] instead of $_COOKIE['PHPSESSID'].
 * For users who have an admin cookie, this loads the admin session as the customer
 * session, corrupting both. For users without an admin cookie, CustomerSession never
 * starts and the customer appears logged out.
 *
 * By storing session.name = 'PHPSESSID' (the PHP default) in $options, initIniOptions()
 * always calls ini_set('session.name', 'PHPSESSID'), resetting any admin contamination
 * before session_start() is called.
 */
#[\Magento\Framework\ObjectManager\Attribute\NonLazy]
class FrontendConfig extends Config
{
    /**
     * @param ValidatorFactory $validatorFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param StringUtils $stringHelper
     * @param RequestInterface $request
     * @param Filesystem $filesystem
     * @param DeploymentConfig $deploymentConfig
     * @param string $scopeType
     * @param string $lifetimePath
     * @param string $sessionName The PHP session cookie name; defaults to 'PHPSESSID'.
     */
    public function __construct(
        ValidatorFactory $validatorFactory,
        ScopeConfigInterface $scopeConfig,
        StringUtils $stringHelper,
        RequestInterface $request,
        Filesystem $filesystem,
        DeploymentConfig $deploymentConfig,
        string $scopeType = '',
        string $lifetimePath = self::XML_PATH_COOKIE_LIFETIME,
        string $sessionName = 'PHPSESSID'
    ) {
        parent::__construct(
            $validatorFactory,
            $scopeConfig,
            $stringHelper,
            $request,
            $filesystem,
            $deploymentConfig,
            $scopeType,
            $lifetimePath
        );

        // Explicitly store the session name in $options so initIniOptions()
        // includes ini_set('session.name', ...) on every call, resetting any
        // stale admin contamination before session_start() reads the cookie.
        $this->setName($sessionName);
    }
}

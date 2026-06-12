<?php
/**
 * Copyright © Rees Solutions. All rights reserved.
 */
declare(strict_types=1);

namespace Reessolutions\WorkerMode\Plugin\App;

use Magento\Framework\App\ObjectManager as FrameworkObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Opengento\Application\App\Http;

/**
 * Restores the correct area ObjectManager as ObjectManager::getInstance() target before each request.
 *
 * In FrankenPHP worker mode ObjectManager::$_instance is a static property overwritten by every new
 * ObjectManager constructor call. After the first webapi_rest (or adminhtml) bootstrap creates its OM,
 * getInstance() permanently returns that area's OM for all subsequent calls on this worker.
 *
 * Framework classes that lazily init dependencies via getInstance() — FrontController.appState,
 * Page\Config.areaResolver, Asset\Repository.themeProvider — then receive wrong-area objects during
 * frontend rendering. Symptom: asset URLs contain 'webapi_rest/_view/en_US/', robots meta is
 * NOINDEX,NOFOLLOW, and body class is '--' (routing context missing for webapi_rest area).
 *
 * This plugin runs as beforeLaunch on Opengento\Application\App\Http before the scope-specific plugin
 * chain executes. It restores $_instance to the OM that owns this bootstrap so every getInstance()
 * call during the request returns the correct area OM.
 */
class ObjectManagerContextPlugin
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeLaunch(Http $subject): void
    {
        FrameworkObjectManager::setInstance($this->objectManager);
    }
}

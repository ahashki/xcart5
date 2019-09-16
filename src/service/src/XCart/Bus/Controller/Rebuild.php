<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XCart\Bus\Controller;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Silex\Application;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use XCart\Bus\Auth\EmergencyCodeService;
use XCart\Bus\Auth\TokenService;
use XCart\Bus\Auth\XC5LoginService;
use XCart\Bus\Client\XCart;
use XCart\Bus\Domain\Module;
use XCart\Bus\Editions\Core\EditionStorage;
use XCart\Bus\Exception\XC5Unavailable;
use XCart\Bus\Helper\UrlBuilder;
use XCart\Bus\Query\Context;
use XCart\Bus\Query\Data\CoreConfigDataSource;
use XCart\Bus\Query\Data\InstalledModulesDataSource;
use XCart\Bus\Query\Data\MarketplaceModulesDataSource;
use XCart\Bus\Query\Data\ScenarioDataSource;
use XCart\Bus\Query\Data\ScriptStateDataSource;
use XCart\Bus\Query\Data\SetDataSource;
use XCart\Bus\Query\Data\TmpInstalledModulesDataSource;
use XCart\Bus\Query\Data\UploadedModulesDataSource;
use XCart\Bus\Query\Resolver\ModulesResolver;
use XCart\Bus\Query\Resolver\RebuildResolver;
use XCart\Bus\Rebuild\Executor;
use XCart\Bus\Rebuild\Executor\RebuildLockManager;
use XCart\Bus\Rebuild\Executor\Step\Execute\PostUpgradeHook;
use XCart\Bus\Rebuild\Executor\Step\Execute\UpdateModulesList;
use XCart\Bus\Rebuild\Executor\Step\Execute\UpdateScriptState;
use XCart\Bus\Rebuild\Executor\Step\StepInterface;
use XCart\Bus\Rebuild\Scenario\ChangeUnitProcessor;
use XCart\SilexAnnotations\Annotations\Router;
use XCart\SilexAnnotations\Annotations\Service;

/**
 * @Service\Service()
 * @Router\Controller()
 */
class Rebuild
{
    /**
     * @var Application
     */
    private $app;

    /**
     * @var TokenService
     */
    private $tokenService;

    /**
     * @var ScenarioDataSource
     */
    private $scenarioDataSource;

    /**
     * @var ScriptStateDataSource
     */
    private $scriptStateDataSource;

    /**
     * @var ChangeUnitProcessor
     */
    private $changeUnitProcessor;

    /**
     * @var RebuildResolver
     */
    private $rebuildResolver;

    /**
     * @var ModulesResolver
     */
    private $modulesResolver;

    /**
     * @var XC5LoginService
     */
    private $xc5LoginService;

    /**
     * @var EmergencyCodeService
     */
    private $emergencyCodeService;

    /**
     * @var XCart
     */
    private $xcartClient;

    /**
     * @var EditionStorage
     */
    private $editionStorage;

    /**
     * @var UploadedModulesDataSource
     */
    private $uploadedModulesDataSource;

    /**
     * @var InstalledModulesDataSource
     */
    private $installedModulesDataSource;

    /**
     * @var TmpInstalledModulesDataSource
     */
    private $tmpInstalledModulesDataSource;

    /**
     * @var CoreConfigDataSource
     */
    private $coreConfigDataSource;

    /**
     * @var MarketplaceModulesDataSource
     */
    private $marketplaceModulesDataSource;

    /**
     * @var SetDataSource
     */
    private $setDataSource;

    /**
     * @var RebuildLockManager
     */
    private $rebuildLockManager;

    /**
     * @var UrlBuilder
     */
    private $urlBuilder;

    /**
     * @var Executor
     */
    private $executor;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var bool
     */
    private $demoMode;

    /**
     * @param Application                   $app
     * @param TokenService                  $tokenService
     * @param ScenarioDataSource            $scenarioDataSource
     * @param ScriptStateDataSource         $scriptStateDataSource
     * @param ChangeUnitProcessor           $changeUnitProcessor
     * @param RebuildResolver               $rebuildResolver
     * @param ModulesResolver               $modulesResolver
     * @param XC5LoginService               $xc5LoginService
     * @param EmergencyCodeService          $emergencyCodeService
     * @param XCart                         $xcartClient
     * @param EditionStorage                $editionStorage
     * @param UploadedModulesDataSource     $uploadedModulesDataSource
     * @param InstalledModulesDataSource    $installedModulesDataSource
     * @param TmpInstalledModulesDataSource $tmpInstalledModulesDataSource
     * @param CoreConfigDataSource          $coreConfigDataSource
     * @param MarketplaceModulesDataSource  $marketplaceModulesDataSource
     * @param SetDataSource                 $setDataSource
     * @param RebuildLockManager            $rebuildLockManager
     * @param UrlBuilder                    $urlBuilder
     * @param Executor                      $executor
     * @param Context                       $context
     */
    public function __construct(
        Application $app,
        TokenService $tokenService,
        ScenarioDataSource $scenarioDataSource,
        ScriptStateDataSource $scriptStateDataSource,
        ChangeUnitProcessor $changeUnitProcessor,
        RebuildResolver $rebuildResolver,
        ModulesResolver $modulesResolver,
        XC5LoginService $xc5LoginService,
        EmergencyCodeService $emergencyCodeService,
        XCart $xcartClient,
        EditionStorage $editionStorage,
        UploadedModulesDataSource $uploadedModulesDataSource,
        InstalledModulesDataSource $installedModulesDataSource,
        TmpInstalledModulesDataSource $tmpInstalledModulesDataSource,
        CoreConfigDataSource $coreConfigDataSource,
        MarketplaceModulesDataSource $marketplaceModulesDataSource,
        SetDataSource $setDataSource,
        RebuildLockManager $rebuildLockManager,
        UrlBuilder $urlBuilder,
        Executor $executor,
        Context $context
    ) {
        $this->app                           = $app;
        $this->tokenService                  = $tokenService;
        $this->scenarioDataSource            = $scenarioDataSource;
        $this->scriptStateDataSource         = $scriptStateDataSource;
        $this->changeUnitProcessor           = $changeUnitProcessor;
        $this->rebuildResolver               = $rebuildResolver;
        $this->modulesResolver               = $modulesResolver;
        $this->xc5LoginService               = $xc5LoginService;
        $this->emergencyCodeService          = $emergencyCodeService;
        $this->xcartClient                   = $xcartClient;
        $this->editionStorage                = $editionStorage;
        $this->uploadedModulesDataSource     = $uploadedModulesDataSource;
        $this->installedModulesDataSource    = $installedModulesDataSource;
        $this->tmpInstalledModulesDataSource = $tmpInstalledModulesDataSource;
        $this->coreConfigDataSource          = $coreConfigDataSource;
        $this->marketplaceModulesDataSource  = $marketplaceModulesDataSource;
        $this->setDataSource                 = $setDataSource;
        $this->rebuildLockManager            = $rebuildLockManager;
        $this->urlBuilder                    = $urlBuilder;
        $this->executor                      = $executor;
        $this->context                       = $context;

        $this->demoMode = $app['xc_config']['demo']['demo_mode'] ?? false;
    }

    /**
     * @return Response
     * @throws Exception
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/clear-cache"),
     *     @Router\Before("XCart\Bus\Controller\Rebuild:authChecker")
     * )
     */
    public function clearCacheAction(): Response
    {
        if ($this->demoMode) {
            return new Response('', 404);
        }

        $this->coreConfigDataSource->dataDate  = 0;
        $this->coreConfigDataSource->cacheDate = 0;

        $this->marketplaceModulesDataSource->clear();
        $this->setDataSource->clear();

        return new RedirectResponse($this->xcartClient->getUpgradeFrontendURL());
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/rebuild"),
     *     @Router\Before("XCart\Bus\Controller\Rebuild:authChecker")
     * )
     */
    public function rebuildAction(Request $request): Response
    {
        if ($this->demoMode) {
            return new Response('', 404);
        }

        $returnUrl = $request->get('returnUrl');

        $scenario         = $this->changeUnitProcessor->process([], []);
        $scenario['id']   = uniqid('scenario', true);
        $scenario['type'] = 'common';
        $scenario['date'] = time();

        if ($returnUrl && $this->urlBuilder->isSelfURL($returnUrl)) {
            $scenario['returnUrl'] = $returnUrl;
        }

        $this->scenarioDataSource->saveOne($scenario);

        $args  = [
            'id'     => $scenario['id'],
            'reason' => 'redeploy',
        ];
        $state = $this->rebuildResolver->startRebuild(null, $args, null, new ResolveInfo([]));

        return new RedirectResponse($this->xcartClient->getUpgradeFrontendURL() . 'rebuild/' . $state->id);
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/upgrade53"),
     *     @Router\Before("XCart\Bus\Controller\Rebuild:authCodeChecker")
     * )
     */
    public function upgrade53Action(Request $request): Response
    {
        // convert tmp.busInstalledModulesStorage.data to busInstalledModulesStorage.data
        $installedModules = $this->tmpInstalledModulesDataSource->getWrappedData();
        $this->installedModulesDataSource->saveAll($installedModules);
        $this->tmpInstalledModulesDataSource->clear();

        $modules = [];
        foreach ($request->get('modules') ?: [] as $moduleId => $version) {
            $modules[Module::convertModuleId($moduleId)] = $version;
        }

        $serviceVersion = $modules['XC-Service'] ?? null;

        // add xc-service to marketplace modules by saveOne call
        $serviceModule = Module::generateServiceModule($serviceVersion);
        $this->marketplaceModulesDataSource->saveOne([$serviceModule], 'XC-Service');

        $modules['CDev-Core'] = $modules['Core'];
        unset($modules['Core']);

        $changeUnits = [];
        foreach ($modules as $id => $version) {
            if ($version === 'remove') {
                $changeUnits[] = [
                    'id'     => $id,
                    'remove' => true,
                ];
            } elseif ($version === 'disable') {
                $changeUnits[] = [
                    'id'     => $id,
                    'enable' => false,
                ];
            } else {
                /** @var Module $module */
                $changeUnits[] = [
                    'id'      => $id,
                    'upgrade' => true,
                    'version' => $version, // new version (must be present in marketplaceModulesDataSource)
                ];
            }
        }

        $scenario         = $this->changeUnitProcessor->process([], $changeUnits);
        $scenario['id']   = uniqid('scenario', true);
        $scenario['type'] = 'upgrade';
        $scenario['date'] = time();

        $this->scenarioDataSource->saveOne($scenario);

        $args = [
            'id'     => $scenario['id'],
            'reason' => 'upgrade',
        ];

        $this->rebuildLockManager->clearAnySetRebuildFlags();
        $state              = $this->rebuildResolver->startRebuild(null, $args, null, new ResolveInfo([]));
        $state->canRollback = false;

        do {
            $state = $this->executor->execute(clone $state, StepInterface::ACTION_SKIP_STEP);
        } while (!in_array($state->stepState->id, [UpdateScriptState::class, UpdateModulesList::class], true));

        $this->scriptStateDataSource->saveOne($state, $state->id);

        $authCode = $request->get('auth_code') ?: null;

        return new RedirectResponse($this->xcartClient->getUpgradeFrontendURL() . 'rebuild/' . $state->id . '?scode=' . $authCode);
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/install"),
     *     @Router\Before("XCart\Bus\Controller\Rebuild:authCodeChecker")
     * )
     */
    public function installAction(Request $request): Response
    {
        $this->rebuildLockManager->clearAnySetRebuildFlags();

        $coreVersion = $request->get('version') ?: null;
        $service     = Yaml::parseFile($this->app['config']['root_dir'] . 'service/src/service.yaml');

        $this->installedModulesDataSource->clear();
        $this->installedModulesDataSource->fillWithCoreModules($coreVersion, $service['Version']);

        $this->coreConfigDataSource->version = $coreVersion;

        $this->uploadedModulesDataSource->clear();
        $this->uploadedModulesDataSource->fillWithAllPossibleModules();

        $enabledModulesJSON = $request->get('modules') ?: null;
        $enabledModules     = $enabledModulesJSON ? json_decode($enabledModulesJSON, true) : [];

        $changeUnits = [];
        foreach ($this->uploadedModulesDataSource->getAll() as $info) {
            /** @var Module $module */
            $module        = $info[0];
            $changeUnits[] = [
                'id'      => $module->id,
                'install' => true,
                'version' => $module->version,
                'enable'  => $this->isModuleEnabled($enabledModules, $module->author, $module->name),
            ];
        }

        $scenario = $this->changeUnitProcessor->process([], $changeUnits);

        $scenario['id']   = uniqid('scenario', true);
        $scenario['type'] = 'common';
        $scenario['date'] = time();

        $returnUrl = $request->get('returnUrl');
        if ($returnUrl && $this->urlBuilder->isSelfURL($returnUrl)) {
            $scenario['returnUrl'] = $returnUrl;
        }

        $this->scenarioDataSource->saveOne($scenario);

        $args = [
            'id'     => $scenario['id'],
            'type'   => 'install',
            'reason' => 'install',
        ];

        $state = $this->rebuildResolver->startRebuild(null, $args, null, new ResolveInfo([]));

        $authCode = $request->get('auth_code') ?: null;

        return new RedirectResponse($this->xcartClient->getUpgradeFrontendURL() . 'iframe/rebuild/' . $state->id . '?scode=' . $authCode);
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/rebuildToEdition"),
     *     @Router\Before("XCart\Bus\Controller\Auth:authChecker")
     * )
     */
    public function rebuildToEditionAction(Request $request): Response
    {
        $returnUrl   = $request->get('returnUrl');
        $editionName = $request->get('editionName');

        $changeUnits = $this->editionStorage->getChangeUnits($editionName);

        $scenario                   = $this->changeUnitProcessor->process([], $changeUnits);
        $scenario['id']             = uniqid('scenario', true);
        $scenario['type']           = 'common';
        $scenario['date']           = time();
        $scenario['store_metadata'] = [
            'editionName' => $editionName,
        ];

        if ($returnUrl && $this->urlBuilder->isSelfURL($returnUrl)) {
            $scenario['returnUrl'] = $returnUrl;
        }

        $this->scenarioDataSource->saveOne($scenario);

        $args = [
            'id'     => $scenario['id'],
            'reason' => 'module-state',
        ];

        $state = $this->rebuildResolver->startRebuild(null, $args, null, new ResolveInfo([]));

        return new RedirectResponse($this->xcartClient->getUpgradeFrontendURL() . 'rebuild/' . $state->id);
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     *
     * @Router\Route(
     *     @Router\Request(method="GET", uri="/removeUnallowedModules"),
     *     @Router\Before("XCart\Bus\Controller\Rebuild:authChecker")
     * )
     */
    public function removeUnallowedModules(Request $request): Response
    {
        $unallowedModulesPage = $this->modulesResolver->resolvePage([], ['licensed' => false], $this->context, new ResolveInfo([]));
        /** @var Module[] $unallowedModules */
        $unallowedModules = $unallowedModulesPage['modules'] ?? [];

        $changeUnits = [];
        foreach ($unallowedModules as $module) {
            $changeUnits[] = [
                'id'     => $module->id,
                'remove' => true,
            ];
        }

        $scenario         = $this->changeUnitProcessor->process([], $changeUnits);
        $scenario['id']   = uniqid('scenario', true);
        $scenario['type'] = 'common';
        $scenario['date'] = time();

        $this->scenarioDataSource->saveOne($scenario);

        $args = [
            'id'     => $scenario['id'],
            'reason' => 'module-state',
        ];

        $state = $this->rebuildResolver->startRebuild(null, $args, null, new ResolveInfo([]));

        return new RedirectResponse($this->xcartClient->getUpgradeFrontendURL() . 'rebuild/' . $state->id);
    }

    /**
     * @param Request $request
     *
     * @return Response|null
     */
    public function authChecker(Request $request): ?Response
    {
        $tokenData = $this->tokenService->decodeToken($request->cookies->get('bus_token'));
        if (!$tokenData) {
            $xc5Cookie = $request->cookies->get(
                $this->xc5LoginService->getCookieName()
            );

            try {
                $shouldGenerateJWT = $this->xc5LoginService->checkXC5Cookie($xc5Cookie);
            } catch (XC5Unavailable $exception) {
                $shouldGenerateJWT = false;
            }

            if (!$shouldGenerateJWT) {
                return new Response(null, 401);
            }
        }

        return null;
    }

    /**
     * @param Request $request
     *
     * @return Response|null
     */
    public function authCodeChecker(Request $request): ?Response
    {
        $authCode = $request->get('auth_code');
        if ($authCode && $this->emergencyCodeService->checkAuthCode($authCode)) {
            return null;
        }

        return new Response(null, 401);
    }

    /**
     * @param array  $enabledModules
     * @param string $author
     * @param string $name
     *
     * @return bool
     */
    private function isModuleEnabled($enabledModules, $author, $name): bool
    {
        return isset($enabledModules[$author]) && in_array($name, $enabledModules[$author], true);
    }
}

<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XCart\Bus\Query\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use XCart\Bus\Core\Annotations\Resolver;
use XCart\Bus\Domain\Module;
use XCart\Bus\Helper\UrlBuilder;
use XCart\Bus\Query\Context;
use XCart\Bus\Query\Data\Flatten\Flatten;
use XCart\Bus\Query\Data\ModulesDataSource;
use XCart\Bus\Query\Data\ScenarioDataSource;
use XCart\Bus\Rebuild\Scenario\ChangeUnitProcessor;
use XCart\SilexAnnotations\Annotations\Service;

/**
 * @Service\Service()
 */
class ScenarioResolver
{
    /**
     * @var ScenarioDataSource
     */
    private $scenarioDataSource;

    /**
     * @var ModulesDataSource
     */
    private $modulesDataSource;

    /**
     * @var ChangeUnitProcessor
     */
    private $changeUnitProcessor;

    /**
     * @var ModulesResolver
     */
    private $modulesResolver;

    /**
     * @var UrlBuilder
     */
    private $urlBuilder;

    /**
     * @param ScenarioDataSource  $scenarioDataSource
     * @param ModulesDataSource   $modulesDataSource
     * @param ChangeUnitProcessor $changeUnitProcessor
     * @param ModulesResolver     $modulesResolver
     * @param UrlBuilder          $urlBuilder
     */
    public function __construct(
        ScenarioDataSource $scenarioDataSource,
        ModulesDataSource $modulesDataSource,
        ChangeUnitProcessor $changeUnitProcessor,
        ModulesResolver $modulesResolver,
        UrlBuilder $urlBuilder
    ) {
        $this->scenarioDataSource  = $scenarioDataSource;
        $this->changeUnitProcessor = $changeUnitProcessor;
        $this->modulesDataSource   = $modulesDataSource;
        $this->modulesResolver     = $modulesResolver;
        $this->urlBuilder          = $urlBuilder;
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return array
     *
     * @Resolver()
     */
    public function find($value, $args, Context $context, ResolveInfo $info): array
    {
        $scenario = $this->scenarioDataSource->find($args['id']);

        return $scenario ?: [];
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return array
     * @throws \Exception
     *
     * @Resolver()
     */
    public function createScenario($value, $args, Context $context, ResolveInfo $info): array
    {
        $scenario = $this->_createScenario($args);

        $result = $this->scenarioDataSource->saveOne($scenario);

        if ($result === false) {
            throw new \Exception("Can't create scenario");
        }

        return $scenario;
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return string
     * @throws \Exception
     *
     * @Resolver()
     */
    public function discardScenario($value, $args, Context $context, ResolveInfo $info): string
    {
        $scenarioId = $args['scenarioId'] ?? null;

        if (!$scenarioId) {
            throw new \Exception('No scenario id given');
        }

        $result = $this->scenarioDataSource->removeOne($scenarioId);

        if ($result === false) {
            throw new \Exception('No scenario found for id ' . $scenarioId);
        }

        return $scenarioId;
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return array
     * @throws \Exception
     *
     * @Resolver()
     */
    public function changeModulesState($value, $args, Context $context, ResolveInfo $info): array
    {
        $scenarioId = $args['scenarioId'] ?? null;

        $scenario = $this->scenarioDataSource->find($scenarioId);

        if (!$scenario) {
            throw new \Exception('No scenario found for id ' . ($scenarioId ?? '[empty]'));
        }

        $newScenario = $this->changeUnitProcessor->process($scenario, $args['states'] ?? []);

        $this->scenarioDataSource->saveOne($newScenario);

        return $newScenario;
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return array
     * @throws \Exception
     *
     * @Resolver()
     */
    public function changeSkinState($value, $args, Context $context, ResolveInfo $info): array
    {
        $moduleId = $args['moduleId'];

        $scenario = $this->_createScenario($args);
        $scenario = $this->changeSkin($scenario, $moduleId);

        $this->scenarioDataSource->saveOne($scenario);

        return $scenario;
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return array
     * @throws \Exception
     *
     * @Resolver()
     */
    public function mutateRemoveUnallowedModules($value, $args, Context $context, ResolveInfo $info): array
    {
        $unallowedModulesPage = $this->modulesResolver->resolvePage([], ['licensed' => false], $context, $info);
        /** @var Module[] $unallowedModules */
        $unallowedModules = $unallowedModulesPage['modules'] ?? [];

        $changeUnits = [];
        foreach ($unallowedModules as $module) {
            $changeUnits[] = [
                'id' => $module->id,
                'remove' => true
            ];
        }

        $scenario = $this->_createScenario([]);
        $scenario = $this->changeUnitProcessor->process($scenario, $changeUnits);

        $this->scenarioDataSource->saveOne($scenario);

        return $scenario;
    }

    /**
     * @param             $value
     * @param             $args
     * @param Context     $context
     * @param ResolveInfo $info
     *
     * @return array
     *
     * @Resolver()
     */
    public function resolveScenarioInfo($value, $args, Context $context, ResolveInfo $info): array
    {
        $module = $this->modulesResolver->getModule($value['id'], $context->languageCode);

        return array_replace(
            $value['info'] ?? [],
            ['moduleName' => $module ? $module->moduleName : '']
        );
    }

    /**
     * @param array  $scenario
     * @param string $moduleId
     *
     * @return array|null
     * @throws \Exception
     */
    private function changeSkin($scenario, $moduleId): array
    {
        $changeUnitsToDisable = $this->getDisableAllSkinsChangeUnits();
        $changeUnitsToEnable  = $moduleId !== 'standard'
            ? $this->getEnableSkinChangeUnits($moduleId)
            : [];

        $scenario = $this->changeUnitProcessor->process($scenario, $changeUnitsToDisable);
        $scenario = $this->changeUnitProcessor->process($scenario, $changeUnitsToEnable);

        return $scenario;
    }

    /**
     * @param string $moduleId
     *
     * @return array
     */
    private function getEnableSkinChangeUnits($moduleId): array
    {
        /** @var Module $module */
        $module = $this->modulesDataSource->findOne($moduleId, Flatten::RULE_LAST, [
            'type'      => 'skin',
            'installed' => true,
        ]);

        if ($module) {
            return [
                $module->id => [
                    'id'     => $module->id,
                    'enable' => true,
                ],
            ];
        }

        return [];
    }

    /**
     * @return array
     */
    private function getDisableAllSkinsChangeUnits(): array
    {
        $skins = $this->modulesDataSource->getSlice(Flatten::RULE_LAST, [
            'type'      => 'skin',
            'installed' => true,
            'enabled'   => 'enabled',
        ]);

        if ($skins) {
            return array_map(function ($module) {
                return [
                    'id'     => $module['id'],
                    'enable' => false,
                ];
            }, $skins);
        }

        return [];
    }

    /**
     * @param array $params
     *
     * @return array
     */
    private function _createScenario($params): array
    {
        $returnUrl = $params['returnUrl'] ?? null;

        return [
            'id'                 => uniqid('scenario', true),
            'date'               => time(),
            'updatedAt'          => 0,
            'type'               => $params['type'] ?? 'common',
            'modulesTransitions' => [],
            'changeUnits'        => [],
            'returnUrl'          => $this->urlBuilder->isSelfURL($returnUrl) ? $returnUrl : null,
        ];
    }
}

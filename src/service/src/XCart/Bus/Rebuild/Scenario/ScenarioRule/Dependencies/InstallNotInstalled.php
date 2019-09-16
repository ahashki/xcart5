<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XCart\Bus\Rebuild\Scenario\ScenarioRule\Dependencies;

use XCart\Bus\Rebuild\Scenario\ScenarioBuilder;
use XCart\Bus\Rebuild\Scenario\ScenarioRule\ScenarioRuleException;
use XCart\Bus\Rebuild\Scenario\Transition\EnableTransition;
use XCart\Bus\Rebuild\Scenario\Transition\InstallDisabledTransition;
use XCart\Bus\Rebuild\Scenario\Transition\InstallEnabledTransition;
use XCart\Bus\Rebuild\Scenario\Transition\TransitionInterface;

class InstallNotInstalled extends DependencyRuleAbstract
{
    /**
     * @param TransitionInterface $transition
     * @param ScenarioBuilder     $scenarioBuilder
     *
     * @throws ScenarioRuleException
     */
    public function applyTransform(TransitionInterface $transition, ScenarioBuilder $scenarioBuilder): void
    {
        $installedModule = $this->getInstalledModule($transition->getModuleId());
        $dependencies    = $this->getDependencies($transition);

        foreach ($dependencies as $dependency) {
            $id = $this->getDependencyId($dependency);

            $installedDependency = $this->getInstalledModule($id);
            if (!$installedDependency) {
                if (($installedModule && $installedModule->enabled)
                    || $transition instanceof InstallEnabledTransition
                    || $transition instanceof EnableTransition
                ) {
                    $newTransition = new InstallEnabledTransition($id);
                } else {
                    $newTransition = new InstallDisabledTransition($id);
                }

                $this->fillTransitionInfo($newTransition);

                $scenarioBuilder->addTransition($newTransition);
            }
        }
    }

    /**
     * @param TransitionInterface $transition
     * @param ScenarioBuilder     $scenarioBuilder
     */
    public function applyFilter(TransitionInterface $transition, ScenarioBuilder $scenarioBuilder): void
    {
    }
}

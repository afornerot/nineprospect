<?php

namespace App\Service;

use App\Repository\ConfigRepository;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class ConfigParameterBag extends ParameterBag
{
    public function __construct(
        private ConfigRepository $configRepository
    ) {}

    public function load(): void
    {
        $this->clear();

        $configs = $this->configRepository->findAll();

        if (empty($configs)) {
            return;
        }

        $values = [];
        foreach ($configs as $config) {
            $values[$config->getCode()] = $config->getValue();
        }

        $this->add($values);
    }

    public function reload(): void
    {
        $this->load();
    }
}
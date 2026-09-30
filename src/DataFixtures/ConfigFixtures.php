<?php

namespace App\DataFixtures;

use App\Entity\Config;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ConfigFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $configs = [
            [
                'code' => 'appFooterLinkLabel',
                'title' => 'Libellé du lien footer',
                'defaultValue' => 'Powered by Nineprospect',
                'type' => Config::TYPE_STRING,
                'configGroup' => 'Branding',
                'order' => 1,
            ],
            [
                'code' => 'appFooterLinkUrl',
                'title' => 'URL du lien footer',
                'defaultValue' => 'https://github.com/afornerot/nineprospect',
                'type' => Config::TYPE_STRING,
                'configGroup' => 'Branding',
                'order' => 2,
            ],
            [
                'code' => 'appFooterLogoUrl',
                'title' => 'Logo du footer',
                'defaultValue' => '/medias/logo/logo.png',
                'type' => Config::TYPE_LOGO,
                'configGroup' => 'Branding',
                'order' => 3,
            ],
        ];

        foreach ($configs as $item) {
            $config = $manager->getRepository(Config::class)->findOneBy(['code' => $item['code']]);
            if ($config) {
                continue;
            }

            $config = new Config();
            $config->setCode($item['code']);
            $config->setTitle($item['title']);
            $config->setDefaultValue($item['defaultValue']);
            $config->setType($item['type']);
            $config->setConfigGroup($item['configGroup']);
            $config->setOrder($item['order']);

            $manager->persist($config);
        }

        $manager->flush();
    }
}
<?php

namespace App\Form;

use App\Entity\Config;
use Bnine\FilesBundle\Form\Type\IconUploadType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $config = $builder->getData();
        $type = $config instanceof Config ? $config->getType() : Config::TYPE_STRING;

        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ]);

        if (Config::TYPE_LOGO === $type) {
            $builder->add('rawValue', IconUploadType::class, [
                'label' => false,
                'mapped' => false,
                'data' => $config instanceof Config ? $config->getRawValue() : null,
                'icon_label' => 'Logo',
                'icon_empty_preview' => $config instanceof Config ? $config->getDefaultValue() : null,
                'icon_domain' => 'footer',
                'icon_entity_id' => 0,
                'crop' => false,
                'preview_max_height' => 80,
            ]);
        } else {
            $builder->add('rawValue', TextType::class, [
                'label' => 'Valeur',
                'mapped' => false,
                'required' => false,
                'data' => $config instanceof Config ? $config->getRawValue() : null,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Config::class,
        ]);
    }
}
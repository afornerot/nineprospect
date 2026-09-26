<?php

namespace App\Form;

use App\Entity\PipelineEtape;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PipelineEtape>
 */
class PipelineEtapeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de l\'étape',
                'attr' => ['placeholder' => 'Visio', 'class' => 'form-control-sm'],
            ])

            ->add('ordre', IntegerType::class, [
                'label' => 'Ordre',
                'attr' => ['class' => 'form-control-sm'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PipelineEtape::class,
        ]);
    }
}

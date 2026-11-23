<?php

namespace App\Form;

use App\Entity\Objective;
use App\Entity\Produit;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Objective>
 */
class ObjectiveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ])

            ->add('startDate', DateType::class, [
                'label' => 'Date de début',
                'widget' => 'single_text',
            ])

            ->add('endDate', DateType::class, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
            ])

            ->add('produit', EntityType::class, [
                'label' => 'Produit',
                'class' => Produit::class,
                'choice_label' => 'titre',
                'placeholder' => '— Tous les produits —',
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            ->add('type', ChoiceType::class, [
                'label' => 'Type d\'objectif',
                'choices' => array_flip(Objective::TYPES),
            ])

            ->add('target', NumberType::class, [
                'label' => 'Cible',
                'html5' => true,
                'input' => 'number',
                'attr' => ['step' => 'any', 'min' => 0],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Objective::class,
            'mode' => 'update',
        ]);
    }
}

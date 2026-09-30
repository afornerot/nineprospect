<?php

namespace App\Form;

use App\Entity\Cible;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProspectPublicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $cibles = $options['cibles'];

        $builder
            ->add('typePersonne', ChoiceType::class, [
                'label' => 'Vous êtes',
                'choices' => [
                    'Une personne morale (entreprise)' => 'morale',
                    'Une personne physique (particulier)' => 'physique',
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('siren', TextType::class, [
                'label' => 'SIREN',
                'required' => false,
                'attr' => [
                    'placeholder' => '123456789',
                    'maxlength' => 9,
                    'class' => 'form-control',
                ],
                'help' => 'Obligatoire pour les entreprises',
            ])
            ->add('raisonSociale', TextType::class, [
                'label' => 'Raison sociale',
                'required' => false,
                'attr' => ['class' => 'form-control', 'readonly' => true],
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['class' => 'form-control', 'readonly' => true],
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => ['class' => 'form-control', 'readonly' => true],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => ['class' => 'form-control', 'readonly' => true],
            ])
            ->add('naf', TextType::class, [
                'label' => 'Code NAF',
                'required' => false,
                'attr' => ['class' => 'form-control', 'readonly' => true],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => 'contact@exemple.fr'],
            ])
            ->add('telephone', TextType::class, [
                'label' => 'Téléphone',
                'required' => true,
                'attr' => ['class' => 'form-control', 'placeholder' => '06 12 34 56 78'],
            ]);

        $choices = [];
        foreach ($cibles as $cible) {
            $choices[$cible->getTitre()] = $cible->getId();
        }

        $builder->add('cible', ChoiceType::class, [
            'label' => 'Cible souhaitée',
            'choices' => $choices,
            'required' => true,
            'attr' => ['class' => 'form-select'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'cibles' => [],
        ]);

        $resolver->setAllowedTypes('cibles', 'array');
    }
}

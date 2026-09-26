<?php

namespace App\Form;

use App\Entity\Contact;
use App\Entity\Prospect;
use App\Form\Type\PhoneNumberType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Contact>
 */
class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print me-1'],
            ])

            ->add('prospect', EntityType::class, [
                'label' => 'Prospect',
                'class' => Prospect::class,
                'choice_label' => 'nom',
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])

            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
            ])

            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
            ])

            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])

            ->add('telephoneBrut', PhoneNumberType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'default_region' => 'FR',
                'invalid_message' => 'Numéro de téléphone invalide.',
            ])

            ->add('poste', TextType::class, [
                'label' => 'Poste',
                'required' => false,
            ])

            ->add('linkedinUrl', UrlType::class, [
                'label' => 'Profil LinkedIn',
                'required' => false,
            ])

            ->add('estPrincipal', CheckboxType::class, [
                'label' => 'Contact principal du prospect',
                'required' => false,
            ])

            ->add('redirect', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}

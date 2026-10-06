<?php

namespace App\Form;

use App\Entity\Campagne;
use App\Entity\Category;
use App\Entity\Departement;
use App\Entity\PipelineEtape;
use App\Entity\Prospect;
use App\Entity\Sprint;
use App\Entity\User;
use App\Enum\PipelineStatut;
use App\Form\Type\PhoneNumberType;
use Bnine\FilesBundle\Form\Type\IconUploadType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Prospect>
 */
class ProspectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Valider',
                'attr' => ['class' => 'btn btn-success no-print'],
            ])
        ;

        // Le logo n'est éditable qu'en update (l'id du prospect n'existe pas
        // encore en création, et le bundle upload nécessite domain+id).
        if ('update' === $options['mode']) {
            $builder->add('logo', IconUploadType::class, [
                'label' => false,
                'icon_label' => 'Logo',
                'icon_empty_preview' => 'medias/logo.png',
                'icon_domain' => 'logo',
                'icon_entity_id' => $options['prospect_id'],
                'crop' => false,
                'preview_max_height' => 80,
            ]);
        }

        $builder
            ->add('nom', TextType::class, [
                'label' => 'Entreprise / prospect',
            ])

            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
                'attr' => ['maxlength' => 180, 'placeholder' => 'contact@exemple.fr'],
                'help' => 'Initialisé avec l\'email du contact principal s\'il est vide.',
            ])

            ->add('telephone', PhoneNumberType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'default_region' => 'FR',
                'attr' => [
                    'placeholder' => '06 12 34 56 78 ou +33 6 12 34 56 78',
                ],
                'help' => 'Format international ou national FR. Stocké en E164. Initialisé avec le téléphone du contact principal s\'il est vide.',
            ])

            ->add('linkedinUrl', UrlType::class, [
                'label' => 'LinkedIn',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'https://www.linkedin.com/in/…',
                ],
                'help' => 'Initialisé avec le LinkedIn du contact principal s\'il est vide.',
            ])

            ->add('siteUrl', UrlType::class, [
                'label' => 'Site web',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'https://www.exemple.fr',
                ],
            ])

            ->add('siren', TextType::class, [
                'label' => 'SIREN',
                'required' => false,
                'attr' => ['maxlength' => 20],
            ])

            ->add('siret', TextType::class, [
                'label' => 'SIRET',
                'required' => false,
                'attr' => ['maxlength' => 20],
            ])

            ->add('naf', TextType::class, [
                'label' => 'NAF',
                'required' => false,
                'attr' => ['maxlength' => 20],
            ])

            ->add('rcsRm', TextType::class, [
                'label' => 'RCS / RM',
                'required' => false,
                'attr' => ['maxlength' => 80],
            ])

            ->add('numTva', TextType::class, [
                'label' => 'N° TVA',
                'required' => false,
                'attr' => ['maxlength' => 32],
            ])

            ->add('idDolibarr', TextType::class, [
                'label' => 'ID Dolibarr',
                'required' => false,
                'attr' => ['maxlength' => 32, 'placeholder' => 'ex. 1234'],
            ])

            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'Numéro et rue',
                    'autocomplete' => 'off',
                    'class' => 'js-address-autocomplete',
                ],
                'help' => 'Saisissez le numéro et la rue, puis choisissez une suggestion (API Adresse gouv).',
            ])

            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ])

            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ])

            ->add('pays', TextType::class, [
                'label' => 'Pays',
                'required' => false,
                'attr' => ['maxlength' => 80],
            ])

            ->add('departement', EntityType::class, [
                'label' => 'Département',
                'class' => Departement::class,
                'choice_label' => static fn (Departement $d): string => sprintf('%s - %s', (string) $d->getNumero(), (string) $d->getNom()),
                'placeholder' => '—',
                'required' => false,
                // Expose le code département (ex. "01", "75", "972") sur
                // chaque <option> via data-numero, pour que l'autocompletion
                // d'adresse (API data.gouv.fr) puisse pré-sélectionner le
                // département à partir du code postal de la suggestion.
                'choice_attr' => static fn (Departement $d): array => [
                    'data-numero' => (string) $d->getNumero(),
                ],
            ])

            ->add('latitude', TextType::class, [
                'label' => 'Latitude',
                'required' => false,
                'attr' => ['placeholder' => 'ex. 48.8566', 'autocomplete' => 'off'],
            ])

            ->add('longitude', TextType::class, [
                'label' => 'Longitude',
                'required' => false,
                'attr' => ['placeholder' => 'ex. 2.3522', 'autocomplete' => 'off'],
            ])

            ->add('campagne', EntityType::class, [
                'label' => 'Campagne',
                'class' => Campagne::class,
                'choice_label' => 'libelle',
                'placeholder' => 'Hors Meta',
                'required' => false,
            ])

            ->add('sprints', EntityType::class, [
                'label' => 'Vagues de traitement',
                'class' => Sprint::class,
                'choice_label' => static fn (Sprint $sprint): string => null !== $sprint->getLibelle() && '' !== $sprint->getLibelle()
                    ? 'Vague '.$sprint->getNumero().' — '.$sprint->getLibelle()
                    : 'Vague '.$sprint->getNumero(),
                'multiple' => true,
                'required' => false,
                'mapped' => false,
                'query_builder' => static fn (EntityRepository $er): \Doctrine\ORM\QueryBuilder => $er->createQueryBuilder('s')->orderBy('s.numero', 'DESC'),
                'attr' => ['class' => 'select2'],
            ])

            ->add('contacte', CheckboxType::class, [
                'label' => 'Contacté',
                'required' => false,
            ])

            ->add('datePremierContact', DateType::class, [
                'label' => 'Date du premier contact',
                'widget' => 'single_text',
                'required' => false,
            ])

            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
                'attr' => ['rows' => 4],
            ])

            ->add('users', EntityType::class, [
                'label' => 'Affecté à',
                'class' => User::class,
                'choice_label' => 'username',
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            ->add('categories', EntityType::class, [
                'label' => 'Catégories',
                'class' => Category::class,
                'choice_label' => 'nom',
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('c')->orderBy('c.nom', 'ASC'),
            ])

            ->add('redirect', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ]);

        // Champs pipeline dynamiques : une entrée statut + une date par étape
        // du pipeline de la vague sélectionnée (aucune étape = aucun champ).
        foreach ($options['etapes'] as $etape) {
            if (!$etape instanceof PipelineEtape) {
                continue;
            }

            $builder
                ->add('etape'.$etape->getId(), ChoiceType::class, [
                    'label' => $etape->getNom(),
                    'choices' => PipelineStatut::choices(),
                    'required' => false,
                    'mapped' => false,
                ])
                ->add('dateEtape'.$etape->getId(), DateType::class, [
                    'label' => 'Date '.$etape->getNom(),
                    'widget' => 'single_text',
                    'required' => false,
                    'mapped' => false,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Prospect::class,
            'mode' => 'update',
            'etapes' => [],
            'prospect_id' => 0,
            // Les champs pipeline dépendent du pipeline de la vague soumise :
            // un champ d'un ancien pipeline reste acceptable (ignoré).
            'allow_extra_fields' => true,
            'attr' => ['autocomplete' => 'off'],
        ]);
        $resolver->setAllowedTypes('etapes', 'array');
        $resolver->setAllowedTypes('prospect_id', ['int', 'string']);
    }
}

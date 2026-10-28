<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\User;
use App\Form\ContactType;
use App\Http\ContexteRetour;
use App\Repository\ContactRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SortDirection;

#[Route('/user/contacts')]
class ContactController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private ContactRepository $contacts,
        private UserRepository $users,
        private EntityManagerInterface $em,
        protected ContexteRetour $contexteRetour,
    ) {
    }

    #[Route('', name: 'app_user_contacts')]
    public function list(Request $request): Response
    {
        $user = $this->paramUtilisateur($request);

        $qb = $this->contacts->createQueryBuilder('c')
            ->addSelect('p')
            ->leftJoin('c.prospect', 'p')
            ->orderBy('c.nom', SortDirection::Ascending)
            ->addOrderBy('c.prenom', SortDirection::Ascending);

        if (null !== $user) {
            $qb->innerJoin('p.users', 'cu')
                ->andWhere('cu.id = :cuId')
                ->setParameter('cuId', $user);
        }

        return $this->renderLayout('contacts/list.html.twig', 'Contacts', [
            'routesubmit' => 'app_user_contacts_submit',
            'routeupdate' => 'app_user_contacts_update',
            'routeprospect' => 'app_user_prospects_show',
            'contacts' => $qb->getQuery()->getResult(),
            'utilisateurs' => $this->users->findAll(),
            'utilisateurFiltre' => $user?->getId(),
        ]);
    }

    #[Route('/submit', name: 'app_user_contacts_submit')]
    public function submit(Request $request): Response
    {
        $contact = new Contact();

        // Pré-remplissage quand le formulaire est ouvert depuis une fiche
        // prospect : le contact appartient alors à ce prospect.
        $prospectId = $request->query->getInt('prospect');
        if (0 !== $prospectId && null !== ($prospect = $this->em->find(Prospect::class, $prospectId))) {
            $contact->setProspect($prospect);
        }

        $form = $this->createForm(ContactType::class, $contact);
        $retour = $this->contexteRetour->url($request, 'app_user_contacts');
        $form->get('retour')->setData($retour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($contact);
            $this->em->flush();

            return $this->redirectionRetour($form, 'app_user_contacts');
        }

        return $this->renderLayout('contacts/edit.html.twig', 'Nouveau contact', [
            'mode' => 'submit',
            'form' => $form,
            'retour' => $retour,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_contacts_update')]
    public function update(int $id, Request $request): Response
    {
        $contact = $this->contacts->find($id);
        if (!$contact) {
            return $this->contexteRetour->redirection($request, 'app_user_contacts');
        }

        $form = $this->createForm(ContactType::class, $contact);
        $retour = $this->contexteRetour->url($request, 'app_user_contacts');
        $form->get('retour')->setData($retour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectionRetour($form, 'app_user_contacts');
        }

        return $this->renderLayout('contacts/edit.html.twig', 'Modification = '.$contact->getNomComplet(), [
            'routedelete' => 'app_user_contacts_delete',
            'mode' => 'update',
            'form' => $form,
            'contact' => $contact,
            'retour' => $retour,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_contacts_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $contact = $this->contacts->find($id);
        if (!$contact) {
            return $this->contexteRetour->redirection($request, 'app_user_contacts');
        }

        return $this->deleteEntity($request, $contact, $id, 'delete-contact', 'contacts', $this->em, [
            'list' => 'app_user_contacts',
            'update' => 'app_user_contacts_update',
            'conflictMessage' => 'Suppression impossible : ce contact est encore référencé par une action.',
        ]);
    }

    /**
     * Le retour voyage dans le champ caché `retour` : figé au GET, il reste
     * valable même si l'utilisateur a navigué dans un autre onglet entre la
     * saisie et la validation.
     *
     * @param FormInterface<Contact> $form
     */
    private function redirectionRetour(FormInterface $form, string $routeFallback): RedirectResponse
    {
        return $this->contexteRetour->redirectionVers($form->get('retour')->getData(), $routeFallback);
    }

    /**
     * Utilisateur ciblé par le filtre « Affecté à » (?utilisateur=).
     */
    private function paramUtilisateur(Request $request): ?User
    {
        $userId = $this->lireParamEntier($request, 'utilisateur');
        if (null === $userId) {
            return null;
        }

        $user = $this->users->find($userId);

        return $user instanceof User ? $user : null;
    }

    private function lireParamEntier(Request $request, string $nom): ?int
    {
        $valeur = $request->query->all()[$nom] ?? null;
        if (!is_scalar($valeur)) {
            return null;
        }

        $texte = trim((string) $valeur);
        if ('' === $texte) {
            return null;
        }

        return filter_var($texte, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
    }
}

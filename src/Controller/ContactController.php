<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Contact;
use App\Entity\User;
use App\Form\ContactType;
use App\Repository\ContactRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        $redirect = $this->resolveRedirect($request, $contact);

        $form = $this->createForm(ContactType::class, $contact);
        $form->get('redirect')->setData($redirect);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($contact);
            $this->em->flush();

            return $this->redirectTo($redirect, $contact->getProspect()?->getId());
        }

        return $this->renderLayout('contacts/edit.html.twig', 'Nouveau contact', [
            'routecancel' => 'app_user_contacts',
            'mode' => 'submit',
            'form' => $form,
            'redirect' => $redirect,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_contacts_update')]
    public function update(int $id, Request $request): Response
    {
        $contact = $this->contacts->find($id);
        if (!$contact) {
            return $this->redirectToRoute('app_user_contacts');
        }

        $redirect = $this->resolveRedirect($request, $contact);

        $form = $this->createForm(ContactType::class, $contact);
        $form->get('redirect')->setData($redirect);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectTo($redirect, $contact->getProspect()?->getId());
        }

        return $this->renderLayout('contacts/edit.html.twig', 'Modification = '.$contact->getNomComplet(), [
            'routecancel' => 'app_user_contacts',
            'routedelete' => 'app_user_contacts_delete',
            'mode' => 'update',
            'form' => $form,
            'contact' => $contact,
            'redirect' => $redirect,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_contacts_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $contact = $this->contacts->find($id);
        if (!$contact) {
            return $this->redirectToRoute('app_user_contacts');
        }

        return $this->deleteEntity($request, $contact, $id, 'delete-contact', 'contacts', $this->em, [
            'list' => 'app_user_contacts',
            'update' => 'app_user_contacts_update',
            'conflictMessage' => 'Suppression impossible : ce contact est encore référencé par une action.',
        ]);
    }

    /**
     * Détermine l'URL de retour :
     *  - priorité à ?redirect= si présent (URL encodée ou chemin relatif interne)
     *  - sinon, liste des prospects.
     */
    private function resolveRedirect(Request $request, ?Contact $contact): string
    {
        $requested = (string) $request->query->get('redirect', '');
        if ('' !== $requested) {
            return $requested;
        }

        return $this->generateUrl('app_user_prospects');
    }

    /**
     * Redirige vers l'URL stockée. Si l'URL est externe ou invalide, fallback
     * sur la fiche prospect ou la liste.
     */
    private function redirectTo(string $url, ?int $prospectId): RedirectResponse
    {
        // Refuse les URL externes non sécurisées : on n'accepte que les chemins
        // internes (commencent par "/") ou les URL absolues du même hôte.
        if (!str_starts_with($url, '/') && !str_starts_with($url, 'http')) {
            $url = $this->generateUrl('app_user_contacts');
        }

        return new RedirectResponse($url);
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

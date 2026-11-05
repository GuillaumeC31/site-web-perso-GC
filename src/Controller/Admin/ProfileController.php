<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\ProfileType;
use App\Repository\ProfileRepository;
use App\Service\Admin\ProfilePhotoStorage;
use App\Service\Content\ProfilePhotoProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modification du profil (une seule fiche) : textes publics, coordonnées privées, photo.
 *
 * Liens :
 * - Rend : templates/admin/profile/edit.html.twig
 * - Utilise : ProfileRepository::getOrCreate(), ProfileType, ProfilePhotoStorage, ProfilePhotoProvider (aperçu)
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/profil', name: 'profile_')]
final class ProfileController extends AbstractController
{
    /**
     * Affiche et enregistre le profil.
     *
     * Liens :
     * - Route : admin_profile_edit (/{ADMIN_PATH}/profil)
     * - Rend : admin/profile/edit.html.twig (code 422 si le formulaire est invalide)
     *
     * @param Request                $request           requête (formulaire soumis)
     * @param ProfileRepository      $profileRepository dépôt du profil
     * @param EntityManagerInterface $entityManager     gestionnaire Doctrine
     * @param ProfilePhotoStorage    $photoStorage      conversion et enregistrement de la photo (WebP)
     * @param ProfilePhotoProvider   $photoProvider     photo affichée (admin ou par défaut), pour l'aperçu
     *
     * @return Response formulaire, ou redirection après enregistrement
     */
    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ProfileRepository $profileRepository,
        EntityManagerInterface $entityManager,
        ProfilePhotoStorage $photoStorage,
        ProfilePhotoProvider $photoProvider,
    ): Response {
        $profile = $profileRepository->getOrCreate();
        $form = $this->createForm(ProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $photo = $form->get('photo')->getData();
            $previousPhoto = $profile->getPhotoFilename();

            if ($photo instanceof UploadedFile) {
                $profile->setPhotoFilename($photoStorage->store($photo));
            } elseif (true === $form->get('removePhoto')->getData()) {
                $profile->setPhotoFilename(null);
            }

            $entityManager->persist($profile);
            $entityManager->flush();

            // L'ancienne photo n'est effacée qu'une fois la nouvelle valeur enregistrée.
            if ($previousPhoto !== $profile->getPhotoFilename()) {
                $photoStorage->remove($previousPhoto);
            }

            $this->addFlash('success', 'admin.crud.updated');

            return $this->redirectToRoute('admin_profile_edit', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/profile/edit.html.twig', [
            'form' => $form,
            'profile' => $profile,
            'photo' => $photoProvider->getPhoto($profile->getPhotoFilename()),
        ]);
    }
}

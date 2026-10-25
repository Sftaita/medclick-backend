<?php

namespace App\Controller\AdminControllers;

use App\Repository\MarketingImageRepository;
use App\Service\MarketingImageProcessor;
use App\Service\MarketingImageStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import des visuels de campagne (ROLE_ADMIN : access_control ^/api/admin). Le visuel est
 * contrôlé et optimisé dès l'import (aperçu fidèle), puis associé à la campagne à son
 * enregistrement : son URL est envoyée dans un champ *_format de /api/admin/marketing.
 */
#[Route('/api/admin/marketing-images')]
class MarketingImageController extends AbstractController
{
    public function __construct(private readonly MarketingImageStore $store)
    {
    }

    /** Multipart, champ « file » : PNG, JPEG ou WebP de 5 Mo au plus. */
    #[Route('', name: 'admin_marketing_image_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        // Refusé par PHP avant l'application (upload_max_filesize : 6 Mo, voir backend.Dockerfile).
        if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->error('Le visuel dépasse 5 Mo.');
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error('Aucun fichier reçu (5 Mo au maximum).');
        }
        if ($file->getSize() > MarketingImageProcessor::MAX_UPLOAD_BYTES) {
            return $this->error('Le visuel dépasse 5 Mo.');
        }
        try {
            $image = $this->store->store((string) file_get_contents($file->getPathname()));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return new JsonResponse([
            'url' => $image->getPath(),
            'width' => $image->getWidth(),
            'height' => $image->getHeight(),
            'size' => $image->getSize(),
            'originalSize' => $image->getOriginalSize(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Import abandonné (visuel remplacé ou retiré avant l'enregistrement, formulaire annulé) :
     * supprimé s'il n'est utilisé par aucune campagne, conservé sinon.
     */
    #[Route('/{file}', name: 'admin_marketing_image_discard', methods: ['DELETE'], requirements: ['file' => '[a-f0-9]{64}\.webp'])]
    public function discard(string $file, MarketingImageRepository $images, EntityManagerInterface $em): JsonResponse
    {
        $image = $images->findByFileName($file);
        if ($image === null) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }
        if ($this->store->isUsed($image)) {
            return $this->error('Ce visuel est utilisé par une campagne : il est conservé.', Response::HTTP_CONFLICT);
        }
        $em->remove($image);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}

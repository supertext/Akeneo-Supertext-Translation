<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Controller;

use Psr\Log\LoggerInterface;
use Supertext\AkeneoTranslationBundle\Translation\TranslationFileError;
use Supertext\AkeneoTranslationBundle\Translation\TranslationFileGlue;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Internal API behind "Export for translation" and "Import translation" in the "…" menu of the
 * product and product model edit forms (XLIFF 1.2 or HTML, see TranslationFileGlue). The same
 * ACLs as translating; uploads are parsed in memory and never stored.
 */
final class TranslationFileController
{
    public function __construct(
        private readonly TranslationFileGlue $glue,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** POST {"from": "en_US", "to": ["de_DE"], "format": "xliff"|"html"} → the file as a download. */
    public function exportAction(Request $request, string $type, string $id): Response
    {
        $input   = json_decode($request->getContent(), true);
        $input   = \is_array($input) ? $input : [];
        $targets = \is_array($input['to'] ?? null) ? array_values(array_map('strval', $input['to'])) : [];

        try {
            $file = $this->glue->export($type, $id, (string) ($input['from'] ?? ''), $targets, (string) ($input['format'] ?? TranslationFileGlue::FORMAT_XLIFF));
        } catch (TranslationFileError $e) {
            return new JsonResponse($e->toArray(), str_starts_with($e->key, 'forbidden_') ? Response::HTTP_FORBIDDEN : ($e->key === 'file_not_found' ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_REQUEST));
        }

        $response = new Response($file['content'], Response::HTTP_OK, ['Content-Type' => $file['type'], 'X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name']));

        return $response;
    }

    /** POST multipart: file, include_changed (0/1), overwrite (0/1) → {"jobs": [...]} (see TranslationFileGlue::import()). */
    public function importAction(Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return new JsonResponse((new TranslationFileError('file_invalid'))->toArray(), Response::HTTP_BAD_REQUEST);
        }

        // Refuse large files before reading them (also when PHP's own upload limit cut them off).
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) || ($file->isValid() && $file->getSize() > TranslationFileGlue::MAX_UPLOAD_BYTES)) {
            return new JsonResponse((new TranslationFileError('file_too_large'))->toArray(), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (!$file->isValid()) {
            return new JsonResponse((new TranslationFileError('file_invalid'))->toArray(), Response::HTTP_BAD_REQUEST);
        }

        try {
            $jobs = $this->glue->importFile($file->getPathname(), $request->request->getBoolean('include_changed'), $request->request->getBoolean('overwrite'));
        } catch (TranslationFileError $e) {
            return new JsonResponse($e->toArray(), $e->key === 'file_too_large' ? Response::HTTP_REQUEST_ENTITY_TOO_LARGE : Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Supertext translation file import failed', ['exception' => $e]);

            return new JsonResponse(['message' => 'The translation could not be saved. See the application log for details.', 'key' => 'save_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['jobs' => $jobs]);
    }
}

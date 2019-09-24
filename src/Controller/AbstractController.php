<?php declare(strict_types=1);

namespace Reply\WebAuthn\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

abstract class AbstractController extends StorefrontController
{
    protected function getSession(): SessionInterface
    {
        return $this->container->get('session');
    }

    protected function denyAccess(string $message): JsonResponse
    {
        return new JsonResponse($message, 400);
    }
}
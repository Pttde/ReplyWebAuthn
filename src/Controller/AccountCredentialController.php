<?php declare(strict_types=1);

namespace Reply\WebAuthn\Controller;

use Base64Url\Base64Url;
use GuzzleHttp\Psr7\ServerRequest;
use Reply\WebAuthn\Bridge\CustomerCredentialRepository;
use Reply\WebAuthn\Bridge\NamedCredential;
use Reply\WebAuthn\Bridge\PublicKeyCredentialCreationOptionsFactory;
use Reply\WebAuthn\Page\Account\Credential\AccountCredentialPageLoader;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialSource;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AccountCredentialController extends AbstractController
{
    private const CREATION_OPTIONS_SESSION_KEY = 'WebAuthnCredentialCreationOptions';

    /**
     * @var AccountCredentialPageLoader
     */
    private $pageLoader;

    /**
     * @var PublicKeyCredentialCreationOptionsFactory
     */
    private $creationOptionsFactory;

    /**
     * @var CustomerCredentialRepository
     */
    private $credentialRepository;

    /**
     * @var PublicKeyCredentialLoader
     */
    private $credentialLoader;

    /**
     * @var AuthenticatorAttestationResponseValidator
     */
    private $authenticatorAttestationResponseValidator;

    public function __construct(
        AccountCredentialPageLoader $pageLoader,
        PublicKeyCredentialCreationOptionsFactory $creationOptionsFactory,
        CustomerCredentialRepository $credentialRepository,
        PublicKeyCredentialLoader $credentialLoader,
        AuthenticatorAttestationResponseValidator $authenticatorAttestationResponseValidator
    ) {
        $this->pageLoader = $pageLoader;
        $this->creationOptionsFactory = $creationOptionsFactory;
        $this->credentialRepository = $credentialRepository;
        $this->credentialLoader = $credentialLoader;
        $this->authenticatorAttestationResponseValidator = $authenticatorAttestationResponseValidator;
    }

    /**
     * @Route("/account/credential", name="frontend.account.credential.page", options={"seo"="false"}, methods={"GET"})
     *
     * @throws CustomerNotLoggedInException
     */
    public function overviewPage(Request $request, SalesChannelContext $context): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $page = $this->pageLoader->load($request, $context);

        return $this->renderStorefront('page/account/credential/index.html.twig', ['page' => $page]);
    }

    /**
     * @Route("/account/webauthn/credential/creation-options", name="frontend.account.webauthn.credential.creation-options", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function creationOptions(SalesChannelContext $context, Request $request): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $options = $this->creationOptionsFactory->create(
            $request->getHost(),
            $context->getCustomer()->getEmail(),
            $context->getCustomer()->getId()
        );

        $this->getSession()->set(self::CREATION_OPTIONS_SESSION_KEY, json_encode($options));

        return new JsonResponse($options);
    }

    /**
     * @Route("/account/webauthn/credential/save", name="frontend.account.webauthn.credential.save", options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function save(Request $request): JsonResponse
    {
        $this->denyAccessUnlessLoggedIn();
        $requestParams = $request->request->all();

        $credential = $this->credentialLoader->loadArray($requestParams);
        $response = $credential->getResponse();

        if (!$response instanceof AuthenticatorAttestationResponse) {
            return $this->denyAccess('Not an authenticator attestation response');
        }

        $psrRequest = ServerRequest::fromGlobals();
        $creationOptionsJson = $this->getSession()->get(self::CREATION_OPTIONS_SESSION_KEY);
        if (!is_string($creationOptionsJson)) {
            return $this->denyAccess('Cannot find valid credential creation options');
        }

        /** @var PublicKeyCredentialCreationOptions $creationOptions */
        $creationOptions = PublicKeyCredentialCreationOptions::createFromString($creationOptionsJson);
        $this->authenticatorAttestationResponseValidator->check($response, $creationOptions, $psrRequest);

        $credentialSource = PublicKeyCredentialSource::createFromPublicKeyCredential(
            $credential,
            $creationOptions->getUser()->getId()
        );

        $namedCredential = new NamedCredential($credentialSource, $requestParams['name']);

        $this->credentialRepository->saveCredentialSource($namedCredential);

        $this->getSession()->remove(self::CREATION_OPTIONS_SESSION_KEY);

        return new JsonResponse();
    }

    /**
     * @Route("/account/webauthn/credential/delete/{credentialId}", name="frontend.account.webauthn.credential.delete.one", options={"seo"="false"}, methods={"POST"})
     */
    public function deleteOne(string $credentialId, SalesChannelContext $salesChannelContext): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $credentialId = Base64Url::decode($credentialId);
        $credential = $this->credentialRepository->findOneByCredentialId($credentialId);
        if ($credential === null || $credential->getUserHandle() !== $salesChannelContext->getCustomer()->getId()) {
            return $this->redirectToRoute('frontend.account.credential.page');
        }

        $this->credentialRepository->deleteById($credentialId);

        return $this->redirectToRoute('frontend.account.credential.page');
    }

    /**
     * @Route("/account/webauthn/credential/delete", name="frontend.account.webauthn.credential.delete.all", options={"seo"="false"}, methods={"POST"})
     */
    public function deleteAll(SalesChannelContext $salesChannelContext): Response
    {
        $this->denyAccessUnlessLoggedIn();

        $this->credentialRepository->deleteByCustomerId($salesChannelContext->getCustomer()->getId());

        return $this->redirectToRoute('frontend.account.credential.page');
    }
}
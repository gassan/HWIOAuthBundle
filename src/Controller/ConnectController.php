<?php

/*
 * This file is part of the HWIOAuthBundle package.
 *
 * (c) Hardware Info <opensource@hardware.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HWI\Bundle\OAuthBundle\Controller;

use HWI\Bundle\OAuthBundle\Connect\AccountConnectorInterface;
use HWI\Bundle\OAuthBundle\Event\FilterUserResponseEvent;
use HWI\Bundle\OAuthBundle\Event\FormEvent;
use HWI\Bundle\OAuthBundle\Event\GetResponseUserEvent;
use HWI\Bundle\OAuthBundle\Form\RegistrationFormHandlerInterface;
use HWI\Bundle\OAuthBundle\HWIOAuthEvents;
use HWI\Bundle\OAuthBundle\OAuth\ResourceOwnerInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\AbstractOAuthToken;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use HWI\Bundle\OAuthBundle\Security\Http\ResourceOwnerMapLocator;
use HWI\Bundle\OAuthBundle\Security\OAuthUtils;
use Symfony\Component\EventDispatcher\Event as DeprecatedEvent;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * @author Alexander <iam.asm89@gmail.com>
 *
 * @internal
 */
final class ConnectController
{
    private OAuthUtils $oauthUtils;
    private ResourceOwnerMapLocator $resourceOwnerMapLocator;
    private RequestStack $requestStack;
    private EventDispatcherInterface $dispatcher;
    private TokenStorageInterface $tokenStorage;
    private UserCheckerInterface $userChecker;
    private AuthorizationCheckerInterface $authorizationChecker;
    private FormFactoryInterface $formFactory;
    private Environment $twig;
    private RouterInterface $router;
    private ?AccountConnectorInterface $accountConnector;
    private ?RegistrationFormHandlerInterface $formHandler;
    private string $grantRule;
    private bool $failedUseReferer;
    private string $failedAuthPath;
    private bool $enableConnectConfirmation;
    private string $registrationForm;

    public function __construct(
        OAuthUtils $oauthUtils,
        ResourceOwnerMapLocator $resourceOwnerMapLocator,
        RequestStack $requestStack,
        EventDispatcherInterface $dispatcher,
        TokenStorageInterface $tokenStorage,
        UserCheckerInterface $userChecker,
        AuthorizationCheckerInterface $authorizationChecker,
        FormFactoryInterface $formFactory,
        Environment $twig,
        RouterInterface $router,
        string $grantRule,
        bool $failedUseReferer,
        string $failedAuthPath,
        bool $enableConnectConfirmation,
        string $registrationForm,
        ?AccountConnectorInterface $accountConnector,
        ?RegistrationFormHandlerInterface $formHandler
    ) {
        $this->oauthUtils = $oauthUtils;
        $this->resourceOwnerMapLocator = $resourceOwnerMapLocator;
        $this->requestStack = $requestStack;
        $this->grantRule = $grantRule;
        $this->failedUseReferer = $failedUseReferer;
        $this->failedAuthPath = $failedAuthPath;
        $this->enableConnectConfirmation = $enableConnectConfirmation;
        $this->registrationForm = $registrationForm;
        $this->dispatcher = $dispatcher;
        $this->accountConnector = $accountConnector;
        $this->tokenStorage = $tokenStorage;
        $this->userChecker = $userChecker;
        $this->formHandler = $formHandler;
        $this->authorizationChecker = $authorizationChecker;
        $this->formFactory = $formFactory;
        $this->twig = $twig;
        $this->router = $router;
    }

    /**
     * Shows a registration form if there is no user logged in and connecting
     * is enabled.
     *
     * @param string $key key used for retrieving the right information for the registration form
     *
     * @throws NotFoundHttpException if `connect` functionality was not enabled
     * @throws AccessDeniedException if any user is authenticated
     * @throws \RuntimeException
     */
    public function registrationAction(Request $request, string $key): Response
    {
        if (!$this->accountConnector || !$this->formHandler) {
            throw new NotFoundHttpException();
        }

        $hasUser = $this->authorizationChecker->isGranted($this->grantRule);
        if ($hasUser) {
            throw new AccessDeniedException('Cannot connect already registered account.');
        }

        $error = null;
        $session = $request->hasSession() ? $request->getSession() : $this->getSession();
        if ($session) {
            if (!$session->isStarted()) {
                $session->start();
            }
            $error = $session->get('_hwi_oauth.registration_error.'.$key);
            $session->remove('_hwi_oauth.registration_error.'.$key);
        }

        if (!$error instanceof AccountNotLinkedException) {
            throw new \RuntimeException('Cannot register an account.', 0, $error instanceof \Exception ? $error : null);
        }

        $userInformation = $this
            ->getResourceOwnerByName($error->getResourceOwnerName())
            ->getUserInformation($error->getRawToken())
        ;

        $form = $this->formFactory->create($this->registrationForm);

        if ($this->formHandler->process($request, $form, $userInformation)) {
            $event = new FormEvent($form, $request);
            $this->dispatch($event, HWIOAuthEvents::REGISTRATION_SUCCESS);

            $this->accountConnector->connect($form->getData(), $userInformation);

            // Authenticate the user
            $this->authenticateUser($request, $form->getData(), $error->getResourceOwnerName(), $error->getAccessToken());

            if (null === $response = $event->getResponse()) {
                if ($targetPath = $this->getTargetPath($session)) {
                    $response = new RedirectResponse($targetPath);
                } else {
                    $response = new Response($this->twig->render('@HWIOAuth/Connect/registration_success.html.twig', [
                        'userInformation' => $userInformation,
                    ]));
                }
            }

            $event = new FilterUserResponseEvent($form->getData(), $request, $response);
            $this->dispatch($event, HWIOAuthEvents::REGISTRATION_COMPLETED);

            return $event->getResponse();
        }

        if ($session) {
            // reset the error in the session
            $session->set('_hwi_oauth.registration_error.'.$key, $error);
        }

        $event = new GetResponseUserEvent($form->getData(), $request);
        $this->dispatch($event, HWIOAuthEvents::REGISTRATION_INITIALIZE);

        if ($response = $event->getResponse()) {
            return $response;
        }

        return new Response($this->twig->render('@HWIOAuth/Connect/registration.html.twig', [
            'key' => $key,
            'form' => $form->createView(),
            'userInformation' => $userInformation,
        ]));
    }

    /**
     * Connects a user to a given account if the user is logged in and connect is enabled.
     *
     * @param string $service name of the resource owner to connect to
     *
     * @throws \Exception
     * @throws NotFoundHttpException if `connect` functionality was not enabled
     * @throws AccessDeniedException if no user is authenticated
     */
    public function connectServiceAction(Request $request, string $service): Response
    {
        if (!$this->accountConnector || !$this->formHandler) {
            throw new NotFoundHttpException();
        }

        $hasUser = $this->authorizationChecker->isGranted($this->grantRule);
        if (!$hasUser) {
            throw new AccessDeniedException('Cannot connect an account.');
        }

        // Get the data from the resource owner
        $resourceOwner = $this->getResourceOwnerByName($service);

        $session = $request->hasSession() ? $request->getSession() : $this->getSession();
        if ($session && !$session->isStarted()) {
            $session->start();
        }

        $key = $request->query->get('key', (string) time());

        $accessToken = null;
        if ($resourceOwner->handles($request)) {
            $accessToken = $resourceOwner->getAccessToken(
                $request,
                $this->oauthUtils->getServiceAuthUrl($request, $resourceOwner)
            );

            if ($session) {
                // save in session
                $session->set('_hwi_oauth.connect_confirmation.'.$key, $accessToken);
            }
        } elseif ($session) {
            $accessToken = $session->get('_hwi_oauth.connect_confirmation.'.$key);
        }

        // Redirect to the login path if the token is empty (Eg. User cancelled auth)
        if (null === $accessToken) {
            if ($this->failedUseReferer && $targetPath = $this->getTargetPath($session)) {
                return new RedirectResponse($targetPath);
            }

            return new RedirectResponse($this->router->generate($this->failedAuthPath));
        }

        // Show confirmation page?
        if (!$this->enableConnectConfirmation) {
            return $this->getConfirmationResponse($request, $accessToken, $service);
        }

        $form = $this->formFactory->create();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->getConfirmationResponse($request, $accessToken, $service);
        }

        /** @var TokenInterface $token */
        $token = $this->tokenStorage->getToken();

        $event = new GetResponseUserEvent($token->getUser(), $request);

        $this->dispatch($event, HWIOAuthEvents::CONNECT_INITIALIZE);

        if ($response = $event->getResponse()) {
            return $response;
        }

        return new Response($this->twig->render('@HWIOAuth/Connect/connect_confirm.html.twig', [
            'key' => $key,
            'service' => $service,
            'form' => $form->createView(),
            'userInformation' => $resourceOwner->getUserInformation($accessToken),
        ]));
    }

    /**
     * Get a resource owner by name.
     *
     * @throws NotFoundHttpException if there is no resource owner with the given name
     */
    private function getResourceOwnerByName(string $name): ResourceOwnerInterface
    {
        foreach ($this->resourceOwnerMapLocator->getResourceOwnerMaps() as $ownerMap) {
            if ($resourceOwner = $ownerMap->getResourceOwnerByName($name)) {
                return $resourceOwner;
            }
        }

        throw new NotFoundHttpException(sprintf("No resource owner with name '%s'.", $name));
    }

    /**
     * Authenticate a user with Symfony Security.
     *
     * @param string|array $accessToken
     */
    private function authenticateUser(Request $request, UserInterface $user, string $resourceOwnerName, $accessToken, bool $fakeLogin = true): void
    {
        try {
            $this->userChecker->checkPreAuth($user);
            $this->userChecker->checkPostAuth($user);
        } catch (AccountStatusException $e) {
            // Don't authenticate locked, disabled or expired users
            return;
        }

        $resourceOwner = $this->getResourceOwnerByName($resourceOwnerName);
        $tokenClass = $resourceOwner->getTokenClass();
        $token = new $tokenClass($accessToken, $user->getRoles());
        $token->setResourceOwnerName($resourceOwnerName);
        $token->setUser($user);

        // required for compatibility with Symfony 5.4
        if (method_exists($token, 'setAuthenticated')) {
            $token->setAuthenticated(true, false);
        }

        $this->tokenStorage->setToken($token);

        if ($fakeLogin) {
            // Since we're "faking" normal login, we need to throw our INTERACTIVE_LOGIN event manually
            $this->dispatch(
                new InteractiveLoginEvent($request, $token),
                SecurityEvents::INTERACTIVE_LOGIN
            );
        }
    }

    private function getTargetPath(?SessionInterface $session): ?string
    {
        if (!$session) {
            return null;
        }

        foreach ($this->resourceOwnerMapLocator->getFirewallNames() as $firewallName) {
            $sessionKey = '_security.'.$firewallName.'.target_path';
            if ($session->has($sessionKey)) {
                return $session->get($sessionKey);
            }
        }

        return null;
    }

    /**
     * @param string $service name of the resource owner to connect to
     *
     * @throws NotFoundHttpException if there is no resource owner with the given name
     */
    private function getConfirmationResponse(Request $request, array $accessToken, string $service): Response
    {
        /** @var AbstractOAuthToken $currentToken */
        $currentToken = $this->tokenStorage->getToken();
        /** @var UserInterface $currentUser */
        $currentUser = $currentToken->getUser();

        $resourceOwner = $this->getResourceOwnerByName($service);
        $userInformation = $resourceOwner->getUserInformation($accessToken);

        $event = new GetResponseUserEvent($currentUser, $request);
        $this->dispatch($event, HWIOAuthEvents::CONNECT_CONFIRMED);

        $this->accountConnector->connect($currentUser, $userInformation);

        if ($currentToken instanceof AbstractOAuthToken) {
            // Update user token with new details
            $newToken =
                (isset($accessToken['access_token']) || isset($accessToken['oauth_token'])) ?
                    $accessToken : $currentToken->getRawToken();

            $this->authenticateUser($request, $currentUser, $service, $newToken, false);
        }

        if (null === $response = $event->getResponse()) {
            if ($targetPath = $this->getTargetPath($request->getSession())) {
                $response = new RedirectResponse($targetPath);
            } else {
                $response = new Response($this->twig->render('@HWIOAuth/Connect/connect_success.html.twig', [
                    'userInformation' => $userInformation,
                    'service' => $service,
                ]));
            }
        }

        $event = new FilterUserResponseEvent($currentUser, $request, $response);
        $this->dispatch($event, HWIOAuthEvents::CONNECT_COMPLETED);

        return $event->getResponse();
    }

    /**
     * @param Event|DeprecatedEvent $event
     */
    private function dispatch($event, string $eventName = null): void
    {
        $this->dispatcher->dispatch($event, $eventName);
    }

    private function getSession(): ?SessionInterface
    {
        if (method_exists($this->requestStack, 'getSession')) {
            return $this->requestStack->getSession();
        }

        if ((null !== $request = $this->requestStack->getCurrentRequest()) && $request->hasSession()) {
            return $request->getSession();
        }

        return null;
    }
}

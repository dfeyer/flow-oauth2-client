<?php

namespace Flownative\OAuth2\Client;

use Doctrine\Common\Persistence\ObjectManager as DoctrineObjectManager;
use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Tool\RequestFactory;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\Uri;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Session\Exception\SessionNotStartedException;
use Neos\Flow\Session\SessionInterface;
use Psr\Http\Message\RequestInterface;

abstract class OAuthClient
{
    /**
     * @Flow\Inject
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\InjectConfiguration(path="http.baseUri", package="Neos.Flow")
     * @var string
     */
    protected $flowBaseUriSetting;

    /**
     * @var Client
     */
    protected $httpClient;

    /**
     * @Flow\Inject
     * @var SessionInterface
     */
    protected $session;

    /**
     * @var DoctrineEntityManager
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $serviceName;

    /**
     * @param string $serviceName
     */
    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    /**
     * @param DoctrineObjectManager $entityManager
     * @return void
     */
    public function injectEntityManager(DoctrineObjectManager $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Returns the service name
     *
     * @return string For example, "FlownativeBeach", "Paypal", "Stripe", "Twitter"
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Returns the OAuth server's base URI
     *
     * @return string For example https://myservice.flownative.com
     */
    abstract public function getBaseUri(): string;

    /**
     * Returns the current client id (for sending authenticated requests)
     *
     * @return string The client id which is known by the OAuth2 server
     */
    abstract public function getClientId(): string;

    /**
     * Returns the OAuth service endpoint for the access token.
     * Override this method if needed.
     *
     * @return string
     */
    public function getAccessTokenUri(): string
    {
        return $this->getBaseUri() . '/oauth/token';
    }

    /**
     * Returns the OAuth service endpoint for authorizing a token.
     * Override this method if needed.
     *
     * @return string
     */
    public function getAuthorizeTokenUri(): string
    {
        return $this->getBaseUri() . '/oauth/token/authorize';
    }

    /**
     * Returns the OAuth service endpoint for accessing the resource owner details.
     * Override this method if needed.
     *
     * @return string
     */
    public function getResourceOwnerUri(): string
    {
        return $this->getBaseUri() . '/oauth/token/resource';
    }

    /**
     * Returns a factory for requests used by this OAuth client.
     *
     * You may override this method an provide a custom request factory, for example for adding
     * additional headers (e.g. User-Agent) to every request.
     *
     * @return RequestFactory
     */
    public function getRequestFactory(): RequestFactory
    {
        return new RequestFactory();
    }

    /**
     * Add credentials for a Client Credentials Grant
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $scope
     * @return void
     * @throws IdentityProviderException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function addClientCredentials(string $clientId, string $clientSecret, string $scope = ''): void
    {
        $oAuthProvider = $this->createOAuthProvider($clientId, $clientSecret);

        try {
            $this->logger->log(sprintf($this->getServiceName() . 'Setting client credentials for client "%s" using a %s bytes long secret.', $clientId, strlen($clientSecret)), LOG_INFO);

            $oldOAuthToken = $this->getOAuthToken();
            if ($oldOAuthToken !== null) {
                $this->entityManager->remove($oldOAuthToken);
                $this->entityManager->flush();

                $this->logger->log(sprintf($this->getServiceName() . 'Removed old OAuth token for client "%s".', $clientId), LOG_INFO);
            }

            $accessToken = $oAuthProvider->getAccessToken('client_credentials');
            $oAuthToken = $this->createNewOAuthToken($clientId, $clientSecret, 'client_credentials', $accessToken, $scope);

            $this->logger->log(sprintf($this->getServiceName() . 'Persisted new OAuth token for client "%s" with expiry time %s.', $clientId, $accessToken->getExpires()), LOG_INFO);

            $this->entityManager->persist($oAuthToken);
            $this->entityManager->flush();
        } catch (IdentityProviderException $e) {
            throw $e;
        }
    }

    /**
     * Start OAuth authorization
     *
     * @param string $clientId The client id, as provided by the OAuth server
     * @param string $clientSecret The client secret, provided by the OAuth server
     * @param string $returnToUri URI to return to when authorization is finished
     * @return Uri The URL the browser should redirect to, asking the user to authorize
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws SessionNotStartedException
     */
    public function startAuthorization(string $clientId, string $clientSecret, string $returnToUri): Uri
    {
        $oAuthProvider = $this->createOAuthProvider($clientId, $clientSecret);
        $authorizationUri = new Uri($oAuthProvider->getAuthorizationUrl());

        $this->logger->log(sprintf($this->getServiceName() . ': Starting authorization for client "%s" using a %s bytes long secret, returning to "%s".', $clientId, strlen($clientSecret), $returnToUri), LOG_INFO);

        $oldOAuthToken = $this->getOAuthToken();
        if ($oldOAuthToken !== null) {
            $this->entityManager->remove($oldOAuthToken);
            $this->entityManager->flush();

            $this->logger->log(sprintf($this->getServiceName() . ': Removed old OAuth token for client "%s".', $oldOAuthToken->clientId), LOG_INFO);
        }

        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        $this->session->putData($this->getServiceName() . '.oAuthClientId', $clientId);
        $this->session->putData($this->getServiceName() . '.oAuthClientSecret', $clientSecret);
        $this->session->putData($this->getServiceName() . '.oAuthState', $oAuthProvider->getState());
        $this->session->putData($this->getServiceName() . '.returnToUri', $returnToUri);

        return $authorizationUri;
    }

    /**
     * Finish an OAuth authorization
     *
     * @param string $code The authorization code given by the OAuth server
     * @param string $state The authorization state given by the OAuth server
     * @param string $scope The scope for the granted authorization (syntax varies depending on the service)
     * @return Uri The URI to return to
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws SessionNotStartedException
     * @throws TransactionRequiredException
     */
    public function finishAuthorization(string $code, string $state, string $scope): Uri
    {
        $stateFromSession = $this->session->isStarted() ? $this->session->getData($this->getServiceName() . '.oAuthState') : null;
        if (empty($state) || $stateFromSession !== $state) {
            throw new OAuthClientException('Invalid oAuth2 state.', 1505313625652);
        }

        $clientId = (string)$this->session->getData($this->getServiceName() . '.oAuthClientId');
        $clientSecret = (string)$this->session->getData($this->getServiceName() . '.oAuthClientSecret');
        $oAuthProvider = $this->createOAuthProvider($clientId, $clientSecret);

        try {
            $this->logger->log(sprintf($this->getServiceName() . ': Finishing authorization for client "%s" using a %s bytes long secret.', $clientId, strlen($clientSecret)), LOG_INFO);

            $oldOAuthToken = $this->entityManager->find(OAuthToken::class, ['clientId' => $clientId, 'serviceName' => $this->getServiceName()]);
            if ($oldOAuthToken !== null) {
                $this->entityManager->remove($oldOAuthToken);
                $this->entityManager->flush();

                $this->logger->log(sprintf($this->getServiceName() . ': Removed old OAuth token for client "%s".', $clientId), LOG_INFO);
            }

            $accessToken = $oAuthProvider->getAccessToken('authorization_code', ['code' => $code]);
            $oAuthToken = $this->createNewOAuthToken($clientId, $clientSecret, 'authorization_code', $accessToken, $scope);

            $this->logger->log(sprintf($this->getServiceName() . ': Persisted new OAuth token for client "%s" with expiry time %s.', $clientId, $accessToken->getExpires()), LOG_INFO);

            $this->entityManager->persist($oAuthToken);
            $this->entityManager->flush();
        } catch (IdentityProviderException $exception) {
            throw new OAuthClientException($exception->getMessage(), 1511187001671, $exception);
        }

        return new Uri((string)$this->session->getData($this->getServiceName() . '.returnToUri'));
    }

    /**
     * Refresh an OAuth authorization
     *
     * @param string $clientId
     * @param string $returnToUri
     * @return string
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function refreshAuthorization(string $clientId, string $returnToUri): string
    {
        $oAuthToken = $this->entityManager->find(OAuthToken::class, ['clientId' => $clientId, 'serviceName' => $this->getServiceName()]);
        if (!$oAuthToken instanceof OAuthToken) {
            throw new OAuthClientException($this->getServiceName() . ': Could not refresh OAuth2 token because it was not found in our database.', 1505317044316);
        }
        $oAuthProvider = $this->createOAuthProvider($clientId, $oAuthToken->clientSecret);

        $this->logger->log(sprintf($this->getServiceName() . ': Refreshing authorization for client "%s" using a %s bytes long secret and refresh token "%s".', $clientId, strlen($oAuthToken->clientSecret), $oAuthToken->refreshToken), LOG_INFO);

        try {
            $accessToken = $oAuthProvider->getAccessToken('refresh_token', ['refresh_token' => $oAuthToken->refreshToken]);
            $oAuthToken->accessToken = $accessToken->getToken();
            $oAuthToken->expires = ($accessToken->getExpires() ? \DateTimeImmutable::createFromFormat('U', $accessToken->getExpires()) : null);

            $this->logger->log(sprintf($this->getServiceName() . ': New access token is "%s", refresh token is "%s".', $oAuthToken->accessToken, $oAuthToken->refreshToken), LOG_DEBUG);

            $this->entityManager->persist($oAuthToken);
            $this->entityManager->flush();
        } catch (IdentityProviderException $exception) {
            throw new OAuthClientException($exception->getMessage(), 1511187196454, $exception);
        }

        return $returnToUri;
    }

    /**
     * @return OAuthToken|null
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function getOAuthToken(): ?OAuthToken
    {
        $oAuthToken = $this->entityManager->find(OAuthToken::class, ['clientId' => $this->getClientId(), 'serviceName' => $this->getServiceName()]);
        return ($oAuthToken instanceof OAuthToken) ? $oAuthToken : null;
    }

    /**
     * Returns a prepared request which provides the needed header for OAuth authentication
     *
     * @param string $relativeUri A relative URI of the web server, prepended by the base URI
     * @param string $method The HTTP method, for example "GET" or "POST"
     * @param array $bodyFields Associative array of body fields to send (optional)
     * @return RequestInterface
     * @throws IdentityProviderException
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function getAuthenticatedRequest(string $relativeUri, string $method = 'GET', array $bodyFields = []): RequestInterface
    {
        $oAuthToken = $this->getOAuthToken();
        if (!$oAuthToken instanceof OAuthToken) {
            throw new OAuthClientException('No OAuthToken found.', 1505321014388);
        }

        $oAuthProvider = $this->createOAuthProvider($oAuthToken->clientId, $oAuthToken->clientSecret);

        if ($oAuthToken->expires < new \DateTimeImmutable()) {
            switch ($oAuthToken->grantType) {
                case 'authorization_code':
                    $this->refreshAuthorization($oAuthToken->clientId, '');
                    $oAuthToken = $this->getOAuthToken();
                break;
                case 'client_credentials':
                    try {
                        $newAccessToken = $oAuthProvider->getAccessToken('client_credentials');
                    } catch (IdentityProviderException $exception) {
                        $this->logger->log(sprintf($this->getServiceName() . 'Failed retrieving new OAuth access token for client "%s" (client credentials grant): %s', $oAuthToken->clientId, $exception->getMessage()), LOG_ERR);
                        throw $exception;
                    }

                    $oAuthToken->accessToken = $newAccessToken->getToken();
                    $oAuthToken->expires = ($newAccessToken->getExpires() ? \DateTimeImmutable::createFromFormat('U', $newAccessToken->getExpires()) : null);

                    $this->logger->log(sprintf($this->getServiceName() . 'Persisted new OAuth token for client "%s" with expiry time %s.', $oAuthToken->clientId, $newAccessToken->getExpires()), LOG_INFO);

                    $this->entityManager->persist($oAuthToken);
                    $this->entityManager->flush();
                break;
            }
        }

        $body = ($bodyFields !== [] ? \GuzzleHttp\json_encode($bodyFields) : '');

        return $oAuthProvider->getAuthenticatedRequest(
            $method,
            $this->getBaseUri() . $relativeUri,
            $oAuthToken->accessToken,
            [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => $body
            ]
        );
    }

    /**
     * @param string $relativeUri
     * @param string $method
     * @param array $bodyFields
     * @return Response
     * @throws IdentityProviderException
     * @throws OAuthClientException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws GuzzleException
     */
    public function sendAuthenticatedRequest(string $relativeUri, string $method = 'GET', array $bodyFields = []): Response
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client();
        }
        return $this->httpClient->send($this->getAuthenticatedRequest($relativeUri, $method, $bodyFields));
    }

    /**
     * @return string
     * @throws
     */
    public function renderRedirectUri(): string
    {
        $currentRequestHandler = $this->bootstrap->getActiveRequestHandler();
        if ($currentRequestHandler instanceof HttpRequestHandlerInterface) {
            $httpRequest = $currentRequestHandler->getHttpRequest();
        } else {
            putenv('FLOW_REWRITEURLS=1');
            $httpRequest = Request::createFromEnvironment();
            $httpRequest->setBaseUri(new Uri($this->flowBaseUriSetting));
        }
        $actionRequest = new ActionRequest($httpRequest);

        $this->uriBuilder->reset();
        $this->uriBuilder->setRequest($actionRequest);
        $this->uriBuilder->setCreateAbsoluteUri(true);

        try {
        return $this->uriBuilder->uriFor('finishAuthorization', ['serviceName' => $this->getServiceName()], 'OAuth', 'Flownative.OAuth2.Client');
        } catch (MissingActionNameException $e) {
            return '';
        }
    }

    /**
     * Create a new OAuthToken instance
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $grantType
     * @param AccessTokenInterface $accessToken
     * @param string $scope
     * @return OAuthToken
     */
    protected function createNewOAuthToken(string $clientId, string $clientSecret, string $grantType, AccessTokenInterface $accessToken, string $scope): OAuthToken
    {
        $oAuthToken = new OAuthToken();
        $oAuthToken->clientId = $clientId;
        $oAuthToken->serviceName = $this->getServiceName();
        $oAuthToken->grantType = $grantType;
        $oAuthToken->clientSecret = $clientSecret;
        $oAuthToken->accessToken = $accessToken->getToken();
        $oAuthToken->refreshToken = $accessToken->getRefreshToken();
        $oAuthToken->expires = ($accessToken->getExpires() ? \DateTimeImmutable::createFromFormat('U', $accessToken->getExpires()) : null);
        $oAuthToken->scope = $scope;

        return $oAuthToken;
    }

    /**
     * @param string $clientId
     * @param string $clientSecret
     * @return GenericProvider
     */
    protected function createOAuthProvider(string $clientId, string $clientSecret): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $this->renderRedirectUri(),
            'urlAuthorize' => $this->getAuthorizeTokenUri(),
            'urlAccessToken' => $this->getAccessTokenUri(),
            'urlResourceOwnerDetails' => $this->getResourceOwnerUri()
        ], [
            'requestFactory' => $this->getRequestFactory()
        ]);
    }
}

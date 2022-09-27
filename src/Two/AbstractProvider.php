<?php

namespace Laravel\Socialite\Two;

use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider as ProviderContract;

abstract class AbstractProvider implements ProviderContract
{
    /**
     * The HTTP Client instance.
     *
     * @var \GuzzleHttp\Client|null
     */
    protected \GuzzleHttp\Client|null $httpClient = null;

    /**
     * The custom parameters to be sent with the request.
     *
     * @var array
     */
    protected array $parameters = [];

    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected array $scopes = [];

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected string $scopeSeparator = ',';

    /**
     * The type of the encoding in the query.
     *
     * @var int Can be either PHP_QUERY_RFC3986 or PHP_QUERY_RFC1738.
     */
    protected int $encodingType = PHP_QUERY_RFC1738;

    /**
     * Indicates if the session state should be utilized.
     *
     * @var bool
     */
    protected bool $stateless = false;

    /**
     * Indicates if PKCE should be used.
     *
     * @var bool
     */
    protected bool $usesPKCE = false;

    /**
     * The cached user instance.
     *
     * @var \Laravel\Socialite\Two\User|null
     */
    protected \Laravel\Socialite\Two\User|null $user = null;

    /**
     * Create a new provider instance.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request instance.
     * @param  string  $clientId  The client ID.
     * @param  string  $clientSecret  The client secret.
     * @param  string  $redirectUrl  The redirect URL.
     * @param  array  $guzzle  The custom Guzzle configuration options.
     * @return void
     */
    public function __construct(
        protected Request $request,
        protected string $clientId,
        protected string $clientSecret,
        protected string $redirectUrl,
        protected array $guzzle = [])
    {
    }

    /**
     * Get the authentication URL for the provider.
     *
     * @param  string|null $state
     * @return string
     */
    abstract public function getAuthUrl(string|null $state): string;

    /**
     * Get the token URL for the provider.
     *
     * @return string
     */
    abstract protected function getTokenUrl(): string;

    /**
     * Get the raw user for the given access token.
     *
     * @param  string  $token
     * @return array
     */
    abstract protected function getUserByToken(string $token): array;

    /**
     * Map the raw user array to a Socialite User instance.
     *
     * @param  array  $user
     * @return \Laravel\Socialite\Two\User
     */
    abstract protected function mapUserToObject(array $user): \Laravel\Socialite\Two\User;

    /**
     * Redirect the user of the application to the provider's authentication screen.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirect(): \Illuminate\Http\RedirectResponse
    {
        $state = null;

        if ($this->usesState()) {
            $this->request->session()->put('state', $state = $this->getState());
        }

        if ($this->usesPKCE()) {
            $this->request->session()->put('code_verifier', $this->getCodeVerifier());
        }

        return new RedirectResponse($this->getAuthUrl($state));
    }

    /**
     * Build the authentication URL for the provider from the given base URL.
     *
     * @param  string  $url
     * @param  string|null  $state
     * @return string
     */
    protected function buildAuthUrlFromBase(string $url, string|null $state): string
    {
        return $url.'?'.http_build_query($this->getCodeFields($state), '', '&', $this->encodingType);
    }

    /**
     * Get the GET parameters for the code request.
     *
     * @param  string|null  $state
     * @return array
     */
    protected function getCodeFields(string|null $state = null): array
    {
        $fields = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'response_type' => 'code',
        ];

        if ($this->usesState()) {
            $fields['state'] = $state;
        }

        if ($this->usesPKCE()) {
            $fields['code_challenge'] = $this->getCodeChallenge();
            $fields['code_challenge_method'] = $this->getCodeChallengeMethod();
        }

        return array_merge($fields, $this->parameters);
    }

    /**
     * Format the given scopes.
     *
     * @param  array  $scopes
     * @param  string  $scopeSeparator
     * @return string
     */
    protected function formatScopes(array $scopes, string $scopeSeparator): string
    {
        return implode($scopeSeparator, $scopes);
    }

    /**
     * {@inheritdoc}
     */
    public function user(): \Laravel\Socialite\Two\User
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $response = $this->getAccessTokenResponse($this->getCode());

        $this->user = $this->mapUserToObject($this->getUserByToken(
            $token = Arr::get($response, 'access_token')
        ));

        return $this->user->setToken($token)
                    ->setRefreshToken(Arr::get($response, 'refresh_token'))
                    ->setExpiresIn(Arr::get($response, 'expires_in'));
    }

    /**
     * Get a Social User instance from a known access token.
     *
     * @param  string  $token
     * @return \Laravel\Socialite\Two\User
     */
    public function userFromToken(string $token): \Laravel\Socialite\Two\User
    {
        $user = $this->mapUserToObject($this->getUserByToken($token));

        return $user->setToken($token);
    }

    /**
     * Determine if the current request / session has a mismatching "state".
     *
     * @return bool
     */
    protected function hasInvalidState(): bool
    {
        if ($this->isStateless()) {
            return false;
        }

        $state = $this->request->session()->pull('state');

        return empty($state) || $this->request->input('state') !== $state;
    }

    /**
     * Get the access token response for the given code.
     *
     * @param  string  $code
     * @return array
     */
    public function getAccessTokenResponse(string $code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => $this->getTokenFields($code),
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * Get the POST fields for the token request.
     *
     * @param  string  $code
     * @return array
     */
    protected function getTokenFields(string $code): array
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ];

        if ($this->usesPKCE()) {
            $fields['code_verifier'] = $this->request->session()->pull('code_verifier');
        }

        return $fields;
    }

    /**
     * Get the code from the request.
     *
     * @return string
     */
    protected function getCode(): string
    {
        return $this->request->input('code');
    }

    /**
     * Merge the scopes of the requested access.
     *
     * @param  array|string  $scopes
     * @return $this
     */
    public function scopes(array|string $scopes): self
    {
        $this->scopes = array_unique(array_merge($this->scopes, (array) $scopes));

        return $this;
    }

    /**
     * Set the scopes of the requested access.
     *
     * @param  array|string  $scopes
     * @return $this
     */
    public function setScopes(array|string $scopes): self
    {
        $this->scopes = array_unique((array) $scopes);

        return $this;
    }

    /**
     * Get the current scopes.
     *
     * @return array
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * Set the redirect URL.
     *
     * @param  string  $url
     * @return $this
     */
    public function redirectUrl(string $url): self
    {
        $this->redirectUrl = $url;

        return $this;
    }

    /**
     * Get a instance of the Guzzle HTTP client.
     *
     * @return \GuzzleHttp\Client|\Mockery\MockInterface
     */
    protected function getHttpClient(): \GuzzleHttp\Client|\Mockery\MockInterface
    {
        if (is_null($this->httpClient)) {
            $this->httpClient = new Client($this->guzzle);
        }

        return $this->httpClient;
    }

    /**
     * Set the Guzzle HTTP client instance.
     *
     * @param  \GuzzleHttp\Client  $client
     * @return $this
     */
    public function setHttpClient(Client $client): self
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * Set the request instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return $this
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Determine if the provider is operating with state.
     *
     * @return bool
     */
    protected function usesState(): bool
    {
        return ! $this->stateless;
    }

    /**
     * Determine if the provider is operating as stateless.
     *
     * @return bool
     */
    protected function isStateless(): bool
    {
        return $this->stateless;
    }

    /**
     * Indicates that the provider should operate as stateless.
     *
     * @return $this
     */
    public function stateless(): self
    {
        $this->stateless = true;

        return $this;
    }

    /**
     * Get the string used for session state.
     *
     * @return string
     */
    protected function getState(): string
    {
        return Str::random(40);
    }

    /**
     * Determine if the provider uses PKCE.
     *
     * @return bool
     */
    protected function usesPKCE(): bool
    {
        return $this->usesPKCE;
    }

    /**
     * Enables PKCE for the provider.
     *
     * @return $this
     */
    public function enablePKCE(): self
    {
        $this->usesPKCE = true;

        return $this;
    }

    /**
     * Generates a random string of the right length for the PKCE code verifier.
     *
     * @return string
     */
    protected function getCodeVerifier(): string
    {
        return Str::random(96);
    }

    /**
     * Generates the PKCE code challenge based on the PKCE code verifier in the session.
     *
     * @return string
     */
    protected function getCodeChallenge(): string
    {
        $hashed = hash('sha256', $this->request->session()->get('code_verifier'), true);

        return rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');
    }

    /**
     * Returns the hash method used to calculate the PKCE code challenge.
     *
     * @return string
     */
    protected function getCodeChallengeMethod(): string
    {
        return 'S256';
    }

    /**
     * Set the custom parameters of the request.
     *
     * @param  array  $parameters
     * @return $this
     */
    public function with(array $parameters): self
    {
        $this->parameters = $parameters;

        return $this;
    }
}

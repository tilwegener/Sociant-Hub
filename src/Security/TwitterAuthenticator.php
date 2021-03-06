<?php

namespace App\Security;

use Abraham\TwitterOAuth\TwitterOAuth;
use App\Entity\LoginAttempt;
use App\Entity\User;
use App\Model\TwitterModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

class TwitterAuthenticator extends AbstractGuardAuthenticator
{

    private $entityManager;
    private $params;
    private $twitterModel;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $params, TwitterModel $twitterModel)
    {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->twitterModel = $twitterModel;
    }

    public function supports(Request $request)
    {
        return 'login_callback' === $request->attributes->get('_route') &&
            $request->query->has("oauth_token") &&
            $request->query->has("oauth_verifier");
    }

    public function getCredentials(Request $request)
    {
        return [
            'oauthToken' => $request->query->get("oauth_token"),
            'oauthVerifier' => $request->query->get("oauth_verifier")
        ];
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $loginAttempt = $this->entityManager->getRepository(LoginAttempt::class)->findOneBy([
            "oauthToken" => $credentials["oauthToken"]
        ]);

        if($loginAttempt) {
            $connection = new TwitterOAuth(
                $this->params->get("twitter_api_key"),
                $this->params->get("twitter_api_secret_key"),
                $loginAttempt->getOauthToken(),
                $loginAttempt->getOauthTokenSecret()
            );

            $accessToken = $connection->oauth("oauth/access_token", [
                "oauth_verifier" => $credentials["oauthVerifier"]
            ]);

            $user = $this->entityManager->getRepository(User::class)->findOneBy([
                "uuid" => $accessToken["user_id"]
            ]);

            $this->entityManager->remove($loginAttempt);

            if($user == null) $user = new User();
            $user->setUuid($accessToken["user_id"]);
            $user->setTwitterUserScreenName($accessToken["screen_name"]);
            $user->setTwitterUserOauthToken($accessToken["oauth_token"]);
            $user->setTwitterUserOauthTokenSecret($accessToken["oauth_token_secret"]);

            $this->twitterModel->verifyCredentials($user);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $user;
        } else
            throw new CustomUserMessageAuthenticationException("unknown oauthToken");
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        return true;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        return new RedirectResponse("/");
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        return new RedirectResponse("/panel/home");
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse("/");
    }

    public function supportsRememberMe()
    {
        return false;
    }
}

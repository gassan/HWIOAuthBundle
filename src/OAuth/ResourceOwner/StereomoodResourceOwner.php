<?php

/*
 * This file is part of the HWIOAuthBundle package.
 *
 * (c) Hardware Info <opensource@hardware.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HWI\Bundle\OAuthBundle\OAuth\ResourceOwner;

use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Vincenzo Di Biaggio <aniceweb@gmail.com>
 */
final class StereomoodResourceOwner extends GenericOAuth1ResourceOwner
{
    protected array $paths = [
        'identifier' => 'oauth_token',
        'nickname' => 'oauth_token',
    ];

    /**
     * {@inheritdoc}
     */
    public function getUserInformation(array $accessToken, array $extraParameters = [])
    {
        $response = $this->getUserResponse();
        $response->setData($accessToken);
        $response->setResourceOwner($this);
        $response->setOAuthToken(new OAuthToken($accessToken));

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'authorization_url' => 'http://www.stereomood.com/api/oauth/authenticate',
            'request_token_url' => 'http://www.stereomood.com/api/oauth/request_token',
            'access_token_url' => 'http://www.stereomood.com/api/oauth/access_token',

            // Stereomood don't use `infos_url`
            'infos_url' => null,
        ]);
    }
}

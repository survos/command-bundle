<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Bearer-token authentication for the /mcp endpoint: the configured token (survos_command.agent.token,
 * normally from an env var) signs in as the configured user, loaded by the firewall's user provider —
 * so agent calls run with that user's roles and show up as that user.
 *
 *   firewalls:
 *       mcp:
 *           pattern: ^/mcp
 *           stateless: true
 *           provider: app_user_provider
 *           access_token:
 *               token_handler: Survos\CommandBundle\Security\AgentTokenHandler
 */
final readonly class AgentTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ?string $token,
        private ?string $user,
    ) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // An unset token must never match an empty bearer.
        if (!$this->token || !$this->user || !hash_equals($this->token, $accessToken)) {
            throw new BadCredentialsException('Invalid agent token.');
        }

        return new UserBadge($this->user);
    }
}

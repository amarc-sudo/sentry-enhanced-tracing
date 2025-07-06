<?php

declare(strict_types=1);

namespace AmarcSudo\SentryEnhancedTracing\User;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Interface for enhanced user information in Sentry tracing.
 *
 * This interface extends Symfony's UserInterface to provide additional
 * methods for enriching Sentry user context with detailed information.
 *
 * When implemented by your User entity, the SentryUserContextListener
 * will automatically capture and send enhanced user information to Sentry,
 * including full name, email, and other user details for better error tracking.
 *
 * The getUserIdentifier() method inherited from UserInterface serves as the UUID
 * for Sentry user identification.
 */
interface EnhancedUserInterface extends UserInterface
{
    /**
     * Returns the user's first name for Sentry enrichment.
     */
    public function getEnhancedFirstname(): ?string;

    /**
     * Returns the user's last name for Sentry enrichment.
     */
    public function getEnhancedLastname(): ?string;

    /**
     * Returns the user's email address for Sentry enrichment.
     */
    public function getEnhancedEmail(): ?string;

    /**
     * Note: getUserIdentifier() is inherited from UserInterface and serves as the UUID
     */
}

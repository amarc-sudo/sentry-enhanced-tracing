<?php

declare(strict_types=1);

namespace App\Entity;

use AmarcSudo\SentryEnhancedTracing\User\EnhancedUserInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Example implementation of the EnhancedUserInterface.
 * 
 * This class demonstrates how to implement the interface to automatically
 * enrich user information sent to Sentry for better error tracking.
 * 
 * By implementing EnhancedUserInterface, the SentryUserContextListener
 * will automatically capture and send detailed user information including
 * full name, email, and UUID to Sentry when errors occur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements EnhancedUserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 255)]
    private string $firstname;

    #[ORM\Column(type: 'string', length: 255)]
    private string $lastname;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    // Standard getters and setters...
    
    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    // Implementation of UserInterface
    public function getUserIdentifier(): string
    {
        return $this->uuid; // The UUID serves as unique identifier
    }

    public function eraseCredentials(): void
    {
        // If you store sensitive temporary data, clear it here
    }

    // Implementation of EnhancedUserInterface
    public function getEnhancedFirstname(): ?string
    {
        return $this->firstname;
    }

    public function getEnhancedLastname(): ?string
    {
        return $this->lastname;
    }

    public function getEnhancedEmail(): ?string
    {
        return $this->email;
    }
}

/**
 * Example usage with optional data.
 * 
 * This class demonstrates how to implement EnhancedUserInterface
 * when user information might be optional or incomplete.
 */
class OptionalDataUser implements EnhancedUserInterface
{
    private string $uuid;
    private ?string $firstname = null;
    private ?string $lastname = null;
    private ?string $email = null;

    public function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }

    // Implementation of UserInterface
    public function getUserIdentifier(): string
    {
        return $this->uuid;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // No credentials to erase
    }

    // Implementation of EnhancedUserInterface
    public function getEnhancedFirstname(): ?string
    {
        return $this->firstname;
    }

    public function getEnhancedLastname(): ?string
    {
        return $this->lastname;
    }

    public function getEnhancedEmail(): ?string
    {
        return $this->email;
    }

    // Setters for optional data
    public function setFirstname(?string $firstname): self
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function setLastname(?string $lastname): self
    {
        $this->lastname = $lastname;
        return $this;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }
} 
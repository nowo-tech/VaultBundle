<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Validator;

use Attribute;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Symfony\Component\Validator\Constraint;

/**
 * A typed login password must meet the owner's policy (1.16.0, `enforceOnSave`). An empty value is not validated: on
 * edit it keeps the stored password. The violation names the missing rules only, never the value.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class VaultPasswordPolicyConstraint extends Constraint
{
    public string $message = 'vault.form.password_policy';

    /**
     * Creates the constraint for a policy.
     *
     * @param list<string>|null $groups
     */
    public function __construct(
        public VaultPasswordPolicy $policy = new VaultPasswordPolicy(),
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}

<?php

declare(strict_types=1);

// Source mutation check of the access-decision equivalence matrix (tests/Unit/Equivalence; 1.19, ports only since 2.0): applies one mutation
// of the decision path at a time, runs the matrix, restores the file. Every mutation must be "killed".
// Usage (bundle root, in the PHP container): php scripts/mutation-check-access-matrix.php
$m = [
    ['src/Port/VaultAccessCheckerGate.php', 'return $this->policy->allowsAccess(VaultUserRefs::fromNullableUser($user)) === true;', 'return $this->policy->allowsAccess(VaultUserRefs::fromNullableUser($user)) !== true;'],
    ['src/Port/VaultAccessCheckerGate.php', 'return $this->policy->allowsCreate(VaultUserRefs::fromNullableUser($user)) === true;', 'return $this->policy->allowsAccess(VaultUserRefs::fromNullableUser($user)) === true;'],
    ['src/Port/VaultTeamMembershipGate.php', 'return $this->policy->teamIdsOf(VaultUserRefs::fromUser($user));', 'return [];'],
    ['src/Port/VaultScopeAccessGate.php', 'return $this->policy->scopeAccessOf(VaultUserRefs::fromUser($user), $scope);', 'return $this->policy->scopeAccessOf(VaultUserRefs::fromUser($user), \'org-1\');'],
    ['src/Support/VaultUserRefs.php', 'return new VaultUserRef($id, $user->getUserIdentifier(), $user->getRoles(), $user);', 'return new VaultUserRef($id, $user->getUserIdentifier(), $user->getRoles(), null);'],
    ['src/Support/VaultUserRefs.php', 'return new VaultUserRef($id, $user->getUserIdentifier(), $user->getRoles(), $user);', 'return new VaultUserRef(null === $id ? null : strtolower($id), $user->getUserIdentifier(), $user->getRoles(), $user);'],
    ['src/Security/ConfigurableVaultAccessChecker.php', 'return $this->known($user) && $this->canAccess($this->user($user));', 'return $this->known($user) && $this->canList($this->user($user));'],
    ['src/Security/ConfigurableVaultAccessChecker.php', 'return $this->known($user) && $this->canRevoke($this->user($user));', 'return $this->known($user) && $this->canCreate($this->user($user));'],
    ['src/Security/AllowAllVaultAccessChecker.php', "public function allowsList(?VaultUserRef \$user = null): bool\n    {\n        return true;", "public function allowsList(?VaultUserRef \$user = null): bool\n    {\n        return false;"],
    ['src/Security/NullVaultTeamMembershipResolver.php', "public function teamIdsOf(VaultUserRef \$user): array\n    {\n        return [];", "public function teamIdsOf(VaultUserRef \$user): array\n    {\n        return ['team-1'];"],
    ['src/Security/NullVaultScopeAccessResolver.php', "public function scopeAccessOf(VaultUserRef \$user, string \$scope): ?VaultScopeAccess\n    {\n        return null;", "public function scopeAccessOf(VaultUserRef \$user, string \$scope): ?VaultScopeAccess\n    {\n        return new VaultScopeAccess(null);"],
    ['src/Support/VaultUserClassPolicy.php', 'if ($user instanceof VaultUserId || $user instanceof VaultRefUser || $this->isUserClass($user)) {', 'if ($user instanceof VaultUserId || $user instanceof VaultRefUser || $this->isUserClass($user) || $user instanceof \\Symfony\\Component\\Security\\Core\\User\\UserInterface) {'],
    ['src/Security/ConfigurableVaultAccessChecker.php', 'return !$user instanceof VaultUserRef || VaultUserRefs::toUser($user) instanceof UserInterface;', 'return true;'],
    ['src/Support/VaultUserRefResolver.php', 'UserIdResolver::getId($found) === $ref->id ? $found : null;', '$found;'],
    ['src/Support/VaultUserRefs.php', 'return $user instanceof UserInterface ? $user : null;', 'return $user instanceof UserInterface ? $user : ($ref->id !== null ? new \\Nowo\\VaultBundle\\Tests\\Stub\\TestUser($ref->id, $ref->identifier, $ref->roles) : null);'],
    ['src/Security/RequestScopedScopeAccessCache.php', '$this->memo[$key] = $this->resolver->resolve($user, $scope);', '$this->memo[$key] = $this->resolver->resolve($user, \'org-1\');'],
];
$killed = 0;
foreach ($m as $i => [$file, $from, $to]) {
    $orig = file_get_contents($file);
    if (substr_count($orig, $from) !== 1) {
        echo "M{$i} NOT APPLICABLE ({$file})\n";
        continue;
    }
    file_put_contents($file, str_replace($from, $to, $orig));
    exec('vendor/bin/phpunit --no-progress --no-coverage tests/Unit/Equivalence 2>&1', $out, $code);
    file_put_contents($file, $orig);
    $status = $code === 0 ? 'SURVIVED' : 'killed';
    if ($code !== 0) {
        ++$killed;
    }
    echo "M{$i} {$status}  {$file}\n";
    $out = [];
}
echo "killed {$killed} / " . count($m) . "\n";
exit($killed === count($m) ? 0 : 1);

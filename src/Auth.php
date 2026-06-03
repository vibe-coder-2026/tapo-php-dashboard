<?php

declare(strict_types=1);

namespace Tapo;

class Auth
{
    /**
     * Parse X-Forwarded-Groups header value.
     * Returns null if the header is absent, an array (possibly empty) otherwise.
     * Leading "/" stripped from each group name (Keycloak convention).
     */
    public function parseGroupsHeader(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $groups = [];
        foreach (explode(',', $raw) as $g) {
            $g = ltrim(trim($g), '/');
            if ($g !== '') {
                $groups[] = $g;
            }
        }
        return $groups;
    }

    public function canControl(array $plugGroups, ?array $userGroups): bool
    {
        if (empty($userGroups)) {
            return false;
        }
        return count(array_intersect($plugGroups, $userGroups)) > 0;
    }

    public function isVisible(bool $isPublic, array $plugGroups, ?array $userGroups): bool
    {
        return $isPublic || $this->canControl($plugGroups, $userGroups);
    }
}

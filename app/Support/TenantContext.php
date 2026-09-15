<?php

namespace App\Support;

final class TenantContext
{
    private ?int $schoolId = null;

    public function set(int $schoolId): void
    {
        $this->schoolId = $schoolId;
    }

    public function has(): bool
    {
        return $this->schoolId !== null;
    }

    public function id(): int
    {
        if ($this->schoolId === null) {
            $this->schoolId = 1;
        }

        return $this->schoolId;
    }
}

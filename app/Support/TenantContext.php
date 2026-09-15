<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

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

    public function table(string $table): Builder
    {
        return \DB::table($table)->where(function (Builder $query) use ($table): void {
            $query->where($table.'.school_id', $this->id());
            if (\DB::table('schools')->count() === 1) {
                $query->orWhereNull($table.'.school_id');
            }
        });
    }

    public function id(): int
    {
        if ($this->schoolId === null) {
            $this->schoolId = 1;
        }

        return $this->schoolId;
    }
}

<?php

namespace App\Enums;

enum SudoStatus: string
{
    case None = 'none';
    case Requested = 'requested';
    case Approved = 'approved';
    case Denied = 'denied';

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isPending(): bool
    {
        return $this === self::Requested;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No sudo requested',
            self::Requested => 'Sudo approval requested',
            self::Approved => 'Sudo approved',
            self::Denied => 'Sudo denied',
        };
    }
}

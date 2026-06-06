<?php

class RoleHelper
{
    public const ADMIN = 'admin';
    public const CLINIC_DOCTOR = 'clinic_doctor';

    /** @deprecated Internal legacy slug — migrated to clinic_doctor */
    public const LEGACY_OPHTHALMOLOGIST = 'ophthalmologist';

    public static function label(?string $role): string
    {
        return match ($role) {
            self::ADMIN => 'System Administrator',
            self::CLINIC_DOCTOR, self::LEGACY_OPHTHALMOLOGIST => 'Clinic Doctor',
            default => 'Clinician',
        };
    }

    public static function isClinicDoctor(?string $role): bool
    {
        return in_array($role, [self::CLINIC_DOCTOR, self::LEGACY_OPHTHALMOLOGIST], true);
    }

    public static function isAdmin(?string $role): bool
    {
        return $role === self::ADMIN;
    }
}

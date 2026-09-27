<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person on the company's HR books, keyed by their real Employee ID.
 *
 * Today its only job is Employee ID login (see migration
 * 2026_09_24_000001). It is the seed of the HR / Payroll module's Employee
 * Master and will grow there; keep anything login-specific out of it.
 */
class Employee extends Model
{
    protected $fillable = ['employee_id', 'user_id', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The format every Employee ID is checked against, wherever it is typed
     * (Admin > Users and My Profile): letters, digits and dashes, up to 50 —
     * "074047", "HAPL-0231". Uniqueness is the caller's rule, because the two
     * screens differ on which existing records they may take.
     *
     * @return array<int, string>
     */
    public static function idFormatRules(): array
    {
        return ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'];
    }

    /**
     * @return array<string, string>
     */
    public static function idFormatMessages(string $attribute = 'employee_id'): array
    {
        return [
            $attribute.'.max' => 'Employee ID may be at most 50 characters.',
            $attribute.'.regex' => 'Employee ID may contain only letters, numbers and dashes.',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An Employee ID as it is stored and compared: trimmed, uppercased,
     * never blank. Uppercased because MySQL (local) compares IDs
     * case-insensitively and PostgreSQL (live) does not — "hapl-0231" and
     * "HAPL-0231" must be one ID on both. Leading zeros are kept exactly:
     * "074047" is not "74047".
     */
    public static function normalizeId(?string $raw): ?string
    {
        $id = strtoupper(trim((string) $raw));

        return $id === '' ? null : $id;
    }

    /**
     * The login account behind an Employee ID, or null when the ID is
     * unknown, the employee is inactive, or no account is linked. One
     * answer for all three, so the login screen has nothing to leak.
     */
    public static function loginUserFor(?string $rawId): ?User
    {
        $id = self::normalizeId($rawId);

        if ($id === null) {
            return null;
        }

        return self::query()
            ->where('employee_id', $id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->first()
            ?->user;
    }
}

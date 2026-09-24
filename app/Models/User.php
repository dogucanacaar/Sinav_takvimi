<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Uygulama kullanıcısı.
 *
 * Üç rol var ve ayrımları yetkilendirmenin tamamını belirler:
 *   admin            — veri aktarır, çözüm üretir, her şeyi görür
 *   department_head  — çözüm üretemez; kendi bölümünün programını görür
 *   lecturer         — sadece kendi gözetmenlik görevlerini görür
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ADMIN = 'admin';

    public const DEPARTMENT_HEAD = 'department_head';

    public const LECTURER = 'lecturer';

    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'role', 'lecturer_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function isDepartmentHead(): bool
    {
        return $this->role === self::DEPARTMENT_HEAD;
    }

    public function isLecturer(): bool
    {
        return $this->role === self::LECTURER;
    }

    /** Programı bütün hâlinde görebilir mi? */
    public function canSeeWholeSchedule(): bool
    {
        return $this->isAdmin() || $this->isDepartmentHead();
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ADMIN => 'Yönetici',
            self::DEPARTMENT_HEAD => 'Bölüm Başkanı',
            default => 'Öğretim Üyesi',
        };
    }
}

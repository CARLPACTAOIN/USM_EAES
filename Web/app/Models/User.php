<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_sub',
        'organization_id',
        'college_id',
        'department_id',
        'program_id',
        'student_id_number',
        'qr_code_value',
        'program_code',
        'year_level',
        'user_type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the organization that the user belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function adminAssignments(): HasMany
    {
        return $this->hasMany(AdminAssignment::class);
    }

    public function activeAdminAssignments(): HasMany
    {
        return $this->adminAssignments()->where('status', AdminAssignment::STATUS_ACTIVE);
    }

    public function scannerAssignments(): HasMany
    {
        return $this->hasMany(EventScannerAssignment::class, 'scanner_user_id');
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function activeOrganizationMemberships(): HasMany
    {
        return $this->organizationMemberships()->where('status', OrganizationMembership::STATUS_ACTIVE);
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function employeeRosterEntry(): HasOne
    {
        return $this->hasOne(EmployeeRosterEntry::class);
    }

    public function issuedScannerAssignments(): HasMany
    {
        return $this->hasMany(EventScannerAssignment::class, 'assigned_by_user_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function organizationOfficers(): HasMany
    {
        return $this->hasMany(OrganizationOfficer::class);
    }
}

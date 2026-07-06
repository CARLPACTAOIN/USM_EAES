<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasUuids, HasApiTokens, HasRoles;

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
        'program_id',
        'student_id_number',
        'qr_code_value',
        'program_code',
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
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function adminApplications()
    {
        return $this->hasMany(AdminApplication::class, 'applicant_id');
    }

    public function adminAssignments()
    {
        return $this->hasMany(AdminAssignment::class);
    }

    public function activeAdminAssignments()
    {
        return $this->adminAssignments()->where('status', AdminAssignment::STATUS_ACTIVE);
    }

    public function scannerAssignments()
    {
        return $this->hasMany(EventScannerAssignment::class, 'scanner_user_id');
    }

    public function organizationMemberships()
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function activeOrganizationMemberships()
    {
        return $this->organizationMemberships()->where('status', OrganizationMembership::STATUS_ACTIVE);
    }

    public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function employeeRosterEntry()
    {
        return $this->hasOne(EmployeeRosterEntry::class);
    }

    public function issuedScannerAssignments()
    {
        return $this->hasMany(EventScannerAssignment::class, 'assigned_by_user_id');
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}

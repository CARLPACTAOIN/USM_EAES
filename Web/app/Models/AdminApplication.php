<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminApplication extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'applicant_id',
        'request_type',
        'role_name',
        'organization_id',
        'college_id',
        'organization_name',
        'organization_acronym',
        'adviser_name',
        'academic_year',
        'term_start',
        'term_end',
        'position_title',
        'proof_document_path',
        'proof_document_original_name',
        'logo_path',
        'status',
        'review_remarks',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'term_start' => 'date',
        'term_end' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function applicant()
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'admin_application_programs');
    }
}

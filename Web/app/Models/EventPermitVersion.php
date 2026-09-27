<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPermitVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id', 'workflow_run_id', 'version_number', 'venue_id', 'venue_name_snapshot',
        'venue_location_snapshot', 'location_type', 'location_details',
        'confirmation_office', 'confirmation_date', 'confirmation_reference_number',
        'confirmation_document_path', 'confirmation_document_original_name',
        'ppa_document_path', 'ppa_document_original_name', 'amendment_reason',
        'approved_by', 'approved_at', 'verification_reference',
        'canonical_packet_snapshot', 'proposal_snapshot', 'permit_snapshot',
        'evidence_snapshot', 'approval_metadata_snapshot', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'confirmation_date' => 'date',
            'approved_at' => 'datetime',
            'canonical_packet_snapshot' => 'array',
            'proposal_snapshot' => 'array',
            'permit_snapshot' => 'array',
            'evidence_snapshot' => 'array',
            'approval_metadata_snapshot' => 'array',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (EventPermitVersion $version): void {
            $dirty = array_keys($version->getDirty());
            sort($dirty);

            if (
                in_array($dirty, [['superseded_at'], ['superseded_at', 'updated_at']], true)
                && $version->getOriginal('superseded_at') === null
                && $version->superseded_at !== null
            ) {
                return;
            }

            throw new DomainException('Event Permit Versions are immutable.');
        });

        static::deleting(function (): void {
            throw new DomainException('Event Permit Versions are immutable.');
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(ProposalWorkflowRun::class, 'workflow_run_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function copyOwners(): HasMany
    {
        return $this->hasMany(PermitCopyOwner::class, 'event_permit_version_id');
    }
}

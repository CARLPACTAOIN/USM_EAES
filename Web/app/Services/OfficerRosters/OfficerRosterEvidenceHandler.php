<?php

namespace App\Services\OfficerRosters;

use App\Models\OrganizationOfficerRoster;
use Illuminate\Http\UploadedFile;

class OfficerRosterEvidenceHandler
{
    public function applyRosterEvidence(OrganizationOfficerRoster $roster, ?UploadedFile $evidenceFile): void
    {
        if (! $evidenceFile) {
            return;
        }

        $path = $evidenceFile->store('officer_rosters', 'local');
        $roster->evidence_file_path = $path;
        $roster->evidence_file_name = $evidenceFile->getClientOriginalName();
        $roster->evidence_metadata = $this->metadataFor($evidenceFile);
    }

    /**
     * @return array{path: ?string, name: ?string, metadata: ?array{size: int|false, mime: ?string, uploaded_at: string}}
     */
    public function storeChangeRequestEvidence(?UploadedFile $evidenceFile): array
    {
        if (! $evidenceFile) {
            return [
                'path' => null,
                'name' => null,
                'metadata' => null,
            ];
        }

        return [
            'path' => $evidenceFile->store('officer_change_requests', 'local'),
            'name' => $evidenceFile->getClientOriginalName(),
            'metadata' => $this->metadataFor($evidenceFile),
        ];
    }

    /**
     * @return array{size: int|false, mime: ?string, uploaded_at: string}
     */
    private function metadataFor(UploadedFile $evidenceFile): array
    {
        return [
            'size' => $evidenceFile->getSize(),
            'mime' => $evidenceFile->getMimeType(),
            'uploaded_at' => now()->toIso8601String(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalTemplateApplicabilityRule>
 */
class ProposalTemplateApplicabilityRuleFactory extends Factory
{
    protected $model = ProposalTemplateApplicabilityRule::class;

    public function definition(): array
    {
        return [
            'template_version_id' => ProposalProcessTemplateVersion::factory(),
            'priority' => 0,
            'organizer_role' => null,
            'organization_type' => null,
            'college_id' => null,
            'event_domain' => null,
            'activity_category' => null,
            'location_type' => null,
            'is_default' => false,
            'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
            'effective_start' => null,
            'effective_end' => null,
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Services\ProposalWorkflowService;
use Illuminate\Database\Seeder;

class ProposalProcessTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(ProposalWorkflowService::class)->ensureDefaultPublishedTemplate();
    }
}

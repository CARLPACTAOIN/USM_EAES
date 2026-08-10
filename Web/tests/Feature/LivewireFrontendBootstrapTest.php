<?php

namespace Tests\Feature;

use Tests\TestCase;

class LivewireFrontendBootstrapTest extends TestCase
{
    public function test_vite_owns_the_single_livewire_and_alpine_bootstrap(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertStringContainsString(
            "import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';",
            $appJs
        );
        $this->assertStringContainsString('Livewire.start();', $appJs);
        $this->assertStringNotContainsString("import Alpine from 'alpinejs';", $appJs);
        $this->assertStringNotContainsString('Alpine.start();', $appJs);
        $this->assertStringNotContainsString("from 'html5-qrcode'", $appJs);
        $this->assertStringNotContainsString("Alpine.data('profileQrScanner'", $appJs);

        $this->assertStringContainsString('@livewireScriptConfig', $layout);
        $this->assertStringNotContainsString('@livewireScripts', $layout);
        $this->assertStringContainsString('vendor/html5-qrcode/html5-qrcode.min.js', $layout);
        $this->assertStringContainsString("window.Alpine.data('profileQrScanner'", $layout);
    }

    public function test_modal_editor_controls_use_immediate_alpine_state(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $createView = file_get_contents(resource_path('views/livewire/dashboard/event-proposal-create.blade.php'));
        $rulesView = file_get_contents(resource_path('views/livewire/dashboard/event-attendance-rules.blade.php'));
        $workflowView = file_get_contents(resource_path('views/livewire/dashboard/event-proposal-workflow.blade.php'));
        $participantScopesView = file_get_contents(resource_path('views/livewire/dashboard/event-participant-scopes.blade.php'));
        $assignAdminView = file_get_contents(resource_path('views/dashboard/admin-users/partials/assign-admin-form.blade.php'));
        $adminAccessView = file_get_contents(resource_path('views/dashboard/admin-users/index.blade.php'));
        $eventsIndexView = file_get_contents(resource_path('views/dashboard/events/index.blade.php'));

        $this->assertStringContainsString("Alpine.data('eventScheduleEditor'", $appJs);
        $this->assertStringContainsString("Alpine.data('adminAssignmentForm'", $appJs);
        $this->assertStringContainsString("Alpine.data('organizationForm'", $appJs);
        $this->assertStringContainsString("Alpine.data('participantAudienceBuilder'", $appJs);
        $this->assertStringContainsString("\$wire.entangle('eventDays')", $createView);
        $this->assertStringContainsString('x-on:click="addDay()"', $createView);
        $this->assertStringContainsString('x-on:click="removeDay(index)"', $createView);
        $this->assertStringNotContainsString('wire:click="addDay"', $createView);
        $this->assertStringNotContainsString('wire:click="removeDay', $createView);

        $this->assertStringContainsString("\$wire.entangle('eventLateCutoffMin')", $rulesView);
        $this->assertStringContainsString("'is-selected': String(cutoff) === '15'", $rulesView);
        $this->assertStringNotContainsString('wire:click="setEventPreset', $rulesView);
        $this->assertStringNotContainsString('wire:click="setScopePreset', $rulesView);
        $this->assertStringNotContainsString('is-recommended', $rulesView);

        $this->assertStringContainsString('setTimeout(() => visible = false, 4000)', $workflowView);

        $this->assertStringContainsString('name="person_query"', $assignAdminView);
        $this->assertStringContainsString('x-on:submit="prepareSubmit()"', $assignAdminView);
        $this->assertStringContainsString('oldPersonQuery', $assignAdminView);
        $this->assertStringNotContainsString("window.Alpine.data('adminAssignmentForm'", $adminAccessView);

        $this->assertStringContainsString('participantAudienceBuilder', $participantScopesView);
        $this->assertStringContainsString("\$wire.entangle('audienceMode')", $participantScopesView);
        $this->assertStringContainsString('x-on:click="setAudienceMode(\'all\')"', $participantScopesView);
        $this->assertStringContainsString('x-model.debounce.150ms="organizationQuery"', $participantScopesView);
        $this->assertStringNotContainsString('wire:click="setAudienceMode', $participantScopesView);
        $this->assertStringNotContainsString('wire:model.live.debounce.250ms="organizationQuery"', $participantScopesView);

        $this->assertStringContainsString("window.Livewire.hook('morphed'", $eventsIndexView);
        $this->assertStringContainsString("component.name === 'dashboard.events-workspace'", $eventsIndexView);
        $this->assertStringContainsString('requestAnimationFrame(() => window.initEventsDashboard?.())', $eventsIndexView);
    }
}

<?php
namespace App\Services\Leads;

use App\Models\LeadPipelineStage;
use App\Models\LeadSource;
use App\Models\Organization;

/** Lazily provisions the default pipeline stages + system sources per organization. */
class LeadCatalogService
{
    public function ensureDefaults(Organization $organization): void
    {
        if (LeadPipelineStage::withoutTenantScope()->where('organization_id', $organization->id)->count() === 0) {
            foreach (LeadPipelineStage::defaults() as $i => $stage) {
                LeadPipelineStage::withoutTenantScope()->create(array_merge($stage, [
                    'organization_id' => $organization->id,
                    'sort_order' => $i,
                    'is_system' => true,
                    'is_won' => $stage['is_won'] ?? false,
                    'is_lost' => $stage['is_lost'] ?? false,
                ]));
            }
        }
        foreach (LeadSource::systemSources() as $source) {
            LeadSource::withoutTenantScope()->firstOrCreate(
                ['organization_id' => $organization->id, 'key' => $source['key']],
                ['name' => $source['name'], 'is_system' => true]
            );
        }
    }

    public function sourceByKey(Organization $organization, string $key): LeadSource
    {
        $this->ensureDefaults($organization);
        return LeadSource::withoutTenantScope()
            ->where('organization_id', $organization->id)->where('key', $key)->firstOrFail();
    }

    public function defaultStage(Organization $organization): LeadPipelineStage
    {
        $this->ensureDefaults($organization);
        return LeadPipelineStage::withoutTenantScope()
            ->where('organization_id', $organization->id)->orderBy('sort_order')->firstOrFail();
    }
}

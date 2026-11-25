<?php
namespace App\Services\Leads;

use App\Models\LeadCampaign;
use App\Models\Organization;

/**
 * Decides which active Lead Campaign an incoming lead belongs to.
 * A campaign matches when:
 *  - it listens to the lead's source (or listens to all sources), and
 *  - one of its keywords appears in the enquiry text (notes, custom
 *    fields, location/property fields, campaign/UTM text), and
 *  - its optional location filter, when set, matches the lead's
 *    city/area/preferred location (leads with no location still match).
 * Campaigns with no keywords match every lead from their sources
 * (a catch-all campaign). First matching campaign (oldest) wins.
 */
class LeadCampaignMatcher
{
    public function match(Organization $organization, array $data, string $sourceKey): ?LeadCampaign
    {
        $campaigns = LeadCampaign::withoutTenantScope()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($campaigns->isEmpty()) return null;

        $haystack = $this->haystack($data);
        $leadLocations = array_filter([
            strtolower((string) ($data['city'] ?? '')),
            strtolower((string) ($data['area'] ?? '')),
            strtolower((string) ($data['preferred_location'] ?? '')),
        ]);

        foreach ($campaigns as $campaign) {
            $sources = $campaign->sources ?: [];
            if ($sources && !in_array($sourceKey, $sources, true)) continue;

            $locations = array_filter(array_map(fn ($l) => strtolower(trim((string) $l)), $campaign->locations ?: []));
            if ($locations && $leadLocations) {
                $locationOk = false;
                foreach ($locations as $loc) {
                    foreach ($leadLocations as $leadLoc) {
                        if (str_contains($leadLoc, $loc) || str_contains($loc, $leadLoc)) { $locationOk = true; break 2; }
                    }
                }
                if (!$locationOk) continue;
            }

            $keywords = array_filter(array_map(fn ($k) => strtolower(trim((string) $k)), $campaign->keywords ?: []));
            if (!$keywords) return $campaign; // catch-all for its sources
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) return $campaign;
            }
        }
        return null;
    }

    private function haystack(array $data): string
    {
        $parts = [];
        foreach (['notes', 'city', 'area', 'preferred_location', 'property_type', 'property_reference', 'intent', 'campaign_name', 'ad_name', 'utm_term', 'utm_campaign'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) $parts[] = (string) $data[$key];
        }
        if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
            $this->flatten($data['custom_fields'], $parts);
        }
        return strtolower(implode(' ', $parts));
    }

    private function flatten(array $values, array &$parts): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) { $this->flatten($value, $parts); continue; }
            if (is_scalar($value)) $parts[] = $key . ' ' . $value;
        }
    }
}

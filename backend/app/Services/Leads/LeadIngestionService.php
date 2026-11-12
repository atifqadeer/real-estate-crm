<?php
namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadTouchpoint;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for every lead source (manual, import, website form,
 * Meta Lead Ads). Implements the prompt's duplicate rules:
 *  - normalize phone (E.164) and email first
 *  - same Meta lead id again            -> ignored duplicate event
 *  - match on phone/WhatsApp/email AND (same-ish name or email match)
 *                                        -> touchpoint on the ORIGINAL lead
 *  - phone matches but the name is clearly a different person
 *                                        -> new lead flagged possible_duplicate_of
 *                                           for human review (never auto-merged)
 *  - otherwise                           -> new lead + auto-assignment
 */
class LeadIngestionService
{
    public function __construct(
        private LeadCatalogService $catalog,
        private LeadAssignmentService $assignment,
    ) {}

    /**
     * @return array{lead: Lead, outcome: 'created'|'touchpoint'|'possible_duplicate'|'duplicate_event'}
     */
    public function ingest(Organization $organization, array $data, string $sourceKey, ?User $actor = null, array $rawPayload = []): array
    {
        $this->catalog->ensureDefaults($organization);
        $source = $this->catalog->sourceByKey($organization, $sourceKey);

        $data['phone_e164'] = PhoneNormalizer::normalize($data['phone_e164'] ?? $data['phone'] ?? null);
        $data['whatsapp_e164'] = PhoneNormalizer::normalize($data['whatsapp_e164'] ?? $data['whatsapp'] ?? null);
        $data['email'] = PhoneNormalizer::normalizeEmail($data['email'] ?? null);
        unset($data['phone'], $data['whatsapp']);

        // Exact provider duplicate (e.g. webhook redelivery).
        if (!empty($data['meta_lead_id'])) {
            $existing = Lead::withoutTenantScope()
                ->where('organization_id', $organization->id)
                ->where('meta_lead_id', $data['meta_lead_id'])->first();
            if ($existing) {
                return ['lead' => $existing, 'outcome' => 'duplicate_event'];
            }
        }

        $match = $this->findMatch($organization, $data);

        if ($match) {
            $emailMatch = $data['email'] && $match->email && strcasecmp($data['email'], $match->email) === 0;
            $nameSimilar = $this->namesSimilar((string) ($data['full_name'] ?? ''), $match->full_name);
            if ($emailMatch || $nameSimilar) {
                $this->recordTouchpoint($organization, $match, $source->id, $data, $rawPayload);
                // Fill blanks on the original; never overwrite known facts.
                $fill = array_filter([
                    'city' => $match->city ?: ($data['city'] ?? null),
                    'area' => $match->area ?: ($data['area'] ?? null),
                    'property_type' => $match->property_type ?: ($data['property_type'] ?? null),
                    'preferred_location' => $match->preferred_location ?: ($data['preferred_location'] ?? null),
                    'email' => $match->email ?: ($data['email'] ?? null),
                    'whatsapp_e164' => $match->whatsapp_e164 ?: ($data['whatsapp_e164'] ?? null),
                ], fn ($v) => $v !== null);
                if ($fill) $match->update($fill);
                LeadActivity::create([
                    'organization_id' => $organization->id, 'lead_id' => $match->id, 'actor_id' => $actor?->id,
                    'type' => 'touchpoint',
                    'description' => "New enquiry from {$source->name} matched this existing lead; recorded as a touchpoint.",
                    'metadata' => ['source' => $sourceKey],
                ]);
                return ['lead' => $match->fresh(), 'outcome' => 'touchpoint'];
            }

            // Same phone, clearly different name: do NOT merge — flag for review.
            $lead = $this->createLead($organization, $data, $source->id, $actor);
            $lead->update(['possible_duplicate_of' => $match->id]);
            LeadActivity::create([
                'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => $actor?->id,
                'type' => 'possible_duplicate',
                'description' => "Shares a phone number with lead #{$match->id} ({$match->full_name}) but the name differs. Needs human review before any merge.",
                'metadata' => ['matched_lead_id' => $match->id],
            ]);
            $this->recordTouchpoint($organization, $lead, $source->id, $data, $rawPayload);
            return ['lead' => $lead->fresh(), 'outcome' => 'possible_duplicate'];
        }

        $lead = $this->createLead($organization, $data, $source->id, $actor);
        $this->recordTouchpoint($organization, $lead, $source->id, $data, $rawPayload);
        return ['lead' => $lead, 'outcome' => 'created'];
    }

    private function findMatch(Organization $organization, array $data): ?Lead
    {
        $phones = array_filter([$data['phone_e164'] ?? null, $data['whatsapp_e164'] ?? null]);
        return Lead::withoutTenantScope()
            ->where('organization_id', $organization->id)
            ->where(function ($q) use ($phones, $data) {
                if ($phones) {
                    $q->whereIn('phone_e164', $phones)->orWhereIn('whatsapp_e164', $phones);
                }
                if (!empty($data['email'])) {
                    $q->orWhere('email', $data['email']);
                }
            })
            ->orderBy('id')
            ->first();
    }

    private function namesSimilar(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        // Token overlap (handles "Muhammad Ahmed" vs "Ahmed") or close spelling.
        $ta = collect(preg_split('/\s+/', $a));
        $tb = collect(preg_split('/\s+/', $b));
        if ($ta->intersect($tb)->isNotEmpty()) return true;
        return levenshtein($a, $b) <= 3;
    }

    private function createLead(Organization $organization, array $data, int $sourceId, ?User $actor): Lead
    {
        return DB::transaction(function () use ($organization, $data, $sourceId, $actor) {
            $lead = Lead::withoutTenantScope()->create(array_merge(
                array_intersect_key($data, array_flip([
                    'full_name', 'phone_e164', 'whatsapp_e164', 'email', 'city', 'area', 'property_type',
                    'property_reference', 'intent', 'preferred_location', 'budget_min', 'budget_max',
                    'budget_currency', 'bedrooms', 'bathrooms', 'campaign_name', 'meta_lead_id', 'meta_form_id',
                    'meta_page_id', 'meta_campaign_id', 'meta_ad_id', 'meta_adgroup_id', 'ad_name',
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page',
                    'priority', 'consent_marketing', 'consent_whatsapp', 'notes', 'custom_fields',
                ])),
                [
                    'organization_id' => $organization->id,
                    'lead_source_id' => $sourceId,
                    'lead_pipeline_stage_id' => $this->catalog->defaultStage($organization)->id,
                ]
            ));
            LeadActivity::create([
                'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => $actor?->id,
                'type' => 'created', 'description' => 'Lead created.', 'metadata' => [],
            ]);
            if (empty($data['assigned_to'])) {
                $this->assignment->autoAssign($lead->fresh(), $actor);
            } elseif (!empty($data['assigned_to'])) {
                $lead->update(['assigned_to' => $data['assigned_to']]);
            }
            return $lead->fresh();
        });
    }

    private function recordTouchpoint(Organization $organization, Lead $lead, int $sourceId, array $data, array $rawPayload): void
    {
        LeadTouchpoint::create([
            'organization_id' => $organization->id,
            'lead_id' => $lead->id,
            'lead_source_id' => $sourceId,
            'source_label' => $data['campaign_name'] ?? $data['ad_name'] ?? null,
            'campaign_name' => $data['campaign_name'] ?? null,
            'meta_lead_id' => $data['meta_lead_id'] ?? null,
            'utm' => array_filter([
                'source' => $data['utm_source'] ?? null, 'medium' => $data['utm_medium'] ?? null,
                'campaign' => $data['utm_campaign'] ?? null, 'term' => $data['utm_term'] ?? null,
                'content' => $data['utm_content'] ?? null,
            ]) ?: null,
            'payload' => $rawPayload ?: null,
        ]);
    }
}

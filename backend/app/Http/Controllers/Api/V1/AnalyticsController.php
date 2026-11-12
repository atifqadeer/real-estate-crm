<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Analytics\AnalyticsService;
use App\Services\AuditLogger;
use App\Services\Exports\ReportExportService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public const REPORTS = ['leads', 'campaign-performance', 'funnel', 'agent-performance', 'publishing-history'];

    public function __construct(
        private AnalyticsService $analytics,
        private ReportExportService $exports,
        private AuditLogger $audit,
    ) {}

    public function overview(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('analytics.view'), 403);
        [$from, $to] = $this->analytics->range($request->query('from'), $request->query('to'), $request->query('range'));
        return response()->json(['data' => $this->analytics->clientOverview($organization, $from, $to)]);
    }

    public function agencyOverview(Request $request, Organization $organization)
    {
        abort_unless($organization->type === 'agency', 404);
        abort_unless($request->user()->can('analytics.view'), 403);
        [$from, $to] = $this->analytics->range($request->query('from'), $request->query('to'), $request->query('range'));
        return response()->json(['data' => $this->analytics->agencyOverview($organization, $from, $to)]);
    }

    /** Exports are permission-gated and audit-logged (prompt section 16). */
    public function export(Request $request, Organization $organization, string $report)
    {
        abort_unless($request->user()->can('analytics.export'), 403);
        abort_unless(in_array($report, self::REPORTS, true), 404);
        $format = strtolower((string) $request->query('format', 'csv'));
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 422, 'format must be csv, xlsx or pdf');
        [$from, $to] = $this->analytics->range($request->query('from'), $request->query('to'), $request->query('range'));
        $dataset = $this->analytics->reportDataset($organization, $report, $from, $to);
        $this->audit->log($organization->id, $request->user()->id, 'analytics.exported', [
            'report' => $report, 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ]);
        $filename = "{$report}-{$from->toDateString()}-{$to->toDateString()}.{$format}";
        [$body, $contentType] = match ($format) {
            'csv' => [$this->exports->toCsv($dataset), 'text/csv'],
            'xlsx' => [$this->exports->toXlsx($dataset), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => [$this->exports->toPdf($dataset), 'application/pdf'],
        };
        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

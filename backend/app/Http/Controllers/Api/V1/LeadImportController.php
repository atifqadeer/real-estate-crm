<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadImport;
use App\Models\Organization;
use App\Services\Leads\LeadIngestionService;
use App\Services\Leads\SimpleXlsxReader;
use Illuminate\Http\Request;

class LeadImportController extends Controller
{
    /** Header synonyms -> lead fields (case-insensitive). */
    private const HEADER_MAP = [
        'full_name' => ['full name', 'name', 'lead name', 'customer name', 'full_name'],
        'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'contact', 'contact number', 'phone_number'],
        'whatsapp' => ['whatsapp', 'whatsapp number', 'whatsapp_number'],
        'email' => ['email', 'email address', 'e-mail'],
        'city' => ['city'],
        'area' => ['area', 'locality'],
        'preferred_location' => ['preferred location', 'location', 'interested location'],
        'property_type' => ['property type', 'property_type'],
        'property_reference' => ['property reference', 'property', 'project'],
        'budget_min' => ['budget min', 'budget_min', 'min budget', 'budget'],
        'budget_max' => ['budget max', 'budget_max', 'max budget'],
        'notes' => ['notes', 'note', 'message', 'comments'],
        'campaign_name' => ['campaign', 'campaign name', 'campaign_name'],
    ];

    public function __construct(private LeadIngestionService $ingestion) {}

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.import'), 403);
        return response()->json(LeadImport::where('organization_id', $organization->id)->latest()->paginate(15));
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.import'), 403);
        $request->validate(['file' => ['required', 'file', 'max:10240']]); // 10 MB
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        try {
            $rows = match ($ext) {
                'csv', 'txt' => $this->readCsv($file->getRealPath()),
                'xlsx' => SimpleXlsxReader::read($file->getRealPath()),
                default => throw new \RuntimeException('Only .csv and .xlsx files are supported.'),
            };
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['code' => 'unreadable_file', 'message' => $e->getMessage()]], 422);
        }

        $report = ['total' => 0, 'created' => 0, 'touchpoints' => 0, 'possible_duplicates' => 0, 'invalid' => 0, 'errors' => []];
        foreach ($rows as $i => $row) {
            $report['total']++;
            $data = $this->mapRow($row);
            if (!$data || (empty($data['phone']) && empty($data['email']))) {
                $report['invalid']++;
                if (count($report['errors']) < 20) $report['errors'][] = ['row' => $i + 2, 'error' => 'Missing name, or no phone/email.'];
                continue;
            }
            $result = $this->ingestion->ingest($organization, $data, 'csv_import', $request->user(), ['row' => $i + 2]);
            match ($result['outcome']) {
                'created' => $report['created']++,
                'touchpoint', 'duplicate_event' => $report['touchpoints']++,
                'possible_duplicate' => $report['possible_duplicates']++,
            };
        }

        $import = LeadImport::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'filename' => $file->getClientOriginalName(),
            'total_rows' => $report['total'],
            'created_count' => $report['created'],
            'touchpoint_count' => $report['touchpoints'],
            'possible_duplicate_count' => $report['possible_duplicates'],
            'invalid_count' => $report['invalid'],
            'row_errors' => $report['errors'],
        ]);

        return response()->json(['data' => $import, 'report' => $report], 201);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) throw new \RuntimeException('Could not read the CSV file.');
        $headers = null;
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => trim((string) $h), $line);
                continue;
            }
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            $row = [];
            foreach ($headers as $i => $h) $row[$h] = $line[$i] ?? '';
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function mapRow(array $row): ?array
    {
        $lower = [];
        foreach ($row as $k => $v) $lower[strtolower(trim((string) $k))] = is_string($v) ? trim($v) : $v;
        $data = [];
        foreach (self::HEADER_MAP as $field => $synonyms) {
            foreach ($synonyms as $syn) {
                if (isset($lower[$syn]) && $lower[$syn] !== '') {
                    $data[$field] = $lower[$syn];
                    break;
                }
            }
        }
        if (empty($data['full_name'])) return null;
        foreach (['budget_min', 'budget_max'] as $b) {
            if (isset($data[$b])) {
                $data[$b] = is_numeric(str_replace([',', ' '], '', (string) $data[$b])) ? (float) str_replace([',', ' '], '', (string) $data[$b]) : null;
            }
        }
        return $data;
    }
}

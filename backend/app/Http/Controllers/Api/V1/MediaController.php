<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];
    private const IMAGE_MAX_KB = 10240;    // 10 MB
    private const VIDEO_MAX_KB = 102400;   // 100 MB

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('media.view'), 403);
        $assets = MediaAsset::where('organization_id', $organization->id)
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->latest()->paginate(30);
        return response()->json($assets);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('media.upload'), 403);
        $request->validate(['file' => ['required', 'file', 'max:' . self::VIDEO_MAX_KB]]);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $isImage = in_array($mime, self::IMAGE_MIMES, true);
        $isVideo = in_array($mime, self::VIDEO_MIMES, true);
        if (!$isImage && !$isVideo) {
            return response()->json(['error' => ['code' => 'unsupported_media', 'message' => 'Only JPEG/PNG/WebP images and MP4/MOV videos are accepted.']], 422);
        }
        if ($isImage && $file->getSize() > self::IMAGE_MAX_KB * 1024) {
            return response()->json(['error' => ['code' => 'image_too_large', 'message' => 'Images must be 10 MB or smaller.']], 422);
        }

        $path = $file->store("org-{$organization->id}/" . now()->format('Y/m'), 'public');
        $fullPath = Storage::disk('public')->path($path);

        $width = $height = null;
        if ($isImage && ($size = @getimagesize($fullPath))) {
            [$width, $height] = $size;
        }

        $asset = MediaAsset::create([
            'organization_id' => $organization->id,
            'uploaded_by' => $request->user()->id,
            'disk' => 'public',
            'path' => $path,
            // Meta fetches media from this URL — production must serve a public
            // domain/S3 here (APP_URL); a localhost URL will not work live.
            'public_url' => rtrim((string) config('app.url'), '/') . '/storage/' . $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'extension' => Str::lower($file->getClientOriginalExtension()),
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'kind' => $isImage ? 'image' : 'video',
        ]);

        return response()->json(['data' => $asset], 201);
    }

    public function destroy(Request $request, Organization $organization, MediaAsset $media)
    {
        abort_unless($media->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('media.delete'), 403);
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
        return response()->json(['data' => ['message' => 'Media deleted.']]);
    }
}

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Global (platform-wide) integration settings — Meta/LinkedIn/AI app
        // credentials. Distinct from client_settings, which is per-workspace.
        // Secret values are stored encrypted (see PlatformSettings service).
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->boolean('is_secret')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};

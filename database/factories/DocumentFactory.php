<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\ScanStatus;
use App\Models\Document;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata only: no file is written. Upload through the API when a test needs the file.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'uploaded_by' => User::factory(),
            'type' => DocumentType::Evidence,
            'title' => fake()->sentence(3),
            'disk' => 'local',
            'path' => 'incidents/test/'.Str::uuid().'/screenshot.png',
            'original_name' => 'screenshot.png',
            'mime' => 'image/png',
            'size' => 1024,
            'sha256' => hash('sha256', Str::random()),
            'scan_status' => ScanStatus::NotScanned,
            'current_version' => 1,
        ];
    }
}

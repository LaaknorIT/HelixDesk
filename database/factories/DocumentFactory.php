<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'tenant_id' => Tenant::factory(),
            'doc_type' => 'kb',
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 99999),
            'content' => fake()->paragraphs(2, true),
            'status' => 'draft',
            'visibility' => 'authenticated',
            'owner_user_id' => null,
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\PulseResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Department Pulse survey submission. Identity and department are columns; everything
 * else lives in the answers JSON so the form can evolve without a migration per question.
 *
 * @property int $id
 * @property string $department
 * @property string $name
 * @property string $role
 * @property array<string, mixed> $answers
 * @property string $edit_token_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['department', 'name', 'role', 'answers', 'edit_token_hash'])]
#[Hidden(['edit_token_hash'])]
class PulseResponse extends Model
{
    /** @use HasFactory<PulseResponseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Compare in constant time so a wrong token cannot be discovered byte by byte.
     */
    public function hasEditToken(string $token): bool
    {
        return $token !== '' && hash_equals($this->edit_token_hash, self::hashToken($token));
    }

    public function departmentName(): string
    {
        return (string) config("pulse.departments.{$this->department}.name", $this->department);
    }
}

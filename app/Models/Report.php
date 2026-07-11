<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category',
        'title',
        'description',
        'address',
        'state',
        'country',
        'latitude',
        'longitude',
        'status',
        'ai_score',
        'images',
        'required_confirmations',
        'is_emergency',
    ];

    protected $casts = [
        'images'       => 'array',
        'latitude'     => 'float',
        'longitude'    => 'float',
        'ai_score'     => 'integer',
        'is_emergency' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(ReportConfirmation::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * Reports located in the same state as the given state string.
     * This is how "if you're in Abuja, you see Abuja reports" is implemented.
     */
    public function scopeInState(Builder $query, ?string $state): Builder
    {
        return $query->when($state, fn (Builder $q) => $q->where('state', $state));
    }

    public function scopeNotOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', '!=', $userId);
    }

    // ── Accessors ────────────────────────────────────────────────────────

    /**
     * Public URLs for the evidence images attached at submission time.
     */
    public function getImageUrlsAttribute(): array
    {
        return collect($this->images ?? [])
            ->map(fn ($path) => asset('storage/' . $path))
            ->values()
            ->all();
    }

    public function getReferenceCodeAttribute(): string
    {
        return '#NA-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }
}
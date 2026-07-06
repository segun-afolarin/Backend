<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'user_id',
        'evidence_path',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────

    /**
     * Public URL for the confirmation's evidence photo.
     */
    public function getEvidenceUrlAttribute(): ?string
    {
        return $this->evidence_path
            ? asset('storage/' . $this->evidence_path)
            : null;
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HtNetworkUpdate extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeder::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class);
    }

    public function summary(): array
    {
        $payload = $this->payload;
        unset($payload['photos']);

        return $this->only(['client_uuid', 'feeder_id', 'survey_team_id', 'transformer_code', 'transformer_id', 'status', 'revision', 'review_note'])
            + ['payload' => $payload];
    }
}

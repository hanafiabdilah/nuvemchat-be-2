<?php

namespace App\Models;

use App\Enums\Flow\NodeType;
use Illuminate\Database\Eloquent\Model;

class FlowNode extends Model
{
    protected $fillable = [
        'flow_id',
        'type',
        'data',
        'position_x',
        'position_y',
    ];

    protected $casts = [
        'type' => NodeType::class,
    ];

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }
}

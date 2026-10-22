<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebinarDocument extends Model
{
    use HasPublicId, SoftDeletes;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['file_path', 'storage_disk'];

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }
}

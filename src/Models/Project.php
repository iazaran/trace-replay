<?php

namespace TraceReplay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Project extends Model
{
    use HasUuids;

    protected $table = 'tr_projects';

    protected $fillable = ['workspace_id', 'name'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function traces()
    {
        return $this->hasMany(Trace::class, 'project_id');
    }
}

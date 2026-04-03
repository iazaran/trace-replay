<?php

namespace TraceReplay\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasUuids;

    protected $table = 'tr_workspaces';

    protected $fillable = ['name'];

    public function projects()
    {
        return $this->hasMany(Project::class, 'workspace_id');
    }
}

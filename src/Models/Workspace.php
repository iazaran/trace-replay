<?php

namespace TraceReplay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

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

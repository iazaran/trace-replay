<?php

namespace TraceReplay\View\Components;

use Illuminate\View\Component;
use TraceReplay\Facades\TraceReplay;

class TraceBar extends Component
{
    public function __construct(
        public readonly bool $show = true,
    ) {}

    public function render()
    {
        if (!$this->show || !config('tracereplay.enabled', true)) {
            return '';
        }

        $trace = TraceReplay::getCurrentTrace();

        return view('tracereplay::components.trace-bar', [
            'trace' => $trace,
        ]);
    }
}


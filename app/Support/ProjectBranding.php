<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProjectBranding
{
    public const DEFAULT_NAME = 'HAZECO Transmission and Distribution Losses Calculation Project';

    public function appName(): string
    {
        return 'HAZECO Survey App';
    }

    private bool $resolved = false;

    private ?Project $project = null;

    public function projectName(): string
    {
        return $this->project()?->name ?: self::DEFAULT_NAME;
    }

    public function logoUrl(): string
    {
        return $this->assetUrl($this->project()?->logo_path, 'branding/barqaab-logo.png');
    }

    public function faviconUrl(): string
    {
        return $this->assetUrl($this->project()?->favicon_path, 'branding/barqaab-favicon.png');
    }

    private function project(): ?Project
    {
        if ($this->resolved) {
            return $this->project;
        }

        try {
            if (! Schema::hasTable('projects')) {
                return null;
            }

            $this->resolved = true;
            $this->project = Project::query()
                ->where('code', 'HAZECO-TDL')
                ->orWhere('status', 'active')
                ->orderByRaw("CASE WHEN code = 'HAZECO-TDL' THEN 0 ELSE 1 END")
                ->first();
        } catch (Throwable) {
            $this->project = null;
        }

        return $this->project;
    }

    private function assetUrl(?string $storedPath, string $fallback): string
    {
        if ($storedPath) {
            return Storage::disk('public')->url($storedPath);
        }

        return asset($fallback);
    }
}

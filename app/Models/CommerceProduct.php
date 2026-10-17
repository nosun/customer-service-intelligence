<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceProduct extends Model
{
    protected $guarded = [];

    public function primaryImageUrl(int $width = 828): ?string
    {
        $image = collect($this->images ?? [])->first(fn ($image) => (bool) ($image['is_main'] ?? false))
            ?? collect($this->images ?? [])->first();
        $url = is_array($image) ? ($image['url'] ?? null) : $image;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        if (! $host || ! $path || ! preg_match('#^/image/0x0/(\d+)/(\d+)/[^/]+/(.+)$#', $path, $matches)) {
            return $url;
        }

        $hostParts = explode('.', $host);
        $siteName = $hostParts[count($hostParts) - 2] ?? null;

        if (! $siteName) {
            return $url;
        }

        [, $quality, $type, $name] = $matches;
        $signature = substr(md5(md5($siteName).$width.'0'.$quality.$type.$name), 0, 16);

        return ($parts['scheme'] ?? 'https').'://'.$host."/image/{$width}x0/{$quality}/{$type}/{$signature}/{$name}";
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }

    protected function casts(): array
    {
        return [
            'product' => 'array',
            'product_detail' => 'array',
            'images' => 'array',
            'options' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}

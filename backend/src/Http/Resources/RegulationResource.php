<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Product;
use App\Models\Regulation;
use App\Models\RegulationAcceptance;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\Capsule\Manager as Capsule;

final class RegulationResource
{
    /**
     * @return array<string,mixed>
     */
    public static function toArray(Regulation $reg, ?User $viewer, bool $withBody): array
    {
        $isStaff = $viewer !== null && $viewer->isStaff();

        $targets = [];
        foreach ($reg->targets()->get() as $target) {
            $name = null;
            if ($target->target_type === 'category') {
                $name = Category::find($target->target_id)?->name;
            } else {
                $name = Product::find($target->target_id)?->name;
            }
            $targets[] = [
                'target_type' => $target->target_type,
                'target_id' => (int) $target->target_id,
                'target_name' => $name,
            ];
        }

        $acceptance = null;
        if ($viewer !== null) {
            $row = RegulationAcceptance::where('regulation_id', $reg->id)
                ->where('user_id', $viewer->id)
                ->where('version', $reg->version)
                ->first();
            $acceptance = [
                'accepted' => $row !== null,
                'version' => (int) $reg->version,
                'accepted_at' => $row !== null ? Dates::iso($row->accepted_at) : null,
            ];
        }

        $out = [
            'id' => (int) $reg->id,
            'slug' => $reg->slug,
            'title' => $reg->title,
            'summary' => $reg->summary,
            'scope' => $reg->scope,
            'content_type' => $reg->content_type,
            'file_url' => $reg->content_type === 'pdf' ? '/api/v1/regulations/' . $reg->id . '/file' : null,
            'file_name' => $reg->file_name,
            'file_size' => $reg->file_size !== null ? (int) $reg->file_size : null,
            'version' => (int) $reg->version,
            'requires_acceptance' => (bool) $reg->requires_acceptance,
            'is_active' => (bool) $reg->is_active,
            'published_at' => Dates::iso($reg->published_at),
            'position' => (int) $reg->position,
            'targets' => $targets,
            'acceptance' => $acceptance,
            'created_at' => Dates::iso($reg->created_at),
            'updated_at' => Dates::iso($reg->updated_at),
        ];
        if ($withBody) {
            $out['body'] = $reg->body;
        }
        if ($isStaff) {
            $out['acceptances_count'] = (int) Capsule::table('regulation_acceptances')
                ->where('regulation_id', $reg->id)
                ->where('version', $reg->version)
                ->count();
        }
        return $out;
    }

    /** Reduced form used in pending_regulations lists. @return array<string,mixed> */
    public static function pendingItem(Regulation $reg, bool $withBlocking = false): array
    {
        $out = [
            'id' => (int) $reg->id,
            'slug' => $reg->slug,
            'title' => $reg->title,
            'summary' => $reg->summary,
            'scope' => $reg->scope,
            'version' => (int) $reg->version,
            'content_type' => $reg->content_type,
            'file_url' => $reg->content_type === 'pdf' ? '/api/v1/regulations/' . $reg->id . '/file' : null,
        ];
        if ($withBlocking) {
            $out['blocking'] = $reg->scope === 'global';
        }
        return $out;
    }
}

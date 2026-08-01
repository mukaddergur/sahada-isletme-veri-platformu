<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Project;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'min_reviews' => ['nullable', 'integer', 'min:0'],
            'min_ai_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'has_website' => ['nullable', 'boolean'],
            'has_phone' => ['nullable', 'boolean'],
            'has_email' => ['nullable', 'boolean'],
            'has_instagram' => ['nullable', 'boolean'],
            'has_linkedin' => ['nullable', 'boolean'],
            'has_facebook' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:rating,review_count,ai_score,name,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        $query = Business::query()
            ->with(['social', 'aiAnalysis', 'websiteAnalysis'])
            ->where(function ($q) {
                $q->whereNull('place_id')->orWhere('place_id', 'not like', 'demo_%');
            })
            ->when(! $request->user()->isAdmin(), function ($q) use ($request) {
                $q->whereHas('project', fn ($p) => $p->where('user_id', $request->user()->id));
            });

        if (! empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);
            abort_unless($request->user()->isAdmin() || $project->user_id === $request->user()->id, 403);
            $query->where('project_id', $project->id);
        }

        if (! empty($data['q'])) {
            $q = $data['q'];
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%")
                    ->orWhere('website', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        foreach (['category', 'city', 'district'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        if (isset($data['min_rating'])) {
            $query->where('rating', '>=', $data['min_rating']);
        }
        if (isset($data['min_reviews'])) {
            $query->where('review_count', '>=', $data['min_reviews']);
        }
        if (isset($data['min_ai_score'])) {
            $query->where('ai_score', '>=', $data['min_ai_score']);
        }
        if (array_key_exists('has_website', $data) && $data['has_website'] !== null) {
            $data['has_website'] ? $query->whereNotNull('website') : $query->whereNull('website');
        }
        if (array_key_exists('has_phone', $data) && $data['has_phone'] !== null) {
            $data['has_phone'] ? $query->whereNotNull('phone') : $query->whereNull('phone');
        }
        if (array_key_exists('has_email', $data) && $data['has_email'] !== null) {
            $data['has_email'] ? $query->whereNotNull('email') : $query->whereNull('email');
        }
        if (! empty($data['has_instagram'])) {
            $query->whereHas('social', fn ($s) => $s->whereNotNull('instagram'));
        }
        if (! empty($data['has_linkedin'])) {
            $query->whereHas('social', fn ($s) => $s->whereNotNull('linkedin'));
        }
        if (! empty($data['has_facebook'])) {
            $query->whereHas('social', fn ($s) => $s->whereNotNull('facebook'));
        }

        $sort = $data['sort'] ?? 'rating';
        $direction = $data['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        return response()->json($query->paginate($data['per_page'] ?? 20));
    }

    public function show(Request $request, Business $business)
    {
        $business->load(['social', 'aiAnalysis', 'websiteAnalysis', 'project']);
        abort_unless(
            $request->user()->isAdmin() || $business->project->user_id === $request->user()->id,
            403
        );

        return response()->json($business);
    }
}

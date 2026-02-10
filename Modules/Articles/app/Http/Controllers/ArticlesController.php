<?php

namespace Modules\Articles\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Articles\Models\Article;
use Modules\Articles\Http\Requests\ArticleStoreRequest;
use Modules\Articles\Http\Requests\ArticleUpdateRequest;
use Illuminate\Support\Facades\Storage;
use Modules\Notifications\Services\NotificationService;

class ArticlesController extends Controller
{
    public function frontArticles(Request $request)
    {
        $perPage = $request->get('per_page', 20);

        $articles = Article::with('categories', 'author')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'لیست مقالات',
            'data'    => $articles
        ]);
    }
    public function frontArticle($id)
    {
        // مقاله اصلی
        $article = Article::with('categories', 'author', 'comments')->find($id);

        if (!$article) {
            return response()->json([
                'success' => false,
                'message' => 'مقاله پیدا نشد',
            ], 404);
        }

        $categoryIds = $article->categories->pluck('id');
        $relatedArticles = collect();
        if ($categoryIds->isNotEmpty()) {
            $relatedArticles = Article::with('author')
                ->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                })
                ->where('id', '!=', $article->id)
                ->latest('created_at')
                ->take(10)
                ->get();
        }

        return response()->json([
            'success' => true,
            'message' => 'جزئیات مقاله',
            'data'    => [
                'article' => $article,
                'related' => $relatedArticles
            ]
        ]);
    }

    // List articles with pagination
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $articles = Article::with('categories', 'author')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'لیست مقالات',
            'data'    => $articles
        ]);
    }

    // Show single article
    public function show($id)
    {
        $article = Article::with('categories', 'author', 'comments')->find($id);

        if (!$article) {
            return response()->json([
                'success' => false,
                'message' => 'مقاله پیدا نشد',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'جزئیات مقاله',
            'data'    => $article
        ]);
    }

    // Store new article
    public function store(ArticleStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        // Set author_id from authenticated user
        $data['author_id'] = $request->user()->id;

        // Handle image upload if exists
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('articles', 'public');
            $data['image'] = $path;
        }

        $article = Article::create($data);

        // Sync categories
        if (!empty($data['category_ids'])) {
            $article->categories()->sync($data['category_ids']);
        }
        $notifications->create(
            " ثبت  مقاله",
            " مقاله  {$article->title}در سیستم ثبت  شد",
            "notification_article",
            ['article' => $article->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'مقاله با موفقیت ثبت شد',
            'data'    => $article->load('categories', 'author')
        ], 201);
    }


    // Update article
    public function update(ArticleUpdateRequest $request, $id, NotificationService $notifications)
    {
        $article = Article::findOrFail($id);

        if (!$article) {
            return response()->json([
                'success' => false,
                'message' => 'مقاله پیدا نشد',
            ], 404);
        }

        $data = $request->validated();

        // Handle image upload if exists
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($article->image) {
                Storage::disk('public')->delete($article->image);
            }
            $path = $request->file('image')->store('articles', 'public');
            $data['image'] = $path;
        }

        $article->update($data);
        $notifications->create(
            " ویرایش  مقاله",
            " مقاله  {$article->title}در سیستم ویرایش  شد",
            "notification_article",
            ['article' => $article->id]
        );
        // Sync categories
        if (isset($data['category_ids'])) {
            $article->categories()->sync($data['category_ids']);
        }

        return response()->json([
            'success' => true,
            'message' => 'مقاله با موفقیت ویرایش شد',
            'data'    => $article->load('categories', 'author')
        ]);
    }

    // Delete article
    public function destroy($id, NotificationService $notifications)
    {
        $article = Article::find($id);

        if (!$article) {
            return response()->json([
                'success' => false,
                'message' => 'مقاله پیدا نشد',
            ], 404);
        }
        if ($article->comments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'برای این مقاله کامنت ثبت شده و قابل حذف نیست',
            ], 422);
        }
        // Delete image if exists
        if ($article->image) {
            Storage::disk('public')->delete($article->image);
        }
        $notifications->create(
            " حذف  مقاله",
            " مقاله  {$article->title}از سیستم حذف  شد",
            "notification_article",
            ['article' => $article->id]
        );
        $article->delete();

        return response()->json([
            'success' => true,
            'message' => 'مقاله با موفقیت حذف شد',
        ]);
    }
}

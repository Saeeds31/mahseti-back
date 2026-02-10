<?php

namespace Modules\Comments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Comments\Models\Comment;

class CommentsController extends Controller
{
    // لیست کامنت‌های یک مقاله یا محصول
    public function indexArticle($id)
    {
        $comments = Comment::where('commentable_type', 'Modules\\Articles\\Models\\Article')
            ->where('commentable_id', $id)
            ->whereNull('parent_id')
            ->where('status', 1) // فقط تایید شده‌ها
            ->with(['user', 'replies.user'])
            ->latest();
        return response()->json($comments);
    }
    public function indexProducts($id)
    {
        $comments = Comment::where('commentable_type', 'Modules\\Products\\Models\\Product')
            ->where('commentable_id', $id)
            ->whereNull('parent_id')
            ->where('status', 1) // فقط تایید شده‌ها
            ->with(['user', 'replies.user'])
            ->latest();
        return response()->json($comments);
    }
    // ثبت کامنت جدید
    public function storeArticle(Request $request, $id)
    {
        $user = $request->user();
        $request->validate([
            'content' => 'required|string|min:3',
            'rating' => 'nullable|integer|min:1|max:5',
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $comment = Comment::create([
            'content' => $request->content,
            'user_id' => $user->id,
            'commentable_type' => 'Modules\\Articles\\Models\\Article',
            'commentable_id' => $id,
            'rating' => $request->rating,
            'parent_id' => $request->parent_id,
            'ip' => $request->ip(),
            'status' => 0, // پیش‌فرض pending
        ]);

        return response()->json([
            'message' => 'کامنت شما ثبت شد و پس از تایید نمایش داده خواهد شد.',
            'comment' => $comment
        ]);
    }
    public function storeProducts(Request $request, $id)
    {
        $user = $request->user();
        $request->validate([
            'content' => 'required|string|min:3',
            'rating' => 'nullable|integer|min:1|max:5',
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $comment = Comment::create([
            'content' => $request->content,
            'user_id' => $user->id,
            'commentable_type' => 'Modules\\Products\\Models\\Product',
            'commentable_id' => $id,
            'rating' => $request->rating,
            'parent_id' => $request->parent_id,
            'ip' => $request->ip(),
            'status' => 0, // پیش‌فرض pending
        ]);

        return response()->json([
            'message' => 'کامنت شما ثبت شد و پس از تایید نمایش داده خواهد شد.',
            'comment' => $comment
        ]);
    }
    // مشاهده جزئیات یک کامنت
    public function show($id)
    {
        $comment = Comment::findOrFail($id);
        return response()->json($comment->load(['user', 'replies.user']));
    }
    // لیست همه کامنت‌ها
    public function indexAdmin()
    {
        $comments = Comment::with('user', 'commentable')
            ->latest()
            ->whereNull('parent_id')
            ->paginate(20);
        return response()->json($comments);
    }

    // تغییر وضعیت کامنت (تایید، رد، ...)
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:0,1,2', // 0=pending, 1=approved, 2=rejected
        ]);
        $comment = Comment::findOrFail($id);
        $comment->update(['status' => $request->status]);

        return response()->json([
            'message' => 'وضعیت کامنت تغییر کرد.',
            'comment' => $comment
        ]);
    }

    // پاسخ به یک کامنت
    public function reply(Request $request, $id)
    {
        $request->validate([
            'content' => 'required|string|min:3',
        ]);
        $comment = Comment::findOrFail($id);
        $user = $request->user();
        $reply = Comment::create([
            'content' => $request->content,
            'user_id' => $user->id,
            'commentable_type' => $comment->commentable_type,
            'commentable_id' => $comment->commentable_id,
            'parent_id' => $comment->id,
            'status' => 1, // پاسخ ادمین مستقیم تایید شده
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'پاسخ ثبت شد.',
            'reply' => $reply
        ]);
    }

    // حذف کامنت
    public function destroy($id)
    {
        $comment = Comment::findOrFail($id);
        $ex = Comment::where('parent_id', $comment->id)->exists();
        if ($ex) {
            return response()->json([
                'message' => 'این کامنت به عنوان پاسخ استفاده شده و قابل حذف نیست',
                'success' => false,
            ]);
        }
        $comment->delete();
        return response()->json(['message' => 'کامنت حذف شد.']);
    }
}

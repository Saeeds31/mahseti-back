<?php

namespace Modules\Channel\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Http\Requests\ChannelUpdateRequest;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\ChannelAdminService;

class ChannelController extends Controller
{
    public function __construct(
        protected ChannelAdminService $service,
    ) {}

    /**
     * لیست همه کانال‌ها + آمار
     * GET /admin/channels
     */
    public function index()
    {
        $channels = $this->service->listChannels();

        return response()->json([
            'success' => true,
            'data'    => $channels,
        ]);
    }

    /**
     * جزئیات یک کانال
     * GET /admin/channels/{slug}
     */
    public function show(string $slug)
    {
        $channel = Channel::where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => $this->service->channelDetails($channel),
        ]);
    }

    /**
     * وصل/قطع اتصال
     * PUT /admin/channels/{slug}/toggle
     */
    public function toggle(string $slug)
    {
        $channel = Channel::where('slug', $slug)->firstOrFail();

        $channel->update([
            'is_connected' => !$channel->is_connected,
        ]);

        return response()->json([
            'success' => true,
            'message' => $channel->is_connected
                ? "اتصال {$channel->name} برقرار شد."
                : "اتصال {$channel->name} قطع شد.",
            'data' => $channel,
        ]);
    }

    /**
     * ویرایش تنظیمات (درصد، credentials، settings)
     * PUT /admin/channels/{slug}
     */
    public function update(ChannelUpdateRequest $request, string $slug)
    {
        $channel = Channel::where('slug', $slug)->firstOrFail();

        $channel->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تنظیمات با موفقیت ذخیره شد.',
            'data'    => $channel,
        ]);
    }
}

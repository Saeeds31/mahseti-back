<?php

namespace Modules\Channel\Http\Controllers\Torob;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\TorobProductService;

class TorobProductController extends Controller
{
    public function __construct(
        protected TorobProductService $service,
    ) {}

    /**
     * POST /channel/torob/v3/products
     * endpoint رسمی ترب برای دریافت محصولات
     */
    public function __invoke(Request $request)
    {
        $payload = $request->all();

        // ۱. اعتبارسنجی پارامترها طبق مستندات ترب
        $mode = $this->detectMode($payload);

        if ($mode === null) {
            return response()->json([
                'error' => 'Invalid request parameters.',
            ], 400);
        }

        // ۲. بارگذاری کانال ترب
        $channel = Channel::where('slug', 'torob')->firstOrFail();

        // ۳. ساخت پاسخ
        $response = match ($mode) {
            'page_urls'   => $this->service->fetchByPageUrls($channel, $payload['page_urls']),
            'page_uniques'=> $this->service->fetchByPageUniques($channel, $payload['page_uniques']),
            'cursor'      => $this->service->fetchByCursor($channel, $payload),
            'page'        => $this->service->fetchByPage($channel, $payload),
        };

        return response()->json($response);
    }

    /**
     * تشخیص حالت درخواست بر اساس پارامترها
     * طبق مستندات ترب
     */
    protected function detectMode(array $payload): ?string
    {
        if (!empty($payload['page_urls'])) {
            return 'page_urls';
        }

        if (!empty($payload['page_uniques'])) {
            return 'page_uniques';
        }

        if (!empty($payload['cursor'])) {
            if (empty($payload['sort'])) {
                return null;
            }
            return 'cursor';
        }

        if (isset($payload['page'])) {
            if (empty($payload['sort'])) {
                return null; // طبق مستندات: اگه sort نباشه 400
            }
            return 'page';
        }

        return null;
    }
}
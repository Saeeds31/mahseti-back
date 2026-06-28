<?php

// Modules/Orders/Enums/OrderStatus.php

namespace Modules\Orders\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';          // در انتظار پرداخت (برای سفارش‌های درگاهی)
    case RESERVED = 'reserved';        // رزرو شده (پرداخت کامل، اما در انتظار اضافه شدن سفارش‌های دیگر)
    case PROCESSING = 'processing';    // در حال پردازش (سفارش عادی یا بعد از انقضای رزرو)
    case COMPLETED = 'completed';      // تکمیل شده (تحویل داده شده)
    case CANCELLED = 'cancelled';      // لغو شده (توسط کاربر یا سیستم)
    case EXPIRED = 'expired';          // منقضی شده (رزرو به اتمام رسیده و تمدید نشده)
    case REFUNDED = 'refunded';        // برگشت خورده (وجه به کاربر برگشت داده شده)

    /**
     * دریافت عنوان فارسی وضعیت
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'در انتظار پرداخت',
            self::RESERVED => 'رزرو شده',
            self::PROCESSING => 'در حال پردازش',
            self::COMPLETED => 'تکمیل شده',
            self::CANCELLED => 'لغو شده',
            self::EXPIRED => 'منقضی شده',
            self::REFUNDED => 'برگشت خورده',
        };
    }

    /**
     * دریافت رنگ متناسب با وضعیت (برای فرانت‌اند)
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::RESERVED => 'info',
            self::PROCESSING => 'primary',
            self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
            self::EXPIRED => 'secondary',
            self::REFUNDED => 'dark',
        };
    }

    /**
     * دریافت آیکون متناسب با وضعیت
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::RESERVED => 'calendar-check',
            self::PROCESSING => 'spinner',
            self::COMPLETED => 'check-circle',
            self::CANCELLED => 'x-circle',
            self::EXPIRED => 'calendar-x',
            self::REFUNDED => 'arrow-return-left',
        };
    }

    /**
     * بررسی اینکه آیا سفارش قابل ویرایش است
     */
    public function isEditable(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::RESERVED,
        ]);
    }

    /**
     * بررسی اینکه آیا سفارش قابل لغو است
     */
    public function isCancellable(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::RESERVED,
            self::PROCESSING,
        ]);
    }

    /**
     * بررسی اینکه آیا می‌توان به این سفارش سفارش دیگری اضافه کرد
     */
    public function canAddToReservation(): bool
    {
        return $this === self::RESERVED;
    }

    /**
     * بررسی اینکه آیا سفارش نیاز به پرداخت دارد
     */
    public function needsPayment(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * بررسی اینکه آیا وضعیت نهایی است
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELLED,
            self::EXPIRED,
            self::REFUNDED,
        ]);
    }

    /**
     * گرفتن ترتیب گام‌های وضعیت
     */
    public function step(): int
    {
        return match ($this) {
            self::PENDING => 1,
            self::RESERVED => 2,
            self::PROCESSING => 3,
            self::COMPLETED => 4,
            default => 0,
        };
    }

    /**
     * دریافت لیست تمام وضعیت‌ها برای استفاده در فرانت‌اند
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function ($carry, $case) {
            $carry[$case->value] = [
                'label' => $case->label(),
                'color' => $case->color(),
                'icon' => $case->icon(),
                'editable' => $case->isEditable(),
                'cancellable' => $case->isCancellable(),
                'final' => $case->isFinal(),
                'step' => $case->step(),
            ];
            return $carry;
        }, []);
    }

    /**
     * دریافت وضعیت از روی مقدار
     */
    public static function fromValue(string $value): ?self
    {
        return match ($value) {
            'pending' => self::PENDING,
            'reserved' => self::RESERVED,
            'processing' => self::PROCESSING,
            'completed' => self::COMPLETED,
            'cancelled' => self::CANCELLED,
            'expired' => self::EXPIRED,
            'refunded' => self::REFUNDED,
            default => null,
        };
    }

    /**
     * دریافت وضعیت‌های قابل نمایش برای مشتری
     */
    public static function customerStatuses(): array
    {
        return [
            self::PENDING->value => self::PENDING->label(),
            self::RESERVED->value => self::RESERVED->label(),
            self::PROCESSING->value => self::PROCESSING->label(),
            self::COMPLETED->value => self::COMPLETED->label(),
            self::CANCELLED->value => self::CANCELLED->label(),
        ];
    }

    /**
     * دریافت وضعیت‌های قابل نمایش برای ادمین
     */
    public static function adminStatuses(): array
    {
        return array_reduce(self::cases(), function ($carry, $case) {
            $carry[$case->value] = $case->label();
            return $carry;
        }, []);
    }

    /**
     * دریافت وضعیت‌های قابل فیلتر برای گزارشات
     */
    public static function reportStatuses(): array
    {
        return [
            self::PROCESSING->value => self::PROCESSING->label(),
            self::COMPLETED->value => self::COMPLETED->label(),
            self::CANCELLED->value => self::CANCELLED->label(),
            self::REFUNDED->value => self::REFUNDED->label(),
        ];
    }
}

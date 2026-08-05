<!doctype html>
<html lang="fa">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>درگاه شبیه‌سازی‌شده</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="card mx-auto" style="max-width:680px;">
            <div class="card-body text-center">
                <h4 class="card-title mb-3">درگاه پرداخت (Fake)</h4>

                @if (session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif
                @if (session('info'))
                    <div class="alert alert-info">{{ session('info') }}</div>
                @endif

                <p>کاربر: {{ auth()->user()->name ?? 'کاربر' }}</p>
                <p>شماره تراکنش: <strong>{{ $transaction->transaction_id }}</strong></p>
                <p>مبلغ: <strong>{{ number_format($transaction->amount) }}</strong> تومان</p>

                <div class="d-flex justify-content-center gap-2 mt-4">
                    <!-- پرداخت موفق - هدایت به Callback واقعی -->
                    <form method="POST"
                        action="{{ route('payment.callback', ['gateway' => $transaction->gateway ?? 'zarinpal']) }}">
                        @csrf
                        <!-- پارامترهای مورد نیاز برای درگاه‌ها -->
                        <input type="hidden" name="trackId" value="FAKE_{{ time() }}">
                        <input type="hidden" name="Authority" value="FAKE_{{ time() }}">
                        <input type="hidden" name="Status" value="OK">
                        <input type="hidden" name="success" value="true">
                        <button class="btn btn-success px-4" type="submit">پرداخت موفق</button>
                    </form>

                    <!-- لغو پرداخت - هدایت به Callback واقعی -->
                    <form method="POST"
                        action="{{ route('payment.callback', ['gateway' => $transaction->gateway ?? 'zarinpal']) }}">
                        @csrf
                        <input type="hidden" name="Status" value="Canceled">
                        <input type="hidden" name="error" value="پرداخت توسط کاربر لغو شد">
                        <button class="btn btn-danger px-4" type="submit">لغو پرداخت</button>
                    </form>
                </div>

                <div class="mt-3 text-muted small">
                    <p>این صفحه فقط برای تست است — در محیط production غیرفعال شود.</p>
                </div>
            </div>
        </div>
    </div>
</body>

</html>

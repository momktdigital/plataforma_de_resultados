@if (session('status'))
    <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm flex items-start gap-2 shadow-sm">
        <i class="ph-fill ph-check-circle text-emerald-500 text-lg mt-0.5"></i>
        <span>{{ session('status') }}</span>
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm flex items-start gap-2 shadow-sm">
        <i class="ph-fill ph-warning-circle text-red-500 text-lg mt-0.5"></i>
        <ul class="list-disc pl-5 space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

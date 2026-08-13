@if (session('success'))
    <div class="mb-4 rounded-md bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-700 px-4 py-3 text-sm text-green-700 dark:text-green-300">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-700 px-4 py-3 text-sm text-red-700 dark:text-red-300">
        {{ session('error') }}
    </div>
@endif

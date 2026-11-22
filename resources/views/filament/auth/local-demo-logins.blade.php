<div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
    <p class="font-medium text-gray-950 dark:text-white">
        {{ __('Local development accounts') }}
    </p>

    <p class="mt-1 text-gray-500 dark:text-gray-400">
        {{ __('Password') }}: <code class="rounded bg-gray-200 px-1 py-0.5 text-xs dark:bg-white/10">{{ $password }}</code>
    </p>

    <ul class="mt-3 space-y-2">
        @foreach ($accounts as $account)
            <li>
                <button
                    type="button"
                    wire:click="selectDemoAccount(@js($account['email']))"
                    class="text-start text-primary-600 hover:underline focus-visible:underline dark:text-primary-400"
                >
                    <span class="font-medium">{{ $account['label'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">({{ $account['email'] }})</span>
                </button>
            </li>
        @endforeach
    </ul>
</div>

<div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-800/50">
    <p class="font-medium text-gray-950 dark:text-white">
        Local development accounts
    </p>

    <p class="mt-1 text-gray-500 dark:text-gray-400">
        Password: <code class="rounded bg-gray-200 px-1 py-0.5 text-xs dark:bg-gray-700">{{ $password }}</code>
        @if ($panelHasTenant)
            · Tenant: <code class="rounded bg-gray-200 px-1 py-0.5 text-xs dark:bg-gray-700">{{ $tenantSlug }}</code>
        @endif
    </p>

    <ul class="mt-3 space-y-2">
        @foreach ($accounts as $account)
            <li>
                <button
                    type="button"
                    wire:click="selectDemoAccount(@js($account['email']))"
                    class="text-start text-primary-600 hover:underline dark:text-primary-400"
                >
                    <span class="font-medium">{{ $account['label'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">({{ $account['email'] }})</span>
                </button>
            </li>
        @endforeach
    </ul>
</div>

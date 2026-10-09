<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Jev AI"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($models->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Model</x-pulse::th>
                        <x-pulse::th class="text-right">Calls</x-pulse::th>
                        <x-pulse::th class="text-right">Errors</x-pulse::th>
                        <x-pulse::th class="text-right">Tokens charged</x-pulse::th>
                        <x-pulse::th class="text-right">Credits</x-pulse::th>
                        <x-pulse::th class="text-right">Avg / max ms</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($models as $row)
                        <tr wire:key="jev-{{ $row->model }}" class="h-2 first:h-0"></tr>
                        <tr wire:key="jev-{{ $row->model }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $row->model }}">{{ $row->model }}</code>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->calls) }}</x-pulse::td>
                            <x-pulse::td numeric class="{{ $row->errors > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">{{ number_format($row->errors) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">{{ number_format($row->tokens) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($row->credits, 6), '0'), '.') }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">{{ number_format($row->avg) }} / {{ number_format($row->max) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>

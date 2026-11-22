<div wire:poll.{{ $pollSeconds }}s.keep-alive="heartbeat">
    @if ($viewers !== [])
        <x-filament::callout
            color="warning"
            icon="heroicon-o-eye"
            :heading="count($viewers) === 1
                ? __(':name is also viewing this ticket', ['name' => $viewers[0]])
                : __(':names and :last are also viewing this ticket', ['names' => implode(', ', array_slice($viewers, 0, -1)), 'last' => last($viewers)])"
            :description="__('Check the conversation before you reply so you do not answer twice.')"
        />
    @endif
</div>

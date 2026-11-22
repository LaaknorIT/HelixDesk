<div wire:poll.{{ $pollSeconds }}s.keep-alive="heartbeat">
    @if ($viewers !== [])
        <x-filament::callout
            color="warning"
            icon="heroicon-o-eye"
            :heading="count($viewers) === 1
                ? $viewers[0].' is also viewing this ticket'
                : implode(', ', array_slice($viewers, 0, -1)).' and '.last($viewers).' are also viewing this ticket'"
            description="Check the conversation before you reply so you do not answer twice."
        />
    @endif
</div>

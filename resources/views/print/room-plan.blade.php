<x-print.layout title="Derslik Planı">
    @foreach ($slotsByDay as $day => $slots)
        <div class="section">
            <h1>{{ \Carbon\Carbon::parse($day)->format('d.m.Y') }} — Derslik Planı</h1>
            <div class="meta">{{ $solution->label }}</div>

            <table>
                <thead>
                    <tr>
                        <th style="width:16%">Derslik</th>
                        @foreach ($slots as $slot)
                            <th>{{ substr($slot->starts_at, 0, 5) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($grid[$day] ?? [] as $roomName => $bySlot)
                        <tr>
                            <td><strong>{{ $roomName }}</strong></td>
                            @foreach ($slots as $slot)
                                @php($entry = $bySlot[$slot->id] ?? null)
                                <td>
                                    @if ($entry)
                                        <strong>{{ $entry->code }}</strong><br>
                                        {{ $entry->student_count }} öğrenci
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ $slots->count() + 1 }}">Bu gün için sınav yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</x-print.layout>

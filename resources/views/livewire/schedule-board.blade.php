<div>
    <div class="mb-5 flex flex-wrap items-end gap-3">
        <div>
            <h1 class="text-xl font-semibold">Program</h1>
            @if ($solution)
                <p class="text-sm text-slate-500">
                    {{ $solution->label }} · ceza puanı {{ number_format($solution->penalty) }}
                    @if ($solution->stats['unplaced_count'] ?? 0)
                        · <span class="text-amber-700">{{ $solution->stats['unplaced_count'] }} sınav yerleşemedi</span>
                    @endif
                </p>
            @endif
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($solution)
                <a href="{{ route('print.doors', $solution) }}" target="_blank"
                   class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Kapı listesi</a>
                <a href="{{ route('print.rooms', $solution) }}" target="_blank"
                   class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Derslik planı</a>
            @endif
        </div>
    </div>

    @if (! $solution)
        <div class="rounded border border-slate-200 bg-white p-8 text-center text-slate-500">
            Henüz tamamlanmış bir çözüm yok.
            @can('manage-solutions')
                <a href="{{ route('solve') }}" class="text-slate-900 underline">Program üret</a>.
            @endcan
        </div>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-2">
            @foreach ($days as $d)
                <button wire:click="$set('day', '{{ $d }}')"
                        class="rounded px-3 py-1.5 text-sm {{ $day === $d ? 'bg-slate-900 text-white' : 'border border-slate-300 hover:bg-slate-100' }}">
                    {{ \Carbon\Carbon::parse($d)->translatedFormat('d.m.Y') }}
                </button>
            @endforeach

            <select wire:model.live="department"
                    class="ml-auto rounded border border-slate-300 px-3 py-1.5 text-sm">
                <option value="">Tüm bölümler</option>
                @foreach ($departments as $d)
                    <option value="{{ $d }}">{{ $d }}</option>
                @endforeach
            </select>

            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Ders kodu, ad veya hoca"
                   class="w-64 rounded border border-slate-300 px-3 py-1.5 text-sm">

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input wire:model.live="showEmptyRooms" type="checkbox"
                       class="rounded border-slate-300">
                Boş derslikleri de göster
            </label>
        </div>

        <div class="overflow-x-auto rounded border border-slate-200 bg-white">
            <table class="w-full border-collapse text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="sticky left-0 z-10 w-40 border-b border-slate-200 bg-slate-50 px-3 py-2">Derslik</th>
                        @foreach ($slots as $slot)
                            <th class="border-b border-l border-slate-200 px-3 py-2 font-medium">
                                {{ substr($slot->starts_at, 0, 5) }}
                                <span class="block text-xs font-normal text-slate-400">{{ $slot->index_in_day }}. oturum</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rooms as $room)
                        <tr class="align-top">
                            <th class="sticky left-0 z-10 border-b border-slate-100 bg-white px-3 py-2 text-left font-medium">
                                {{ $room->name }}
                                <span class="block text-xs font-normal text-slate-400">{{ $room->capacity }} kişilik</span>
                            </th>

                            @foreach ($slots as $slot)
                                @php($entry = $grid[$room->id][$slot->id] ?? null)
                                <td class="border-b border-l border-slate-100 px-3 py-2">
                                    @if ($entry)
                                        <div class="font-medium">{{ $entry->code }}</div>
                                        <div class="text-xs text-slate-500">{{ $entry->course_name }}</div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            {{ $entry->student_count }} öğrenci
                                            @if ($entry->lecturer_name) · {{ $entry->lecturer_name }} @endif
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $slots->count() + 1 }}" class="px-3 py-8 text-center text-slate-500">
                                Bu gün ve filtreyle gösterilecek sınav yok.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>

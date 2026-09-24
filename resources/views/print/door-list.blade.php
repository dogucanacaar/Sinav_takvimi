<x-print.layout title="Kapı Listesi">
    @foreach ($entries as $entry)
        <div class="section">
            <h1>{{ $entry->course_name }}</h1>
            <div class="meta">
                {{ $entry->code }} ·
                {{ \Carbon\Carbon::parse($entry->day)->format('d.m.Y') }} {{ substr($entry->starts_at, 0, 5) }} ·
                {{ $entry->duration_min }} dakika ·
                {{ $entry->building_name }} / {{ $entry->room_name }} ·
                {{ $entry->student_count }} öğrenci
                @if ($entry->lecturer_name) · Sorumlu: {{ $entry->lecturer_name }} @endif
            </div>

            <h2>Öğrenci numaraları</h2>
            <div class="numbers">
                @foreach ($students[$entry->exam_id] ?? [] as $student)
                    <div>{{ $student->number }}</div>
                @endforeach
            </div>

            <table class="sign">
                <tr>
                    <td style="width:50%">Gözetmen imza: ..............................</td>
                    <td>Katılan öğrenci sayısı: ..............................</td>
                </tr>
            </table>
        </div>
    @endforeach

    @if ($entries->isEmpty())
        <p>Bu çözümde yazdırılacak sınav yok.</p>
    @endif
</x-print.layout>

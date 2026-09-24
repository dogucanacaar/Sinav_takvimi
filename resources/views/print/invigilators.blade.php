<x-print.layout title="Gözetmen Çizelgesi">
    @foreach ($duties as $name => $rows)
        <div class="section">
            <h1>{{ $rows->first()->title }} {{ $name }}</h1>
            <div class="meta">
                {{ $rows->first()->department }} · {{ $rows->count() }} görev ·
                {{ $solution->label }}
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:18%">Tarih</th>
                        <th style="width:12%">Saat</th>
                        <th style="width:18%">Derslik</th>
                        <th>Ders</th>
                        <th style="width:12%">Süre</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $duty)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($duty->day)->format('d.m.Y') }}</td>
                            <td>{{ substr($duty->starts_at, 0, 5) }}</td>
                            <td>{{ $duty->room_name }}</td>
                            <td>{{ $duty->code }} — {{ $duty->course_name }}</td>
                            <td>{{ $duty->duration_min }} dk</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="sign">
                <tr>
                    <td style="width:50%">Tebellüğ eden: ..............................</td>
                    <td>Tarih: ..............................</td>
                </tr>
            </table>
        </div>
    @endforeach

    @if ($duties->isEmpty())
        <p>Bu çözümde gözetmen ataması yok.</p>
    @endif
</x-print.layout>

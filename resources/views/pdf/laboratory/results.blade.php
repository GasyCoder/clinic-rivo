{{--
    ADR-218 — le compte rendu de résultats d'analyses (PDF, dompdf), sur le
    modèle du laboratoire de la clinique (labo-vuejs). Tout est composé par
    App\Services\Laboratory\LabResultReport : cette vue ne décide rien, et
    n'écrit que du texte échappé.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Résultats d’analyses — {{ $report['patient']['name'] }}</title>
<style>
    @page { size: A4; margin: 12mm 14mm 16mm 14mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; color: #111; line-height: 1.25; margin: 0; }
    .accent { color: {{ $report['site']['color'] }}; }
    .line { border-top: 0.8pt solid {{ $report['site']['color'] }}; }
    #footer { position: fixed; bottom: -10mm; left: 0; right: 0; text-align: center; font-size: 7pt; color: #9ca3af; }
    .brand { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
    .brand td { vertical-align: middle; padding: 0; }
    .brand img { max-height: 58pt; max-width: 200pt; }
    .brand .name { font-size: 13pt; font-weight: bold; }
    .brand .sub { font-size: 7.5pt; color: #4b5563; }
    .legal { font-size: 7pt; color: #4b5563; padding-bottom: 3pt; }
    .banner { margin: 6pt 0 0; padding: 4pt 6pt; border: 0.8pt solid #b45309; color: #92400e; font-size: 7.6pt; font-weight: bold; text-align: center; }
    .patient { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; border-bottom: 0.5pt solid #d1d5db; }
    .patient td { width: 50%; vertical-align: top; padding: 0 0 6pt; font-size: 8.2pt; line-height: 1.45; }
    .label { color: #4b5563; }
    .strong { font-weight: bold; }
    .big { font-size: 10pt; font-weight: bold; }
    table.results { width: 100%; border-collapse: collapse; margin: 0 0 12pt; }
    table.results thead td { padding: 0 0 2pt; }
    .section { font-size: 10pt; font-weight: bold; text-transform: uppercase; }
    .cols { font-size: 7.6pt; font-style: italic; color: #4b5563; }
    .sep td { padding: 0 0 3pt; }
    .c-des { width: 42%; } .c-res { width: 22%; } .c-ref { width: 19%; } .c-ant { width: 17%; }
    table.results tbody td { padding: 1.4pt 0; vertical-align: top; }
    tr { page-break-inside: avoid; }
    .item-title td { font-weight: bold; font-size: 9.2pt; padding-top: 4pt !important; }
    .heading { font-weight: bold; }
    .bold { font-weight: bold; }
    .ref, .ant { font-size: 7.6pt; color: #374151; }
    .ant { color: #9ca3af; }
    .critical { color: #b91c1c; font-weight: bold; }
    .flag { font-size: 7pt; }
    .note-label { font-size: 7.6pt; font-style: italic; color: #6b7280; }
    .note-text { font-size: 7.8pt; font-style: italic; color: #374151; }
    .abg-title { font-size: 8pt; padding-top: 3pt !important; }
    .abg-line { font-size: 7.8pt; }
    .heading.plain { font-weight: normal; }
    .conclusion { margin: 2pt 0 4pt; padding: 3pt 6pt; border-left: 1.6pt solid {{ $report['site']['color'] }}; background: #f8fafc; font-size: 8pt; }
    .conclusion .title { font-weight: bold; font-size: 7.6pt; text-transform: uppercase; color: #374151; }
    .text { white-space: pre-line; font-size: 8.2pt; }
    .muted { color: #6b7280; font-size: 7.4pt; }
    .general { page-break-inside: avoid; margin-top: 4pt; padding: 5pt 8pt; border: 0.6pt solid #d1d5db; }
    .general .title { font-weight: bold; font-size: 8pt; text-transform: uppercase; margin-bottom: 2pt; }
    .closing { page-break-inside: avoid; margin-top: 10pt; }
    .identity { border-top: 0.5pt solid #e5e7eb; padding-top: 2pt; text-align: center; font-size: 7.4pt; color: #1e3a8a; }
    .sign { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    .sign td { width: 50%; vertical-align: top; font-size: 8pt; }
    .sign .box { height: 38pt; }
</style>
</head>
<body>
@php
    $site = $report['site'];
    $patient = $report['patient'];
    $req = $report['request'];
    $pad = fn (int $depth) => 'padding-left: '.(max(0, $depth) * 11).'pt;';
@endphp

<div id="footer">
    {{ $site['brand'] }}@if($site['site']) — {{ $site['site'] }}@endif · {{ $patient['name'] }} · Dossier n° {{ $patient['number'] }}
</div>

<table class="brand">
    <tr>
        @if($site['logo'])
            <td style="width: 45%"><img src="{{ $site['logo'] }}" alt=""></td>
        @endif
        <td style="text-align: {{ $site['logo'] ? 'right' : 'left' }}">
            <div class="name accent">{{ $site['brand'] }}</div>
            <div class="sub">Laboratoire d’analyses médicales{{ $site['site'] ? ' — '.$site['site'] : '' }}</div>
            @if($site['address'] || $site['phone'])
                <div class="sub">{{ collect([$site['address'], $site['phone'] ? 'Tél : '.$site['phone'] : null, $site['email']])->filter()->implode(' · ') }}</div>
            @endif
        </td>
    </tr>
</table>
@if($site['nif'] || $site['stat'])
    <div class="legal">@if($site['nif'])NIF : {{ $site['nif'] }}@endif @if($site['nif'] && $site['stat']) &nbsp;·&nbsp; @endif @if($site['stat'])STAT : {{ $site['stat'] }}@endif</div>
@endif
<div class="line"></div>

@if($report['provisional'])
    <div class="banner">Document provisoire — au moins un résultat n’est pas encore envoyé au médecin.</div>
@endif

<table class="patient">
    <tr>
        <td>
            <span class="label">Résultats de :</span><br>
            <span class="big">{{ $patient['name'] }}</span><br>
            @if($patient['birth_date'])
                <span class="label">Date de naissance :</span> {{ $patient['birth_date'] }}@if($patient['age'] !== null) ({{ $patient['age'] }} ans)@endif<br>
            @elseif($patient['age'] !== null)
                <span class="label">Âge :</span> {{ $patient['age'] }} ans<br>
            @endif
            @if($patient['sex'])<span class="label">Sexe :</span> {{ $patient['sex'] }}<br>@endif
            <span class="label">Dossier n° :</span> {{ $patient['number'] }}@if($patient['since']) du {{ $patient['since'] }}@endif<br>
            <span class="label">Prescription :</span> {{ $req['lab_number'] ?? $req['episode_number'] }}@if($req['requested_at']) du {{ $req['requested_at'] }}@endif<br>
            @if($req['prescriber'])<span class="label">Prescrit par :</span> <span class="strong">{{ $req['prescriber'] }}</span>@endif
        </td>
        <td>
            <span class="strong">{{ $patient['name'] }}</span><br>
            <span class="label">Dossier n° :</span> {{ $patient['number'] }} · passage {{ $req['episode_number'] }}<br>
            @if($req['lab_number'])<span class="label">N° laboratoire :</span> <span class="strong">{{ $req['lab_number'] }}</span><br>@endif
            @if($req['recipient'])<span class="label">Adressés à :</span> {{ $req['recipient'] }}<br>@endif
            @if($req['clinical_notes'])<span class="label">Renseignement clinique :</span> {{ $req['clinical_notes'] }}@endif
        </td>
    </tr>
</table>

@forelse($report['sections'] as $section)
    <table class="results">
        <thead>
            <tr>
                <td class="c-des section accent">{{ $section['title'] }}</td>
                <td class="c-res cols">Résultat</td>
                <td class="c-ref cols">Val. réf.</td>
                <td class="c-ant cols">Antériorité</td>
            </tr>
            <tr class="sep"><td colspan="4"><div class="line"></div></td></tr>
        </thead>
        <tbody>
        @foreach($section['items'] as $item)
            @if($item['title_row'])
                <tr class="item-title">
                    <td colspan="4">{{ $item['name'] }}@if($item['sent_out'])<span class="muted"> — réalisée par {{ $item['sent_out'] }}</span>@endif</td>
                </tr>
            @elseif($item['sent_out'])
                <tr><td colspan="4" class="muted">{{ $item['name'] }} — réalisée par {{ $item['sent_out'] }}</td></tr>
            @endif

            @if($item['text'] !== null)
                <tr><td colspan="4" class="text" style="{{ $pad(1) }}">{{ $item['text'] }}</td></tr>
            @endif

            @foreach($item['rows'] as $row)
                @switch($row['kind'])
                    @case('heading')
                        <tr><td colspan="4" class="heading {{ $row['bold'] ? 'bold' : 'plain' }}" style="{{ $pad($row['depth']) }}">{{ $row['designation'] }}</td></tr>
                        @break
                    @case('result')
                        <tr>
                            <td class="c-des {{ $row['bold'] ? 'bold' : '' }}" style="{{ $pad($row['depth']) }}">{{ $row['designation'] }}</td>
                            <td class="c-res {{ $row['pathological'] || $row['critical'] ? 'bold' : '' }} {{ $row['critical'] ? 'critical' : '' }}">
                                {{ $row['value'] }}@if($row['flag'] === 'HIGH')<span class="flag"> ↑</span>@elseif($row['flag'] === 'LOW')<span class="flag"> ↓</span>@endif
                                @if($row['critical'])<br><span class="flag">Valeur critique</span>@endif
                            </td>
                            <td class="c-ref ref">{{ $row['reference'] }}</td>
                            <td class="c-ant ant">{{ $row['anteriority'] }}</td>
                        </tr>
                        @break
                    @case('antibiogram')
                        <tr><td colspan="4" class="abg-title" style="{{ $pad($row['depth']) }}">Antibiogramme de <i>{{ $row['bacterium'] }}</i></td></tr>
                        @forelse($row['lines'] as $line)
                            <tr class="abg-line">
                                <td style="{{ $pad($row['depth'] + 1) }}">{{ $line['antibiotic'] }}</td>
                                <td class="{{ $line['resistant'] ? 'bold' : '' }}" @if($line['intermediate']) style="font-style: italic" @endif>{{ $line['label'] }}</td>
                                <td class="ref">{{ $line['measure'] }}</td>
                                <td></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted" style="{{ $pad($row['depth'] + 1) }}">Aucun antibiotique testé.</td></tr>
                        @endforelse
                        @if($row['notes'])
                            <tr>
                                <td class="note-label" style="{{ $pad($row['depth'] + 1) }}">Notes :</td>
                                <td colspan="3" class="note-text">{{ $row['notes'] }}</td>
                            </tr>
                        @endif
                        @break
                    @case('note')
                        <tr>
                            <td class="note-label" style="{{ $pad($row['depth']) }}">Notes :</td>
                            <td colspan="3" class="note-text">{!! nl2br(e($row['text'])) !!}</td>
                        </tr>
                        @break
                @endswitch
            @endforeach

            @if($item['in_correction'])
                <tr><td colspan="4" class="critical" style="{{ $pad(1) }}">Repris par le laboratoire pour être refait{{ is_string($item['in_correction']) ? ' ('.$item['in_correction'].')' : '' }} : ne vous fiez pas à cette valeur, un nouvel envoi suivra.</td></tr>
            @endif
            @if($item['provisional'])
                <tr><td colspan="4" class="muted" style="{{ $pad(1) }}">Résultat provisoire : pas encore envoyé au médecin.</td></tr>
            @endif
        @endforeach
        </tbody>
    </table>
@empty
    <p class="muted">Aucun résultat à imprimer pour cette demande.</p>
@endforelse

@if(filled($report['conclusion']))
    <div class="general">
        <div class="title accent">Conclusion générale</div>
        {!! nl2br(e($report['conclusion'])) !!}
    </div>
@endif

<div class="closing">
    <div class="identity">{{ $patient['name'] }} — Dossier n° {{ $patient['number'] }}@if($req['lab_number']) — {{ $req['lab_number'] }}@endif</div>
    <table class="sign">
        <tr>
            <td class="muted">
                @if($report['validation']['by'])
                    Résultats envoyés au médecin par {{ implode(', ', $report['validation']['by']) }}@if($report['validation']['at']) le {{ $report['validation']['at'] }}@endif.<br>
                @endif
                @if(! empty($report['approval']['by']))
                    <span class="strong">Résultats validés par {{ implode(', ', $report['approval']['by']) }}@if($report['approval']['at']) le {{ $report['approval']['at'] }}@endif.</span><br>
                @endif
                @if(! empty($report['approval']['awaiting']))
                    {{ $report['approval']['awaiting'] }} résultat(s) en attente de validation par le médecin.<br>
                @endif
                Édité le {{ $report['generated_at'] }}.
            </td>
            <td style="text-align: right">
                <span class="strong">Le responsable du laboratoire</span>
                <div class="box"></div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>

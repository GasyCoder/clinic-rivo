{{--
    ADR-218 — le compte rendu de résultats d'analyses (PDF, dompdf), sur le
    modèle du laboratoire de la clinique (labo-vuejs). Tout est composé par
    App\Services\Laboratory\LabResultReport : cette vue ne décide rien, et
    n'écrit que du texte échappé.

    ADR-223 — son aspect (modèle, police, couleurs, en-tête, QR, colonnes, bloc
    final, pied de page) suit `$report['design']`, réglé par site depuis le
    portail (LabReportDesign). Un compte rendu composé sans réglage garde
    l'aspect d'origine.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Résultats d’analyses — {{ $report['patient']['name'] }}</title>
@php
    $site = $report['site'];
    $patient = $report['patient'];
    $req = $report['request'];
    $design = $report['design'] ?? \App\Support\Laboratory\LabReportDesign::build([], $site['brand'], $site['site'], $site['color'] ?? null);
    $inkOn = fn (string $background): string => \App\Support\Laboratory\LabReportDesign::inkOn($background);

    // Le modèle : Classique (logo à gauche, filet de couleur), Bandeau (l'établissement
    // sur un bandeau de couleur), Sobre (centré, filets gris, peu d'encre).
    $banner = $design['template'] === 'BANNER';
    $minimal = $design['template'] === 'MINIMAL';
    $accent = $design['accent'];
    $ink = $design['text'];
    $strong = $minimal ? $ink : $accent; // le nom, le titre, les sections, la conclusion
    $rule = $minimal ? '0.6pt solid #d1d5db' : '0.8pt solid '.$accent;

    $scale = $design['font_size'] / 100;
    $pt = fn (float $size): string => rtrim(rtrim(number_format($size * $scale, 2, '.', ''), '0'), '.').'pt';
    $font = $design['font'] === 'SERIF' ? "'DejaVu Serif', serif" : "'DejaVu Sans', sans-serif";

    $cols = $design['show_anteriority'] ? 4 : 3;
    $logo = $design['show_logo'] ? ($site['logo'] ?? null) : null;
    $qr = $design['show_qr'] ? ($report['qr'] ?? null) : null;
    $sectionInk = $design['section_background'] ? $inkOn($design['section_background']) : null;
    $patientInk = $design['patient_background'] ? $inkOn($design['patient_background']) : null;
    $patientLabel = $patientInk === '#FFFFFF' ? '#E5E7EB' : '#4b5563';
    $bannerInk = $inkOn($accent);
    $signatories = $report['signatories'] ?? [['label' => $design['lab_signatory'], 'name' => null]];

    $contacts = $design['show_contacts']
        ? collect([$site['address'] ?? null, filled($site['phone'] ?? null) ? 'Tél : '.$site['phone'] : null, $site['email'] ?? null])->filter()->implode(' · ')
        : '';
    $legal = $design['show_legal']
        ? collect([filled($site['nif'] ?? null) ? 'NIF : '.$site['nif'] : null, filled($site['stat'] ?? null) ? 'STAT : '.$site['stat'] : null])->filter()->implode(' · ')
        : '';
    $footer = collect([
        $design['footer_text'],
        $design['show_footer_patient'] ? $patient['name'] : null,
        $design['show_footer_patient'] ? 'Dossier n° '.$patient['number'] : null,
        $design['website'],
    ])->filter()->implode(' · ');
    $years = fn (int $age): string => $age.($age > 1 ? ' ans' : ' an');

    // Lignes alternées : un léger retrait garde le texte hors du bord de la bande.
    $inset = $design['zebra'] ? 3 : 0;
    $pad = fn (int $depth) => 'padding-left: '.(max(0, $depth) * 11 + $inset).'pt;';
    $stripe = 0;
    $zebra = function () use (&$stripe, $design): string {
        $stripe++;

        return $design['zebra'] && $stripe % 2 === 0 ? 'zebra' : '';
    };

    // La mise en page choisie par LabResultReport::render : resserrée, ou un saut de
    // page avant une ligne (ou une section) pour que le bloc final ne parte pas seul.
    $layout = array_merge(['compact' => false, 'break_before' => null, 'break_section' => null], $layout ?? []);
    $rowNo = 0;
    $sectionIndex = 0;
    $itemName = '';
    $itemIndex = 0;
    $itemFirstRow = 0;
    // Chaque ligne de résultats est numérotée et typée : le rendu relit sur quelle page
    // elle tombe. Le saut forcé au milieu d'une analyse reprend son nom (« (suite) »).
    $tr = function (string $kind, string $class = '') use (&$rowNo, &$sectionIndex, &$itemName, &$itemIndex, &$itemFirstRow, $layout, $cols): string {
        $rowNo++;
        $attributes = ' data-row="'.$rowNo.'" data-kind="'.$kind.'" data-section="'.$sectionIndex.'" data-item="'.$itemIndex.'"'.($class !== '' ? ' class="'.$class.'"' : '');
        if ($rowNo !== $layout['break_before']) {
            return '<tr'.$attributes.'>';
        }
        if ($rowNo === $itemFirstRow) {
            return '<tr'.$attributes.' style="page-break-before: always">';
        }

        return '<tr class="cont" style="page-break-before: always"><td colspan="'.$cols.'">'.e($itemName).' <span class="muted">(suite)</span></td></tr><tr'.$attributes.'>';
    };
@endphp
<style>
    @page { size: A4; margin: 12mm 14mm 16mm 14mm; }
    * { box-sizing: border-box; }
    body { font-family: {!! $font !!}; font-size: {{ $pt(8.6) }}; color: {{ $ink }}; line-height: 1.25; margin: 0; }
    .accent { color: {{ $strong }}; }
    .line { border-top: {{ $rule }}; }
    #footer { position: fixed; bottom: -10mm; left: 0; right: 0; text-align: center; font-size: {{ $pt(7) }}; color: #9ca3af; }
    .brand { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
    .brand td { vertical-align: middle; padding: 0; }
    .brand img { max-height: 58pt; max-width: 200pt; }
    .brand .name { font-size: {{ $pt(13) }}; font-weight: bold; }
    .brand .sub { font-size: {{ $pt(7.5) }}; color: #4b5563; }
    .brand.band { background: {{ $accent }}; margin-bottom: 5pt; }
    .brand.band td { padding: 7pt 9pt; color: {{ $bannerInk }}; }
    .brand.band .name, .brand.band .sub { color: {{ $bannerInk }}; }
    .brand.band .logo-card { display: inline-block; background: #ffffff; padding: 4pt 5pt; }
    .brand.band img { max-height: 46pt; max-width: 170pt; }
    .brand.centered td { text-align: center; }
    .brand.centered img { max-height: 44pt; margin-bottom: 3pt; }
    .legal { font-size: {{ $pt(7) }}; color: #4b5563; padding-bottom: 3pt; }
    .centered-legal { text-align: center; }
    .doc-title { margin: 7pt 0 1pt; text-align: center; font-size: {{ $pt(11) }}; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; }
    .banner { margin: 6pt 0 0; padding: 4pt 6pt; border: 0.8pt solid #b45309; color: #92400e; font-size: {{ $pt(7.6) }}; font-weight: bold; text-align: center; }
    .patient { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; border-bottom: 0.5pt solid #d1d5db; }
    .patient td { width: 50%; vertical-align: top; padding: 0 0 6pt; font-size: {{ $pt(8.2) }}; line-height: 1.45; }
    .patient.with-qr td { width: 43%; }
    .patient.with-qr td.qr { width: 14%; text-align: right; }
    .patient td.qr img { width: 54pt; height: 54pt; }
    .qr-caption { font-size: {{ $pt(6.4) }}; color: #6b7280; text-align: right; }
    @if($design['patient_background'])
        .patient { background: {{ $design['patient_background'] }}; border-bottom: none; }
        .patient td { padding: 5pt 7pt; color: {{ $patientInk }}; }
        .patient .label, .patient .qr-caption { color: {{ $patientLabel }}; }
    @endif
    .label { color: #4b5563; }
    .pipe { color: #9ca3af; }
    .strong { font-weight: bold; }
    .big { font-size: {{ $pt(10) }}; font-weight: bold; }
    table.results { width: 100%; border-collapse: collapse; margin: 0 0 12pt; }
    table.results thead td { padding: 0 0 2pt; }
    .section { font-size: {{ $pt(10) }}; font-weight: bold; text-transform: uppercase; }
    .cols { font-size: {{ $pt(7.6) }}; font-style: italic; color: #4b5563; }
    @if($design['section_background'])
        table.results thead tr.band td { background: {{ $design['section_background'] }}; padding: 2.5pt 4pt; }
        table.results thead tr.band td.section { color: {{ $sectionInk }}; }
        table.results thead tr.band td.cols { color: {{ $sectionInk }}; }
    @endif
    .sep td { padding: 0 0 3pt; }
    @if($cols === 4)
        .c-des { width: 42%; } .c-res { width: 22%; } .c-ref { width: 19%; } .c-ant { width: 17%; }
    @else
        .c-des { width: 48%; } .c-res { width: 28%; } .c-ref { width: 24%; }
    @endif
    table.results tbody td { padding: 1.4pt {{ $inset }}pt; vertical-align: top; }
    tr.zebra td { background: #f3f4f6; }
    tr { page-break-inside: avoid; }
    .item-title td { font-weight: bold; font-size: {{ $pt(9.2) }}; padding-top: 4pt !important; }
    .heading { font-weight: bold; }
    .bold { font-weight: bold; }
    .ref, .ant { font-size: {{ $pt(7.6) }}; color: #374151; }
    .ant { color: #9ca3af; }
    .abnormal { color: {{ $design['abnormal'] ?? 'inherit' }}; }
    .critical { color: #b91c1c; font-weight: bold; }
    .flag { font-size: {{ $pt(7) }}; }
    .note-label { font-size: {{ $pt(7.6) }}; font-style: italic; color: #6b7280; }
    .note-text { font-size: {{ $pt(7.8) }}; font-style: italic; color: #374151; }
    .abg-title { font-size: {{ $pt(8) }}; padding-top: 3pt !important; }
    .abg-line { font-size: {{ $pt(7.8) }}; }
    .heading.plain { font-weight: normal; }
    .text { white-space: pre-line; font-size: {{ $pt(8.2) }}; }
    .muted { color: #6b7280; font-size: {{ $pt(7.4) }}; }
    .general { page-break-inside: avoid; margin-top: 4pt; padding: 5pt 8pt; border: 0.6pt solid #d1d5db; }
    .general .title { font-weight: bold; font-size: {{ $pt(8) }}; text-transform: uppercase; margin-bottom: 2pt; }
    .closing { page-break-inside: avoid; margin-top: 10pt; }
    .identity { border-top: 0.5pt solid #e5e7eb; padding-top: 2pt; text-align: center; font-size: {{ $pt(7.4) }}; color: {{ $minimal ? '#4b5563' : $accent }}; }
    .sign { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    .sign td { vertical-align: top; font-size: {{ $pt(8) }}; }
    .sign td.meta { width: 64%; padding-right: 10pt; }
    .sign td.signature { width: 36%; }
    .sign td.half { width: 50%; padding-top: 6pt; }
    .sign .box { height: 38pt; }
    .cont td { font-weight: bold; font-size: {{ $pt(9.2) }}; padding-top: 2pt !important; }

    /*
        Resserré : utilisé seulement quand le bloc final ne tiendrait pas sous les
        derniers résultats (LabResultReport::render) — le compte rendu garde alors
        une page de moins plutôt que d'en ouvrir une pour la seule signature.
    */
    body.compact { font-size: {{ $pt(8.2) }}; line-height: 1.18; }
    .compact .brand img { max-height: 46pt; }
    .compact .patient { margin: 4pt 0 6pt; }
    .compact .patient td { line-height: 1.3; padding-bottom: 4pt; }
    .compact table.results { margin-bottom: 7pt; }
    .compact table.results tbody td { padding-top: 0.6pt; padding-bottom: 0.6pt; }
    .compact .item-title td { padding-top: 2pt !important; }
    .compact .closing { margin-top: 5pt; }
    .compact .sign { margin-top: 4pt; }
    .compact .sign .box { height: 28pt; }
</style>
</head>
<body class="{{ $layout['compact'] ? 'compact' : '' }}">

@if($design['show_footer'] && $footer !== '')
    <div id="footer">{{ $footer }}</div>
@endif

@if($banner)
    <table class="brand band">
        <tr>
            @if($logo)
                <td style="width: 40%"><span class="logo-card"><img src="{{ $logo }}" alt=""></span></td>
            @endif
            <td style="text-align: {{ $logo ? 'right' : 'left' }}">
                <div class="name">{{ $design['heading'] }}</div>
                <div class="sub">{{ $design['subheading'] }}</div>
                @if($contacts !== '')<div class="sub">{{ $contacts }}</div>@endif
            </td>
        </tr>
    </table>
    @if($legal !== '')<div class="legal">{{ $legal }}</div>@endif
@elseif($minimal)
    <table class="brand centered">
        <tr>
            <td>
                @if($logo)<img src="{{ $logo }}" alt=""><br>@endif
                <div class="name accent">{{ $design['heading'] }}</div>
                <div class="sub">{{ $design['subheading'] }}</div>
                @if($contacts !== '')<div class="sub">{{ $contacts }}</div>@endif
            </td>
        </tr>
    </table>
    @if($legal !== '')<div class="legal centered-legal">{{ $legal }}</div>@endif
    <div class="line"></div>
@else
    <table class="brand">
        <tr>
            @if($logo)
                <td style="width: 45%"><img src="{{ $logo }}" alt=""></td>
            @endif
            <td style="text-align: {{ $logo ? 'right' : 'left' }}">
                <div class="name accent">{{ $design['heading'] }}</div>
                <div class="sub">{{ $design['subheading'] }}</div>
                @if($contacts !== '')<div class="sub">{{ $contacts }}</div>@endif
            </td>
        </tr>
    </table>
    @if($legal !== '')<div class="legal">{{ $legal }}</div>@endif
    <div class="line"></div>
@endif

@if(filled($design['title']))
    <div class="doc-title accent">{{ $design['title'] }}</div>
@endif

@if(! empty($report['sample']))
    <div class="banner">Aperçu — patient et résultats fictifs, pour régler le compte rendu.</div>
@elseif($report['provisional'])
    <div class="banner">Document provisoire — au moins un résultat n’est pas encore envoyé au médecin.</div>
@endif

<table class="patient{{ $qr ? ' with-qr' : '' }}">
    <tr>
        <td>
            <span class="label">Résultats de :</span><br>
            <span class="big">{{ $patient['name'] }}</span><br>
            @if($patient['birth_date'])
                <span class="label">Date de naissance :</span> {{ $patient['birth_date'] }}<br>
            @endif
            @if($patient['age'] !== null || $patient['sex'])
                @if($patient['age'] !== null)<span class="label">Âge :</span> {{ $years((int) $patient['age']) }}@endif{!! $patient['age'] !== null && $patient['sex'] ? '<span class="pipe"> | </span>' : '' !!}@if($patient['sex'])<span class="label">Sexe :</span> {{ $patient['sex'] }}@endif<br>
            @endif
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
        @if($qr)
            <td class="qr">
                <img src="{{ $qr }}" alt="">
                <div class="qr-caption">{{ $req['lab_number'] ?? $req['episode_number'] }}</div>
            </td>
        @endif
    </tr>
</table>

@forelse($report['sections'] as $section)
    @php $sectionIndex = $loop->index; $stripe = 0; @endphp
    <table class="results" @if($layout['break_section'] === $loop->index) style="page-break-before: always" @endif>
        <thead>
            <tr @if($design['section_background']) class="band" @endif>
                <td class="c-des section accent">{{ $section['title'] }}</td>
                <td class="c-res cols">Résultat</td>
                <td class="c-ref cols">Val. réf.</td>
                @if($cols === 4)<td class="c-ant cols">Antériorité</td>@endif
            </tr>
            @unless($design['section_background'])
                <tr class="sep"><td colspan="{{ $cols }}"><div class="line"></div></td></tr>
            @endunless
        </thead>
        <tbody>
        @foreach($section['items'] as $item)
            @php $itemName = $item['name']; $itemIndex++; $itemFirstRow = $rowNo + 1; @endphp
            @if($item['title_row'])
                {!! $tr('title', 'item-title') !!}
                    <td colspan="{{ $cols }}">{{ $item['name'] }}@if($item['sent_out'])<span class="muted"> — réalisée par {{ $item['sent_out'] }}</span>@endif</td>
                </tr>
            @elseif($item['sent_out'])
                {!! $tr('title') !!}<td colspan="{{ $cols }}" class="muted">{{ $item['name'] }} — réalisée par {{ $item['sent_out'] }}</td></tr>
            @endif

            @if($item['text'] !== null)
                {!! $tr('text') !!}<td colspan="{{ $cols }}" class="text" style="{{ $pad(1) }}">{{ $item['text'] }}</td></tr>
            @endif

            @foreach($item['rows'] as $row)
                @switch($row['kind'])
                    @case('heading')
                        {!! $tr('heading') !!}<td colspan="{{ $cols }}" class="heading {{ $row['bold'] ? 'bold' : 'plain' }}" style="{{ $pad($row['depth']) }}">{{ $row['designation'] }}</td></tr>
                        @break
                    @case('result')
                        {!! $tr('result', $zebra()) !!}
                            <td class="c-des {{ $row['bold'] ? 'bold' : '' }}" style="{{ $pad($row['depth']) }}">{{ $row['designation'] }}</td>
                            <td class="c-res {{ $row['pathological'] || $row['critical'] ? 'bold' : '' }} {{ $row['critical'] ? 'critical' : ($row['pathological'] ? 'abnormal' : '') }}">
                                {{ $row['value'] }}@if($row['flag'] === 'HIGH')<span class="flag"> ↑</span>@elseif($row['flag'] === 'LOW')<span class="flag"> ↓</span>@endif
                                @if($row['critical'])<br><span class="flag">Valeur critique</span>@endif
                            </td>
                            <td class="c-ref ref">{{ $row['reference'] }}</td>
                            @if($cols === 4)<td class="c-ant ant">{{ $row['anteriority'] }}</td>@endif
                        </tr>
                        @break
                    @case('antibiogram')
                        {!! $tr('abg-title') !!}<td colspan="{{ $cols }}" class="abg-title" style="{{ $pad($row['depth']) }}">Antibiogramme de <i>{{ $row['bacterium'] }}</i></td></tr>
                        @forelse($row['lines'] as $line)
                            {!! $tr('abg-line', 'abg-line') !!}
                                <td style="{{ $pad($row['depth'] + 1) }}">{{ $line['antibiotic'] }}</td>
                                <td class="{{ $line['resistant'] ? 'bold' : '' }}" @if($line['intermediate']) style="font-style: italic" @endif>{{ $line['label'] }}</td>
                                <td class="ref">{{ $line['measure'] }}</td>
                                @if($cols === 4)<td></td>@endif
                            </tr>
                        @empty
                            {!! $tr('abg-line') !!}<td colspan="{{ $cols }}" class="muted" style="{{ $pad($row['depth'] + 1) }}">Aucun antibiotique testé.</td></tr>
                        @endforelse
                        @if($row['notes'])
                            {!! $tr('note') !!}
                                <td class="note-label" style="{{ $pad($row['depth'] + 1) }}">Notes :</td>
                                <td colspan="{{ $cols - 1 }}" class="note-text">{{ $row['notes'] }}</td>
                            </tr>
                        @endif
                        @break
                    @case('note')
                        {!! $tr('note') !!}
                            <td class="note-label" style="{{ $pad($row['depth']) }}">Notes :</td>
                            <td colspan="{{ $cols - 1 }}" class="note-text">{!! nl2br(e($row['text'])) !!}</td>
                        </tr>
                        @break
                @endswitch
            @endforeach

            @if($item['in_correction'])
                {!! $tr('note') !!}<td colspan="{{ $cols }}" class="critical" style="{{ $pad(1) }}">Repris par le laboratoire pour être refait{{ is_string($item['in_correction']) ? ' ('.$item['in_correction'].')' : '' }} : ne vous fiez pas à cette valeur, un nouvel envoi suivra.</td></tr>
            @endif
            @if($item['provisional'])
                {!! $tr('note') !!}<td colspan="{{ $cols }}" class="muted" style="{{ $pad(1) }}">Résultat provisoire : pas encore envoyé au médecin.</td></tr>
            @endif
        @endforeach
        </tbody>
    </table>
@empty
    <p class="muted">Aucun résultat à imprimer pour cette demande.</p>
@endforelse

@if(filled($report['conclusion']))
    <div class="general" id="pdf-conclusion">
        <div class="title accent">Conclusion générale</div>
        {!! nl2br(e($report['conclusion'])) !!}
    </div>
@endif

<div class="closing" id="pdf-closing">
    @if($design['show_closing_identity'])
        <div class="identity">{{ $patient['name'] }} — Dossier n° {{ $patient['number'] }}@if($req['lab_number']) — {{ $req['lab_number'] }}@endif</div>
    @endif
    @php
        $meta = [];
        if ($design['show_sent'] && ! empty($report['validation']['by'])) {
            $meta[] = ['text' => 'Résultats envoyés au médecin par '.implode(', ', $report['validation']['by']).($report['validation']['at'] ? ' le '.$report['validation']['at'] : '').'.', 'strong' => false];
        }
        if ($design['show_approval'] && ! empty($report['approval']['by'])) {
            $meta[] = ['text' => 'Résultats validés par '.implode(', ', $report['approval']['by']).($report['approval']['at'] ? ' le '.$report['approval']['at'] : '').'.', 'strong' => true];
        }
        if ($design['show_approval'] && ! empty($report['approval']['awaiting'])) {
            $meta[] = ['text' => $report['approval']['awaiting'].' résultat(s) en attente de validation par le médecin.', 'strong' => false];
        }
        if ($design['show_generated']) {
            $meta[] = ['text' => 'Édité le '.$report['generated_at'].'.', 'strong' => false];
        }
    @endphp
    <table class="sign">
        @if(count($signatories) > 1)
            @if($meta !== [])
                <tr>
                    <td class="muted" colspan="{{ count($signatories) }}">
                        @foreach($meta as $line)@if($line['strong'])<span class="strong">{{ $line['text'] }}</span>@else{{ $line['text'] }}@endif @unless($loop->last)<br>@endunless @endforeach
                    </td>
                </tr>
            @endif
            <tr>
                @foreach($signatories as $signatory)
                    <td class="signature half" style="text-align: {{ $loop->first ? 'left' : 'right' }}">
                        <span class="strong">{{ $signatory['label'] }}</span>
                        @if($signatory['name'])<br>{{ $signatory['name'] }}@endif
                        <div class="box"></div>
                    </td>
                @endforeach
            </tr>
        @else
            <tr>
                <td class="muted meta">
                    @foreach($meta as $line)@if($line['strong'])<span class="strong">{{ $line['text'] }}</span>@else{{ $line['text'] }}@endif @unless($loop->last)<br>@endunless @endforeach
                </td>
                <td class="signature" style="text-align: right">
                    <span class="strong">{{ $signatories[0]['label'] }}</span>
                    @if($signatories[0]['name'])<br>{{ $signatories[0]['name'] }}@endif
                    <div class="box"></div>
                </td>
            </tr>
        @endif
    </table>
</div>
</body>
</html>

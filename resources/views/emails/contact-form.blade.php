@component('mail::message')
# Neue Catering-Station Anfrage

Eine neue Anfrage für eine kostenlose Catering-Station ist eingegangen.

## Unternehmensdaten

@component('mail::panel')
**Unternehmen:** {{ $companyName }}  
**Mitarbeiteranzahl:** {{ $employeeCount }}  
**Eingereicht am:** {{ $submissionTime }}
@endcomponent

## Kontaktdaten

@component('mail::table')
| Feld | Wert |
|:-----|:-----|
| **Name** | {{ $contactName }} |
| **E-Mail** | {{ $contactEmail }} |
| **Telefon** | {{ $contactPhone }} |
| **Catering-Station** | {{ $cateringStation }} |
@endcomponent

## Nächste Schritte

@component('mail::button', ['url' => config('app.admin_url') . '/contacts'])
Anfrage bearbeiten
@endcomponent

Bitte kontaktieren Sie {{ $contactName }} zeitnah unter {{ $contactEmail }} um die Catering-Station Installation zu besprechen.

@if($employeeCount >= 50)
@component('mail::promotion')
✅ **Qualifiziert für kostenlose Station** (≥50 Mitarbeiter)
@endcomponent
@else
@component('mail::promotion')
⚠️ **Mitarbeiterzahl unter 50** - Bitte prüfen Sie die Berechtigung
@endcomponent
@endif

---

Vielen Dank,  
{{ config('app.name') }} Team

@component('mail::subcopy')
Diese E-Mail wurde automatisch durch das Kontaktformular auf {{ config('app.url') }} generiert.
@endcomponent

@endcomponent
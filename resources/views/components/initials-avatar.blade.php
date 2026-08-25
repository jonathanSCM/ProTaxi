{{--
    Avatar con foto real si existe en disco, o un círculo con la inicial del
    nombre si no hay foto o el archivo se perdió (no confía solo en que la
    BD tenga la ruta guardada — ver FileUploadService::exists()).

    Uso: <x-initials-avatar :name="$user->name" :photo="$user->profile_photo"
             class="rounded-circle" style="width:48px;height:48px;" />
--}}
@props(['name' => '', 'photo' => null])

@php
    $exists = $photo && \App\Services\FileUploadService::exists($photo);
    $initial = $name ? mb_strtoupper(mb_substr(trim($name), 0, 1)) : '?';

    // Paleta de marca ProTaxi — color determinístico según el nombre, para
    // que el mismo usuario siempre caiga en el mismo color.
    $palette = [
        ['bg' => '#6D1B96', 'fg' => '#ffffff'],
        ['bg' => '#18EEA4', 'fg' => '#0E2019'],
        ['bg' => '#470E6F', 'fg' => '#ffffff'],
        ['bg' => '#9C4DC9', 'fg' => '#ffffff'],
        ['bg' => '#059669', 'fg' => '#ffffff'],
    ];
    $colors = $palette[$name ? (crc32($name) % count($palette)) : 0];
@endphp

@if($exists)
    <img src="{{ \App\Services\FileUploadService::getUrl($photo) }}"
         alt="{{ $name }}"
         {{ $attributes }}>
@else
    <div {{ $attributes->merge([
            'style' => "background:{$colors['bg']};color:{$colors['fg']};"
                     . "display:flex;align-items:center;justify-content:center;"
                     . "font-weight:700;line-height:1;",
        ]) }}
        title="{{ $name }}">
        {{ $initial }}
    </div>
@endif

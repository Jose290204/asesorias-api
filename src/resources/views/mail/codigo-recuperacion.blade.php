<x-mail::message>
# Hola, {{ $nombre }}

Recibimos una solicitud para recuperar tu contraseña en Asesorías FIC.
Tu código de verificación es:

<x-mail::panel>
## {{ $codigo }}
</x-mail::panel>

El código vence en {{ $minutos }} minutos. Si tú no lo pediste, ignora este correo:
tu contraseña no cambiará.

Facultad de Informática Culiacán
</x-mail::message>
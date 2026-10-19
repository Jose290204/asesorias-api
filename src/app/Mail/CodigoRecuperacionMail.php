<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// ShouldQueue: el correo se guarda como trabajo en "jobs" y lo manda el contenedor "queue",
// así la respuesta del endpoint no espera al servidor SMTP.
class CodigoRecuperacionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // Propiedades públicas: quedan disponibles en la vista con el mismo nombre
    public function __construct(
        public string $nombre,
        public string $codigo,
        public int $minutos = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código para recuperar tu contraseña');
    }

    public function content(): Content
    {
        // Busca resources/views/mail/codigo-recuperacion.blade.php
        return new Content(markdown: 'mail.codigo-recuperacion');
    }
}
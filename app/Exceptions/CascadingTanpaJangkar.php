<?php

namespace App\Exceptions;

/**
 * Baris cascading hendak ditulis tanpa jangkar Eselon II sama sekali.
 *
 * Kelasnya sendiri, bukan RuntimeException biasa, supaya penangkapnya di
 * CascadingController::_remap() bisa sempit: hanya kegagalan INI yang berubah
 * menjadi pesan di layar. Galat lain tetap naik apa adanya, karena galat yang
 * tertelan diam-diam justru masalah yang sedang kita berantas.
 */
class CascadingTanpaJangkar extends \RuntimeException
{
}

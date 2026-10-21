<?php

namespace App\Http\Middleware;

/**
 * Middleware EnsureAdmin (mantiene compatibilidad y hereda la lógica estricta de EnsureUserIsAdmin).
 */
class EnsureAdmin extends EnsureUserIsAdmin
{
}

<?php

namespace App\Services\Llm;

use RuntimeException;

/** El modelo respondió sin texto por una falla suya (p. ej. MALFORMED_RESPONSE): vale la pena reintentar. */
class RespuestaVaciaLlm extends RuntimeException {}

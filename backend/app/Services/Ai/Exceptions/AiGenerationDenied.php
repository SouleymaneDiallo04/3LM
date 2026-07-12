<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** Génération IA refusée (RGPD : fiche non-diffusible ou exclue). */
class AiGenerationDenied extends RuntimeException {}

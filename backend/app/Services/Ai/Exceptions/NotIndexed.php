<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** La fiche n'a pas encore d'embedding : la similarité est indisponible. */
class NotIndexed extends RuntimeException {}
